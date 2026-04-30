<?php

declare(strict_types=1);

namespace App\Http\Controllers;

use Illuminate\Http\JsonResponse;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;
use Illuminate\View\View;
use Kraite\Core\Models\Kraite;
use Kraite\Core\Models\Payment;
use Kraite\Core\Models\Subscription;
use Kraite\Core\Models\TopUpCoin;
use Kraite\Core\Models\User as KraiteUser;
use Kraite\Core\Models\WalletTransaction;
use Kraite\Core\Support\Billing\InsufficientFundsException;
use Kraite\Core\Support\Billing\NowPaymentsClient;
use Kraite\Core\Support\Billing\Wallet;
use Throwable;

/**
 * User-facing billing area.
 *
 * Renewal-anchored self-service surface. Top-ups go through the
 * NOWPayments gateway — the user is redirected to a hosted invoice
 * page for payment, and the IPN webhook credits the wallet.
 */
final class BillingController extends Controller
{
    public function __construct(private readonly Wallet $wallet) {}

    public function index(Request $request): View
    {
        $user = $this->kraiteUser($request);
        $tier = $user->subscription;

        $transactions = WalletTransaction::where('user_id', $user->id)
            ->orderByDesc('created_at')
            ->limit(50)
            ->get();

        return view('billing', [
            'user' => $user,
            'tier' => $tier,
            'subscriptions' => Subscription::where('is_active', true)->orderBy('id')->get(),
            'transactions' => $transactions,
            'trialActive' => $user->isTrialActive(),
            'isPaused' => $user->isPaused(),
            'inClosingMode' => $user->isInClosingMode(),
            'rateCovered' => $user->subscriptionCoversNextRenewal(),
            'shortfall' => $user->renewalShortfallUsdt(),
            'monthlyRate' => (float) ($tier?->monthly_rate_usdt ?? 0),
            'renewsAt' => $user->subscription_renews_at,
            'accounts' => $user->accounts()->orderBy('id')->get(),
            'topUpCoins' => TopUpCoin::active(),
        ]);
    }

    public function startTrading(Request $request): RedirectResponse
    {
        $user = $this->kraiteUser($request);

        if ($user->subscription_id === null) {
            return redirect()
                ->route('billing')
                ->with('error', 'Pick a plan before starting your trial.');
        }

        if ($user->trial_started_at === null) {
            $user->trial_started_at = now();

            $trialDays = $user->effectiveTrialDays();

            if ($trialDays > 0) {
                $user->subscription_renews_at = now()->copy()->addDays($trialDays);
            }

            $user->save();
        }

        return redirect()
            ->route('billing')
            ->with('status', 'Trial started. Enjoy your free days!');
    }

    public function changeSubscription(Request $request): RedirectResponse
    {
        $data = $request->validate([
            'subscription_id' => 'required|exists:subscriptions,id',
            'active_account_id' => 'nullable|integer',
        ]);

        $user = $this->kraiteUser($request);

        if ($user->isPaused()) {
            return redirect()
                ->route('billing')
                ->with('error', 'Resume your subscription before changing plan.');
        }

        $newTier = Subscription::findOrFail((int) $data['subscription_id']);

        if ($newTier->id === $user->subscription_id) {
            return redirect()
                ->route('billing')
                ->with('status', 'Already on this plan.');
        }

        // First-time plan pick: user has no tier and hasn't started
        // their trial yet. Free assignment, no debit, no anchor.
        if ($user->subscription_id === null && $user->trial_started_at === null) {
            $user->subscription_id = $newTier->id;
            $this->maybeAssignActiveAccount($user, $newTier, $data['active_account_id'] ?? null);
            $user->save();

            return redirect()
                ->route('billing')
                ->with('status', 'Plan selected. Click "Start trading" when ready.');
        }

        // During trial, flip the tier without prorate or debit.
        if ($user->isTrialActive()) {
            $user->subscription_id = $newTier->id;
            $this->maybeAssignActiveAccount($user, $newTier, $data['active_account_id'] ?? null);
            $user->save();

            return redirect()
                ->route('billing')
                ->with('status', 'Plan updated.');
        }

        $isDowngradeToCapped = ! $newTier->hasUnlimitedAccounts()
            && (int) ($newTier->max_accounts ?? 0) === 1;

        if (
            $isDowngradeToCapped
            && empty($data['active_account_id'])
            && $user->accounts()->count() > 1
        ) {
            return redirect()
                ->route('billing')
                ->with('error', 'Pick which account stays active under the new plan.');
        }

        try {
            DB::transaction(function () use ($user, $newTier, $data) {
                $currentTier = $user->subscription;
                $now = now();

                if (
                    $currentTier !== null
                    && $user->subscription_renews_at !== null
                    && $user->subscription_renews_at->isFuture()
                ) {
                    $daysRemaining = (int) ceil($now->diffInDays($user->subscription_renews_at, absolute: false));
                    $currentMonthly = (float) $currentTier->monthly_rate_usdt;
                    $refund = $daysRemaining > 0 ? round($daysRemaining * ($currentMonthly / 30), 4) : 0.0;

                    if ($refund > 0) {
                        $this->wallet->credit(
                            user: $user,
                            amount: $refund,
                            type: WalletTransaction::TYPE_CREDIT_PRORATE_REFUND,
                            description: sprintf(
                                'Prorate refund · %s · %d unused days',
                                $currentTier->name,
                                $daysRemaining,
                            ),
                            meta: [
                                'subscription_id' => $currentTier->id,
                                'days_remaining' => $daysRemaining,
                                'monthly_rate_usdt' => $currentMonthly,
                            ],
                        );
                    }
                }

                $user->subscription_id = $newTier->id;
                $this->maybeAssignActiveAccount($user, $newTier, $data['active_account_id'] ?? null);
                $user->save();
                $user->load('subscription');

                $this->wallet->runRenewal(
                    user: $user,
                    newRenewsAt: now()->addMonth()->subDay(),
                );
            });
        } catch (InsufficientFundsException) {
            return redirect()
                ->route('billing')
                ->with('error', 'Wallet does not cover the new plan after prorate. Top up first.');
        }

        return redirect()
            ->route('billing')
            ->with('status', 'Plan updated.');
    }

