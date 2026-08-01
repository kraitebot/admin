<?php

declare(strict_types=1);

namespace App\Http\Controllers;

use Illuminate\Http\JsonResponse;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Str;
use Illuminate\Validation\Rule;
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
        $subscriptions = Subscription::publiclyAvailable()->orderBy('id')->get();
        $isComplimentaryPlan = $tier !== null && (float) $tier->monthly_rate_usdt <= 0;
        $trialActive = ! $isComplimentaryPlan && $user->isTrialActive();
        $trialEndsAt = $trialActive
            ? $user->trial_started_at?->copy()->addDays($user->effectiveTrialDays())
            : null;

        $transactions = WalletTransaction::where('user_id', $user->id)
            ->orderByDesc('created_at')
            ->limit(50)
            ->get();

        return view('billing', [
            'user' => $user,
            'tier' => $tier,
            'subscriptions' => $subscriptions,
            'transactions' => $transactions,
            'trialActive' => $trialActive,
            'isComplimentaryPlan' => $isComplimentaryPlan,
            'inClosingMode' => $user->isInClosingMode(),
            'rateCovered' => $user->subscriptionCoversNextRenewal(),
            'shortfall' => $user->renewalShortfallUsdt(),
            'monthlyRate' => (float) ($tier?->monthly_rate_usdt ?? 0),
            'renewsAt' => $isComplimentaryPlan ? null : $user->subscription_renews_at,
            'trialEndsAt' => $trialEndsAt,
            'topUpMinimum' => $this->ruleMinimum($user),
            'accounts' => $user->accounts()->with('apiSystem')->orderBy('id')->get(),
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

        $user->loadMissing('subscription');

        if ((float) ($user->subscription?->monthly_rate_usdt ?? 0) <= 0) {
            return redirect()
                ->route('billing')
                ->with('status', "{$user->subscription->name} is free forever. No trial or renewal is needed.");
        }

        if ($user->trial_started_at === null) {
            $user->startTrial();
        } elseif ($user->subscription_renews_at === null) {
            $user->syncTrialRenewalAnchor();
        }

        return redirect()
            ->route('billing')
            ->with('status', 'Trial started. Enjoy your free days!');
    }

    public function changeSubscription(Request $request): RedirectResponse
    {
        $user = $this->kraiteUser($request);

        $data = $request->validate([
            'subscription_id' => [
                'required',
                'integer',
                Rule::exists('subscriptions', 'id')
                    ->where(static fn ($query) => $query
                        ->where('is_active', true)
                        ->where('canonical', '!=', Subscription::CANONICAL_BLACK)),
            ],
            'active_account_id' => [
                'nullable',
                'integer',
                Rule::exists('accounts', 'id')->where('user_id', $user->id),
            ],
        ]);

        $newTier = Subscription::findOrFail((int) $data['subscription_id']);

        if ($newTier->id === $user->subscription_id) {
            return redirect()
                ->route('billing')
                ->with('status', 'Already on this plan.');
        }

        $requiresActiveAccount = ! $newTier->hasUnlimitedAccounts()
            && $newTier->max_accounts === 1
            && $user->accounts()->count() > 1;

        if ($requiresActiveAccount && empty($data['active_account_id'])) {
            return redirect()
                ->route('billing')
                ->with('error', 'Pick which account stays active under the new plan.');
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
        if (
            (float) ($user->subscription?->monthly_rate_usdt ?? 0) > 0
            && $user->isTrialActive()
        ) {
            $user->subscription_id = $newTier->id;
            $this->maybeAssignActiveAccount($user, $newTier, $data['active_account_id'] ?? null);
            $user->save();
            $user->syncTrialRenewalAnchor();

            return redirect()
                ->route('billing')
                ->with('status', 'Plan updated.');
        }

        try {
            $changed = DB::transaction(function () use ($user, $newTier, $data): bool {
                $lockedUser = KraiteUser::query()->lockForUpdate()->findOrFail($user->id);
                $lockedUser->load('subscription');

                if ($lockedUser->subscription_id === $newTier->id) {
                    return false;
                }

                $currentTier = $lockedUser->subscription;
                $now = now();

                if (
                    $currentTier !== null
                    && $lockedUser->subscription_renews_at !== null
                    && $lockedUser->subscription_renews_at->isFuture()
                ) {
                    $daysRemaining = (int) ceil($now->diffInDays($lockedUser->subscription_renews_at, absolute: false));
                    $currentMonthly = (float) $currentTier->monthly_rate_usdt;
                    $refund = $daysRemaining > 0 ? round($daysRemaining * ($currentMonthly / 30), 4) : 0.0;

                    if ($refund > 0) {
                        $this->wallet->credit(
                            user: $lockedUser,
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

                $lockedUser->subscription_id = $newTier->id;
                $this->maybeAssignActiveAccount($lockedUser, $newTier, $data['active_account_id'] ?? null);

                if ((float) $newTier->monthly_rate_usdt <= 0) {
                    $lockedUser->subscription_renews_at = null;
                    $lockedUser->save();

                    return true;
                }

                $lockedUser->save();
                $lockedUser->load('subscription');

                $this->wallet->runRenewal(
                    user: $lockedUser,
                    newRenewsAt: now()->addMonth()->subDay(),
                );

                return true;
            });
        } catch (InsufficientFundsException) {
            return redirect()
                ->route('billing')
                ->with('error', 'Wallet does not cover the new plan after prorate. Top up first.');
        }

        if (! $changed) {
            return redirect()
                ->route('billing')
                ->with('status', 'Already on this plan.');
        }

        return redirect()
            ->route('billing')
            ->with('status', 'Plan updated.');
    }

    public function walletStatus(Request $request): JsonResponse
    {
        $user = $this->kraiteUser($request);

        return response()->json([
            'wallet_balance_usdt' => (float) $user->wallet_balance_usdt,
            'last_credit_at' => optional(
                Payment::where('user_id', $user->id)
                    ->whereNotNull('credited_at')
                    ->orderByDesc('credited_at')
                    ->first()
            )?->credited_at?->toIso8601String(),
        ]);
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
        $enforceCoverage = (bool) config('kraite.billing.enforce_minimum_for_renewal', true);
        $ruleMin = $this->ruleMinimum($user);

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
            'enforce_coverage' => $enforceCoverage,
            'button_label' => sprintf('Top up %s USDT', number_format($effectiveMin, 2)),
        ]);
    }

    public function topUp(Request $request): RedirectResponse
    {
        $data = $request->validate([
            'amount_usdt' => ['required', 'numeric', 'min:0.01'],
            'pay_currency' => ['nullable', 'string', 'max:64'],
        ]);

        $user = $this->kraiteUser($request);

        $coin = isset($data['pay_currency'])
            ? TopUpCoin::query()
                ->where('canonical', $data['pay_currency'])
                ->where('is_active', true)
                ->first()
            : null;

        if (isset($data['pay_currency']) && $coin === null) {
            return redirect()
                ->route('billing')
                ->with('error', 'The selected coin is not available. Pick another option.');
        }

        $ruleMin = $this->ruleMinimum($user);
        $gatewayMin = $coin !== null
            ? $this->gatewayMinAmountUsdt($coin->canonical, $coin->min_amount_override)
            : 0.0;
        $effectiveMin = max($ruleMin, $gatewayMin);

        $amount = (float) $data['amount_usdt'];

        if ($amount + 0.0001 < $effectiveMin) {
            return redirect()
                ->route('billing')
                ->with('error', sprintf(
                    'Minimum top-up for %s is %s USDT.',
                    $coin?->display_name ?? 'this payment',
                    number_format($effectiveMin, 2),
                ));
        }

        return $this->createInvoiceAndRedirect($user, $coin, $amount);
    }

    public function quickTopUp(Request $request): RedirectResponse
    {
        $data = $request->validate([
            'user' => 'required|integer|exists:users,id',
            'amount' => 'required|numeric|min:0.01',
            'coin' => 'required|string',
        ]);

        $user = KraiteUser::with('subscription')->findOrFail((int) $data['user']);

        if ($request->user()?->is_admin || $user->is_admin) {
            abort(403, 'Trader access required.');
        }

        if (! $user->is_active) {
            abort(403, 'User is not active.');
        }

        $coin = TopUpCoin::query()
            ->where('canonical', $data['coin'])
            ->where('is_active', true)
            ->first();

        if ($coin === null) {
            return redirect()
                ->route('billing')
                ->with('error', 'The coin from your last payment is no longer available. Pick another option below.');
        }

        return $this->createInvoiceAndRedirect($user, $coin, (float) $data['amount']);
    }

    private function createInvoiceAndRedirect(KraiteUser $user, ?TopUpCoin $coin, float $amount): RedirectResponse
    {
        $payment = Payment::create([
            'user_id' => $user->id,
            'order_id' => 'pending-'.bin2hex(random_bytes(8)),
            'price_amount' => $amount,
            'pay_currency' => $coin?->canonical,
            'outcome_currency' => 'usdttrc20',
            'status' => Payment::STATUS_PENDING,
        ]);

        $emailSlug = Str::slug((string) $user->email) ?: "user-{$user->id}";
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
                payCurrency: $coin?->canonical,
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
        return (float) Cache::remember(
            'nowpayments.min_amount_usdt.'.sha1($canonical.'|'.($override ?? 'gateway')),
            now()->addMinutes(5),
            function () use ($canonical, $override) {
                try {
                    $client = NowPaymentsClient::fromConfig();
                    $minInCoin = $override !== null
                        ? (float) $override
                        : $client->minimumAmount($canonical);

                    if ($minInCoin <= 0) {
                        return 0.0;
                    }

                    if ($canonical === 'usdttrc20') {
                        return $minInCoin;
                    }

                    return $client->estimate($minInCoin, $canonical);
                } catch (Throwable) {
                    return 0.0;
                }
            },
        );
    }

    private function ruleMinimum(KraiteUser $user): float
    {
        if (! config('kraite.billing.enforce_minimum_for_renewal', true)) {
            return 0.0;
        }

        $monthlyRate = (float) ($user->subscription?->monthly_rate_usdt ?? 0);
        $wallet = (float) $user->wallet_balance_usdt;

        if ($wallet < $monthlyRate) {
            return max(0.0, $monthlyRate - $wallet);
        }

        return (float) (Kraite::find(1)?->top_up_minimum_when_covered_usdt ?? 20);
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
