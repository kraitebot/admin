<?php

declare(strict_types=1);

namespace App\Http\Controllers;

use Carbon\CarbonInterface;
use Illuminate\Database\Eloquent\Collection as EloquentCollection;
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

        $transactions = $this->billingTransactions($user);

        return view('billing', $this->billingViewData(
            $user,
            $tier,
            $subscriptions,
            $transactions,
            $trialActive,
            $isComplimentaryPlan,
            $trialEndsAt,
        ));
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

        $data = $this->validateSubscriptionChange($request, $user);
        $newTier = Subscription::findOrFail($data['subscription_id']);

        return $this->resolveSubscriptionChange($user, $newTier, $data['active_account_id']);
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
        $coin = $this->activeTopUpCoin((string) $request->query('coin', ''));

        if ($coin === null) {
            return response()->json([
                'error' => 'Unknown or inactive coin.',
            ], 422);
        }

        return response()->json($this->minimumAmountPayload($this->kraiteUser($request), $coin));
    }

    public function topUp(Request $request): RedirectResponse
    {
        $data = $request->validate([
            'amount_usdt' => ['required', 'numeric', 'min:0.01'],
            'pay_currency' => ['nullable', 'string', 'max:64'],
        ]);

        $user = $this->kraiteUser($request);
        $payCurrency = isset($data['pay_currency']) ? (string) $data['pay_currency'] : null;
        $coin = $this->activeTopUpCoin($payCurrency);

        if ($payCurrency !== null && $coin === null) {
            return $this->billingRedirect('error', 'The selected coin is not available. Pick another option.');
        }

        $amount = (float) $data['amount_usdt'];

        $minimumError = $this->topUpMinimumError($user, $coin, $amount);
        if ($minimumError !== null) {
            return $minimumError;
        }

        return $this->createInvoiceAndRedirect($user, $coin, $amount);
    }

    public function quickTopUp(Request $request): RedirectResponse
    {
        $data = $this->validateQuickTopUp($request);

        $user = KraiteUser::with('subscription')->findOrFail((int) $data['user']);
        $this->abortIfQuickTopUpForbidden($request, $user);
        $coin = $this->activeTopUpCoin((string) $data['coin']);

        if ($coin === null) {
            return $this->billingRedirect('error', 'The coin from your last payment is no longer available. Pick another option below.');
        }

        return $this->createInvoiceAndRedirect($user, $coin, (float) $data['amount']);
    }

    /** @return array<string, mixed> */
    private function validateQuickTopUp(Request $request): array
    {
        return $request->validate([
            'user' => 'required|integer|exists:users,id',
            'amount' => 'required|numeric|min:0.01',
            'coin' => 'required|string',
        ]);
    }

    private function abortIfQuickTopUpForbidden(Request $request, KraiteUser $user): void
    {
        if ($request->user()?->is_admin || $user->is_admin) {
            abort(403, 'Trader access required.');
        }

        if (! $user->is_active) {
            abort(403, 'User is not active.');
        }
    }

    /** @return array<string, mixed> */
    private function minimumAmountValues(KraiteUser $user, TopUpCoin $coin): array
    {
        $monthlyRate = (float) ($user->subscription?->monthly_rate_usdt ?? 0);
        $wallet = (float) $user->wallet_balance_usdt;
        $enforceCoverage = (bool) config('kraite.billing.enforce_minimum_for_renewal', true);
        $ruleMin = $this->ruleMinimum($user);
        $gatewayMinUsdt = $this->gatewayMinAmountUsdt($coin->canonical, $coin->min_amount_override);

        return [
            'monthly_rate' => $monthlyRate,
            'wallet' => $wallet,
            'enforce_coverage' => $enforceCoverage,
            'rule_min' => $ruleMin,
            'gateway_min' => $gatewayMinUsdt,
            'effective_min' => (float) max($ruleMin, $gatewayMinUsdt),
        ];
    }

    /** @return array<string, mixed> */
    private function minimumAmountPayload(KraiteUser $user, TopUpCoin $coin): array
    {
        $values = $this->minimumAmountValues($user, $coin);

        return [
            'coin' => [
                'canonical' => $coin->canonical,
                'display_name' => $coin->display_name,
            ],
            'wallet_balance_usdt' => $values['wallet'],
            'monthly_rate_usdt' => $values['monthly_rate'],
            'rule_min_usdt' => round($values['rule_min'], 4),
            'gateway_min_usdt' => round($values['gateway_min'], 4),
            'effective_min_usdt' => round($values['effective_min'], 4),
            'enforce_coverage' => $values['enforce_coverage'],
            'button_label' => sprintf('Top up %s USDT', number_format($values['effective_min'], 2)),
        ];
    }

    /**
     * @return array{subscription_id: int, active_account_id: int|null}
     */
    private function validateSubscriptionChange(Request $request, KraiteUser $user): array
    {
        $data = $request->validate($this->subscriptionChangeRules($user));

        return [
            'subscription_id' => (int) $data['subscription_id'],
            'active_account_id' => isset($data['active_account_id'])
                ? (int) $data['active_account_id']
                : null,
        ];
    }

    /**
     * @param  EloquentCollection<int, Subscription>  $subscriptions
     * @param  EloquentCollection<int, WalletTransaction>  $transactions
     * @return array<string, mixed>
     */
    private function billingViewData(
        KraiteUser $user,
        ?Subscription $tier,
        EloquentCollection $subscriptions,
        EloquentCollection $transactions,
        bool $trialActive,
        bool $isComplimentaryPlan,
        ?CarbonInterface $trialEndsAt,
    ): array {
        return [
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
        ];
    }

    /** @return EloquentCollection<int, WalletTransaction> */
    private function billingTransactions(KraiteUser $user): EloquentCollection
    {
        return WalletTransaction::where('user_id', $user->id)
            ->orderByDesc('created_at')
            ->limit(50)
            ->get();
    }

    /** @return array<string, mixed> */
    private function subscriptionChangeRules(KraiteUser $user): array
    {
        return [
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
        ];
    }

    private function resolveSubscriptionChange(
        KraiteUser $user,
        Subscription $newTier,
        ?int $activeAccountId,
    ): RedirectResponse {
        if ($newTier->id === $user->subscription_id) {
            return $this->billingRedirect('status', 'Already on this plan.');
        }

        if ($this->requiresActiveAccountSelection($user, $newTier) && $activeAccountId === null) {
            return $this->billingRedirect('error', 'Pick which account stays active under the new plan.');
        }

        return $this->applySubscriptionChange($user, $newTier, $activeAccountId);
    }

    private function requiresActiveAccountSelection(KraiteUser $user, Subscription $newTier): bool
    {
        return ! $newTier->hasUnlimitedAccounts()
            && $newTier->max_accounts === 1
            && $user->accounts()->count() > 1;
    }

    private function applySubscriptionChange(
        KraiteUser $user,
        Subscription $newTier,
        ?int $activeAccountId,
    ): RedirectResponse {
        if ($this->isInitialPlanSelection($user)) {
            return $this->selectInitialPlan($user, $newTier, $activeAccountId);
        }

        if ($this->isTrialPlanSwitch($user)) {
            return $this->switchTrialPlan($user, $newTier, $activeAccountId);
        }

        return $this->changePaidPlan($user, $newTier, $activeAccountId);
    }

    private function isInitialPlanSelection(KraiteUser $user): bool
    {
        return $user->subscription_id === null && $user->trial_started_at === null;
    }

    private function isTrialPlanSwitch(KraiteUser $user): bool
    {
        return (float) ($user->subscription?->monthly_rate_usdt ?? 0) > 0
            && $user->isTrialActive();
    }

    private function changePaidPlan(
        KraiteUser $user,
        Subscription $newTier,
        ?int $activeAccountId,
    ): RedirectResponse {
        try {
            $changed = $this->persistSubscriptionChange($user, $newTier, $activeAccountId);
        } catch (InsufficientFundsException) {
            return $this->billingRedirect('error', 'Wallet does not cover the new plan after prorate. Top up first.');
        }

        if (! $changed) {
            return $this->billingRedirect('status', 'Already on this plan.');
        }

        return $this->billingRedirect('status', 'Plan updated.');
    }

    private function billingRedirect(string $key, string $message): RedirectResponse
    {
        return redirect()->route('billing')->with($key, $message);
    }

    private function selectInitialPlan(KraiteUser $user, Subscription $newTier, ?int $activeAccountId): RedirectResponse
    {
        $user->subscription_id = $newTier->id;
        $this->maybeAssignActiveAccount($user, $newTier, $activeAccountId);
        $user->save();

        return $this->billingRedirect('status', 'Plan selected. Click "Start trading" when ready.');
    }

    private function switchTrialPlan(KraiteUser $user, Subscription $newTier, ?int $activeAccountId): RedirectResponse
    {
        $user->subscription_id = $newTier->id;
        $this->maybeAssignActiveAccount($user, $newTier, $activeAccountId);
        $user->save();
        $user->syncTrialRenewalAnchor();

        return $this->billingRedirect('status', 'Plan updated.');
    }

    private function persistSubscriptionChange(KraiteUser $user, Subscription $newTier, ?int $activeAccountId): bool
    {
        return DB::transaction(function () use ($user, $newTier, $activeAccountId): bool {
            $lockedUser = KraiteUser::query()->lockForUpdate()->findOrFail($user->id);
            $lockedUser->load('subscription');

            if ($lockedUser->subscription_id === $newTier->id) {
                return false;
            }

            $this->creditProrateRefund($lockedUser, $lockedUser->subscription, now());

            return $this->finishSubscriptionChange($lockedUser, $newTier, $activeAccountId);
        });
    }

    private function creditProrateRefund(KraiteUser $user, ?Subscription $currentTier, CarbonInterface $now): void
    {
        if ($currentTier === null || ! $this->hasProrateableSubscription($user)) {
            return;
        }

        $daysRemaining = (int) ceil($now->diffInDays($user->subscription_renews_at, absolute: false));
        $currentMonthly = (float) $currentTier->monthly_rate_usdt;
        $refund = $this->prorateRefundAmount($daysRemaining, $currentMonthly);

        if ($refund <= 0) {
            return;
        }

        $this->creditProrateRefundToWallet($user, $currentTier, $daysRemaining, $currentMonthly, $refund);
    }

    private function creditProrateRefundToWallet(
        KraiteUser $user,
        Subscription $currentTier,
        int $daysRemaining,
        float $currentMonthly,
        float $refund,
    ): void {
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

    private function hasProrateableSubscription(KraiteUser $user): bool
    {
        return $user->subscription_renews_at !== null
            && $user->subscription_renews_at->isFuture();
    }

    private function prorateRefundAmount(int $daysRemaining, float $currentMonthly): float
    {
        if ($daysRemaining <= 0) {
            return 0.0;
        }

        return round($daysRemaining * ($currentMonthly / 30), 4);
    }

    private function finishSubscriptionChange(KraiteUser $user, Subscription $newTier, ?int $activeAccountId): bool
    {
        $user->subscription_id = $newTier->id;
        $this->maybeAssignActiveAccount($user, $newTier, $activeAccountId);

        if ((float) $newTier->monthly_rate_usdt <= 0) {
            $user->subscription_renews_at = null;
            $user->save();

            return true;
        }

        $user->save();
        $user->load('subscription');

        $this->wallet->runRenewal(
            user: $user,
            newRenewsAt: now()->addMonth()->subDay(),
        );

        return true;
    }

    private function activeTopUpCoin(?string $canonical): ?TopUpCoin
    {
        if ($canonical === null) {
            return null;
        }

        return TopUpCoin::query()
            ->where('canonical', $canonical)
            ->where('is_active', true)
            ->first();
    }

    private function topUpMinimumError(KraiteUser $user, ?TopUpCoin $coin, float $amount): ?RedirectResponse
    {
        $ruleMin = $this->ruleMinimum($user);
        $gatewayMin = $coin === null
            ? 0.0
            : $this->gatewayMinAmountUsdt($coin->canonical, $coin->min_amount_override);
        $effectiveMin = max($ruleMin, $gatewayMin);

        if ($amount + 0.0001 >= $effectiveMin) {
            return null;
        }

        return $this->billingRedirect('error', sprintf(
            'Minimum top-up for %s is %s USDT.',
            $coin?->display_name ?? 'this payment',
            number_format($effectiveMin, 2),
        ));
    }

    /** @return array{callback: string, success: string, cancel: string} */
    private function invoiceUrls(): array
    {
        return [
            'callback' => $this->configuredUrl('services.nowpayments.ipn_callback_url', 'webhooks.payments'),
            'success' => $this->configuredUrl('services.nowpayments.success_url', 'billing'),
            'cancel' => $this->configuredUrl('services.nowpayments.cancel_url', 'billing'),
        ];
    }

    private function configuredUrl(string $configKey, string $fallbackRoute): string
    {
        $configuredUrl = (string) config($configKey);

        if ($configuredUrl !== '' && $configuredUrl !== '0') {
            return $configuredUrl;
        }

        return (string) route($fallbackRoute);
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

        $orderId = $this->assignPaymentOrder($payment, $user);
        $urls = $this->invoiceUrls();

        try {
            $invoice = $this->requestInvoice($user, $coin, $amount, $orderId, $urls);
        } catch (Throwable $e) {
            return $this->invoiceCreationFailure($payment, $user, $amount, $e);
        }

        $payment->invoice_url = $invoice['invoice_url'];
        $payment->save();

        return redirect()->away($invoice['invoice_url']);
    }

    private function assignPaymentOrder(Payment $payment, KraiteUser $user): string
    {
        $emailSlug = Str::slug((string) $user->email) ?: "user-{$user->id}";
        $orderId = "order-{$emailSlug}-{$payment->id}";
        $payment->order_id = $orderId;
        $payment->save();

        return $orderId;
    }

    /**
     * @param  array{callback: string, success: string, cancel: string}  $urls
     * @return array{invoice_url: string}
     */
    private function requestInvoice(
        KraiteUser $user,
        ?TopUpCoin $coin,
        float $amount,
        string $orderId,
        array $urls,
    ): array {
        return NowPaymentsClient::fromConfig()->createInvoice(
            priceAmount: $amount,
            orderId: $orderId,
            ipnCallbackUrl: $urls['callback'],
            successUrl: $urls['success'],
            cancelUrl: $urls['cancel'],
            priceCurrency: 'usdttrc20',
            orderDescription: sprintf('Kraite wallet top-up · %s', $user->email),
            customerEmail: $user->email,
            payCurrency: $coin?->canonical,
        );
    }

    private function invoiceCreationFailure(Payment $payment, KraiteUser $user, float $amount, Throwable $exception): RedirectResponse
    {
        Log::error('[NOWPayments] invoice creation failed', [
            'user_id' => $user->id,
            'amount' => $amount,
            'error' => $exception->getMessage(),
        ]);

        $payment->status = Payment::STATUS_FAILED;
        $payment->save();

        return $this->billingRedirect('error', 'Could not create payment invoice. Please try again or contact admin.');
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
            fn (): float => $this->fetchGatewayMinAmount($canonical, $override),
        );
    }

    private function fetchGatewayMinAmount(string $canonical, ?string $override): float
    {
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