    public function minAmount(Request $request): JsonResponse
    {
        $coinCanonical = (string) $request->query('coin', '');

        $coin = TopUpCoin::query()
            ->where('canonical', $coinCanonical)
            ->where('is_active', true)
            ->first();

        if ($coin === null) {
            return response()->json([
                'error' => 'Unknown or inactive coin.',
            ], 422);
        }

        $user = $this->kraiteUser($request);
        $tier = $user->subscription;

        $monthlyRate = (float) ($tier?->monthly_rate_usdt ?? 0);
        $wallet = (float) $user->wallet_balance_usdt;
        $coveredFloor = (float) (Kraite::find(1)?->top_up_minimum_when_covered_usdt ?? 20);

        $ruleMin = $wallet >= $monthlyRate
            ? $coveredFloor
            : max(0.0, $monthlyRate - $wallet);

        $gatewayMinUsdt = $this->gatewayMinAmountUsdt($coin->canonical, $coin->min_amount_override);

        $effectiveMin = (float) max($ruleMin, $gatewayMinUsdt);

        return response()->json([
            'coin' => [
                'canonical' => $coin->canonical,
                'display_name' => $coin->display_name,
            ],
            'wallet_balance_usdt' => $wallet,
            'monthly_rate_usdt' => $monthlyRate,
            'rule_min_usdt' => round($ruleMin, 4),
            'gateway_min_usdt' => round($gatewayMinUsdt, 4),
            'effective_min_usdt' => round($effectiveMin, 4),
            'button_label' => sprintf('Top up %s USDT', number_format($effectiveMin, 2)),
        ]);
    }

    public function topUp(Request $request): RedirectResponse
    {
        $data = $request->validate([
            'amount_usdt' => 'required|numeric|min:0.01',
            'pay_currency' => 'required|string',
        ]);

        $user = $this->kraiteUser($request);

        $coin = TopUpCoin::query()
            ->where('canonical', $data['pay_currency'])
            ->where('is_active', true)
            ->first();

        if ($coin === null) {
            return redirect()
                ->route('billing')
                ->with('error', 'The selected coin is not available. Pick another option.');
        }

        $tier = $user->subscription;
        $monthlyRate = (float) ($tier?->monthly_rate_usdt ?? 0);
        $wallet = (float) $user->wallet_balance_usdt;
        $coveredFloor = (float) (Kraite::find(1)?->top_up_minimum_when_covered_usdt ?? 20);

        $ruleMin = $wallet >= $monthlyRate
            ? $coveredFloor
            : max(0.0, $monthlyRate - $wallet);

        $gatewayMin = $this->gatewayMinAmountUsdt($coin->canonical, $coin->min_amount_override);
        $effectiveMin = max($ruleMin, $gatewayMin);

        $amount = (float) $data['amount_usdt'];

        if ($amount + 0.0001 < $effectiveMin) {
            return redirect()
                ->route('billing')
                ->with('error', sprintf(
                    'Minimum top-up for %s is %s USDT.',
                    $coin->display_name,
                    number_format($effectiveMin, 2),
                ));
        }

        $payment = Payment::create([
            'user_id' => $user->id,
            'order_id' => 'pending-'.bin2hex(random_bytes(8)),
            'price_amount' => $amount,
            'pay_currency' => $coin->canonical,
            'outcome_currency' => 'usdttrc20',
            'status' => Payment::STATUS_PENDING,
        ]);

        $emailSlug = trim(
            (string) preg_replace('/[^a-z0-9]+/', '-', strtolower((string) $user->email)),
            '-',
        ) ?: "user-{$user->id}";
        $orderId = "order-{$emailSlug}-{$payment->id}";
        $payment->order_id = $orderId;
        $payment->save();

        $callbackUrl = (string) config('services.nowpayments.ipn_callback_url')
            ?: route('webhooks.payments');
        $successUrl = (string) config('services.nowpayments.success_url') ?: route('billing');
        $cancelUrl = (string) config('services.nowpayments.cancel_url') ?: route('billing');

        try {
            $invoice = NowPaymentsClient::fromConfig()->createInvoice(
                priceAmount: $amount,
                orderId: $orderId,
                ipnCallbackUrl: $callbackUrl,
                successUrl: $successUrl,
                cancelUrl: $cancelUrl,
                priceCurrency: 'usdttrc20',
                orderDescription: sprintf('Kraite wallet top-up · %s', $user->email),
                customerEmail: $user->email,
                payCurrency: $coin->canonical,
            );
        } catch (Throwable $e) {
            Log::error('[NOWPayments] invoice creation failed', [
                'user_id' => $user->id,
                'amount' => $amount,
                'error' => $e->getMessage(),
            ]);

            $payment->status = Payment::STATUS_FAILED;
            $payment->save();

            return redirect()
                ->route('billing')
                ->with('error', 'Could not create payment invoice. Please try again or contact admin.');
        }

        $payment->invoice_url = $invoice['invoice_url'];
        $payment->save();

        return redirect()->away($invoice['invoice_url']);
    }

    public function pause(Request $request): RedirectResponse
    {
        $user = $this->kraiteUser($request);
        $user->pause();

        return redirect()
            ->route('billing')
            ->with('status', 'Subscription paused. New positions blocked; existing trades unaffected.');
    }

    public function resume(Request $request): RedirectResponse
    {
        $user = $this->kraiteUser($request);
        $user->resume();

        return redirect()
            ->route('billing')
            ->with('status', 'Subscription resumed. Renewal anchor pushed forward by the pause duration.');
    }

    private function kraiteUser(Request $request): KraiteUser
    {
        return KraiteUser::with('subscription')->findOrFail($request->user()->id);
    }

    /**
     * Resolve the gateway floor (or override) for a coin in
     * USDT-equivalent terms. NOWPayments returns the floor in coin
     * units; we ask /estimate to convert to USDT for like-for-like
     * comparison against the wallet's USDT balance. Cached 5 min so
     * AJAX dropdown changes don't hammer the gateway.
     */
    private function gatewayMinAmountUsdt(string $canonical, ?string $override): float
    {
        $apiKey = (string) config('services.nowpayments.api_key', '');
        $baseUrl = (string) config('services.nowpayments.base_url', '');

        if ($apiKey === '' || $baseUrl === '') {
            return 0.0;
        }

        return (float) Cache::remember(
            "nowpayments.min_amount_usdt.{$canonical}",
            now()->addMinutes(5),
            function () use ($canonical, $override, $apiKey, $baseUrl) {
                try {
                    $minInCoin = $override !== null
                        ? (float) $override
                        : (float) (Http::withHeaders(['x-api-key' => $apiKey])
                            ->timeout(8)
                            ->get(rtrim($baseUrl, '/').'/min-amount', [
                                'currency_from' => $canonical,
                                'currency_to' => 'usdttrc20',
                            ])
                            ->json('min_amount') ?? 0);

                    if ($minInCoin <= 0) {
                        return 0.0;
                    }

                    if ($canonical === 'usdttrc20') {
                        return $minInCoin;
                    }

                    $estimate = Http::withHeaders(['x-api-key' => $apiKey])
                        ->timeout(8)
                        ->get(rtrim($baseUrl, '/').'/estimate', [
                            'amount' => $minInCoin,
                            'currency_from' => $canonical,
                            'currency_to' => 'usdttrc20',
                        ])
                        ->json('estimated_amount');

                    return (float) ($estimate ?? 0);
                } catch (Throwable) {
                    return 0.0;
                }
            },
        );
    }

    private function maybeAssignActiveAccount(KraiteUser $user, Subscription $newTier, mixed $activeAccountId): void
    {
        if ($newTier->hasUnlimitedAccounts()) {
            $user->active_account_id = null;

            return;
        }

        if ((int) ($newTier->max_accounts ?? 0) !== 1) {
            return;
        }

        if ($activeAccountId !== null) {
            $user->active_account_id = (int) $activeAccountId;

            return;
        }

        // Capped at 1 account, no explicit pick — auto-assign when
        // the user has exactly one account; nothing to do otherwise.
        $accounts = $user->accounts()->pluck('id');

        if ($accounts->count() === 1) {
            $user->active_account_id = (int) $accounts->first();
        }
    }
}
