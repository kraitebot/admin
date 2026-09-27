<?php

declare(strict_types=1);

namespace App\Http\Controllers;

use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Kraite\Core\Models\Payment;
use Kraite\Core\Models\PaymentReceipt;
use Kraite\Core\Models\User;
use Kraite\Core\Models\WalletTransaction;
use Kraite\Core\Support\Billing\Wallet;
use Kraite\Core\Support\Math;
use Kraite\Core\Support\NotificationService;
use Symfony\Component\HttpFoundation\Response;
use Throwable;

/**
 * Receives NOWPayments IPN webhooks. Signature has already been
 * verified by the VerifyNowPaymentsSignature middleware.
 *
 * Idempotent: each Payment row tracks the cumulative outcome already
 * credited. Repeated and incremental partially-paid webhooks apply only
 * the positive delta, followed by a possible immediate-renewal retry.
 *
 * @phpstan-type WebhookValue scalar|array<array-key, scalar|array<array-key, scalar|null>|null>|null
 * @phpstan-type WebhookPayload array<string, WebhookValue>
 * @phpstan-type WebhookInput array{payment_id: string, parent_payment_id: string|null, order_id: string, status: string}
 * @phpstan-type WebhookIdentity array{original_payment_id: string, is_original_payment: bool, is_linked_deposit: bool}
 * @phpstan-type WebhookCreditResult array{renewal_ran: bool, credited: bool, amount: float}
 */
final class NowPaymentsWebhookController extends Controller
{
    public function __construct(private readonly Wallet $wallet) {}

    public function handle(Request $request): Response
    {
        $payload = $request->all();
        $input = $this->webhookInput($payload);

        if ($input === null) {
            return response()->noContent();
        }

        $payment = $this->paymentForWebhookInput($input);

        if ($payment === null) {
            return response()->noContent();
        }

        $this->applyWebhook(
            $payment,
            $payload,
            $input['payment_id'],
            $input['parent_payment_id'],
            $input['status'],
        );

        return response()->noContent();
    }

    /** @param WebhookInput $input */
    private function paymentForWebhookInput(array $input): ?Payment
    {
        $payment = Payment::where('order_id', $input['order_id'])->first();

        if ($payment !== null) {
            return $payment;
        }

        Log::warning('[NOWPayments] no Payment row matches order_id', [
            'order_id' => $input['order_id'],
            'payment_id' => $input['payment_id'],
        ]);

        return null;
    }

    /**
     * @param  WebhookPayload  $payload
     * @return WebhookInput|null
     */
    private function webhookInput(array $payload): ?array
    {
        $paymentId = $this->gatewayPaymentId($payload['payment_id'] ?? null);
        $parentPaymentId = $this->gatewayPaymentId($payload['parent_payment_id'] ?? null);
        $orderId = $payload['order_id'] ?? null;
        $status = $payload['payment_status'] ?? null;

        if ($paymentId === null || ! is_string($orderId) || ! is_string($status)) {
            $this->logInvalidWebhookInput($paymentId, $orderId, $status);

            return null;
        }

        return [
            'payment_id' => $paymentId,
            'parent_payment_id' => $parentPaymentId,
            'order_id' => $orderId,
            'status' => $status,
        ];
    }

    private function logInvalidWebhookInput(?string $paymentId, mixed $orderId, mixed $status): void
    {
        Log::warning('[NOWPayments] missing required fields in webhook', [
            'payment_id' => $paymentId,
            'order_id' => is_scalar($orderId) ? (string) $orderId : null,
            'payment_status' => is_scalar($status) ? (string) $status : null,
        ]);
    }

    /**
     * Persist the gateway event and apply any wallet delta under one payment
     * lock. NOWPayments can deliver retries and status changes out of order.
     *
     * @param  WebhookPayload  $payload
     */
    private function applyWebhook(
        Payment $payment,
        array $payload,
        string $paymentId,
        ?string $parentPaymentId,
        string $incomingStatus,
    ): void {
        $result = $this->runWebhookTransaction(
            $payment,
            $payload,
            $paymentId,
            $parentPaymentId,
            $incomingStatus,
        );

        if (! $result['credited']) {
            return;
        }

        $payment->refresh();
        $this->notifyTopupConfirmed($payment->user, $result['amount'], $result['renewal_ran'], $payment->pay_currency);
    }

    /**
     * @param  WebhookPayload  $payload
     * @return WebhookCreditResult
     */
    private function runWebhookTransaction(
        Payment $payment,
        array $payload,
        string $paymentId,
        ?string $parentPaymentId,
        string $incomingStatus,
    ): array {
        return DB::transaction(fn (): array => $this->processWebhookTransaction(
            $payment,
            $payload,
            $paymentId,
            $parentPaymentId,
            $incomingStatus,
        ));
    }

    /**
     * @param  WebhookPayload  $payload
     * @return WebhookCreditResult
     */
    private function processWebhookTransaction(
        Payment $payment,
        array $payload,
        string $paymentId,
        ?string $parentPaymentId,
        string $incomingStatus,
    ): array {
        $locked = Payment::whereKey($payment->getKey())->lockForUpdate()->first();

        if ($locked === null) {
            return $this->noCreditResult();
        }

        return $this->processLockedWebhook($locked, $payload, $paymentId, $parentPaymentId, $incomingStatus);
    }

    /**
     * @param  WebhookPayload  $payload
     * @return WebhookCreditResult
     */
    private function processLockedWebhook(
        Payment $locked,
        array $payload,
        string $paymentId,
        ?string $parentPaymentId,
        string $incomingStatus,
    ): array {
        $identity = $this->webhookIdentity($locked, $paymentId, $parentPaymentId);

        if ($identity === null) {
            return $this->noCreditResult();
        }

        $receipt = $this->webhookReceipt($locked, $paymentId, $parentPaymentId, $identity['is_original_payment']);

        if ($receipt === null) {
            return $this->noCreditResult();
        }

        $this->persistWebhookState($locked, $receipt, $payload, $incomingStatus);

        return $this->creditWebhook(
            $locked,
            $receipt,
            $payload,
            $paymentId,
            $parentPaymentId,
            $incomingStatus,
            $identity['is_linked_deposit'],
        );
    }

    /** @return WebhookIdentity|null */
    private function webhookIdentity(Payment $locked, string $paymentId, ?string $parentPaymentId): ?array
    {
        if (! $this->parentPaymentMatches($locked, $paymentId, $parentPaymentId)) {
            return null;
        }

        $identity = $this->buildWebhookIdentity($locked, $paymentId, $parentPaymentId);

        if ($identity['is_original_payment'] || $identity['is_linked_deposit']) {
            return $identity;
        }

        Log::warning('[NOWPayments] payment_id does not match the existing order', [
            'payment_id' => $locked->id,
            'expected_nowpayments_payment_id' => $identity['original_payment_id'],
            'received_nowpayments_payment_id' => $paymentId,
            'received_parent_payment_id' => $parentPaymentId,
        ]);

        return null;
    }

    /** @return WebhookIdentity */
    private function buildWebhookIdentity(Payment $locked, string $paymentId, ?string $parentPaymentId): array
    {
        $originalPaymentId = $locked->nowpayments_payment_id;

        if ($originalPaymentId === null) {
            $originalPaymentId = $parentPaymentId ?? $paymentId;
            $locked->nowpayments_payment_id = $originalPaymentId;
        }

        $isOriginalPayment = hash_equals($originalPaymentId, $paymentId);
        $isLinkedDeposit = $parentPaymentId !== null
            && hash_equals($originalPaymentId, $parentPaymentId);

        return [
            'original_payment_id' => $originalPaymentId,
            'is_original_payment' => $isOriginalPayment,
            'is_linked_deposit' => $isLinkedDeposit,
        ];
    }

    private function parentPaymentMatches(Payment $locked, string $paymentId, ?string $parentPaymentId): bool
    {
        if ($parentPaymentId === null) {
            return true;
        }

        $parentOwnerPaymentId = $this->parentPaymentOwnerId($parentPaymentId);

        if ($parentOwnerPaymentId === null || (int) $parentOwnerPaymentId === (int) $locked->id) {
            return true;
        }

        $this->logForeignParentPayment($paymentId, $parentPaymentId, $locked, $parentOwnerPaymentId);

        return false;
    }

    private function parentPaymentOwnerId(string $parentPaymentId): ?int
    {
        $receiptOwner = PaymentReceipt::query()
            ->where('gateway_payment_id', $parentPaymentId)
            ->value('payment_id');

        if ($receiptOwner !== null) {
            return (int) $receiptOwner;
        }

        $paymentOwner = Payment::query()
            ->where('nowpayments_payment_id', $parentPaymentId)
            ->value('id');

        return $paymentOwner === null ? null : (int) $paymentOwner;
    }

    private function logForeignParentPayment(
        string $paymentId,
        string $parentPaymentId,
        Payment $locked,
        int $parentOwnerPaymentId,
    ): void {
        Log::warning('[NOWPayments] parent_payment_id belongs to another order', [
            'payment_id' => $paymentId,
            'parent_payment_id' => $parentPaymentId,
            'expected_order_payment_id' => $locked->id,
            'actual_order_payment_id' => $parentOwnerPaymentId,
        ]);
    }

    private function webhookReceipt(
        Payment $locked,
        string $paymentId,
        ?string $parentPaymentId,
        bool $isOriginalPayment,
    ): ?PaymentReceipt {
        $receipt = PaymentReceipt::query()->firstOrNew([
            'gateway_payment_id' => $paymentId,
        ]);

        if ($receipt->exists && (int) $receipt->payment_id !== (int) $locked->id) {
            Log::warning('[NOWPayments] payment_id already belongs to another order', [
                'payment_id' => $paymentId,
                'expected_order_payment_id' => $locked->id,
                'actual_order_payment_id' => $receipt->payment_id,
            ]);

            return null;
        }

        if (! $receipt->exists) {
            $receipt->payment_id = $locked->id;
            $receipt->parent_gateway_payment_id = $parentPaymentId;
            $receipt->credited_amount = $isOriginalPayment
                ? (string) ($locked->credited_amount ?? '0')
                : '0';
        }

        return $receipt;
    }

    /**
     * @param  WebhookPayload  $payload
     */
    private function persistWebhookState(Payment $locked, PaymentReceipt $receipt, array $payload, string $incomingStatus): void
    {
        $locked->raw_payload = $payload;

        if ($this->statusRank($incomingStatus) >= $this->statusRank($locked->status)) {
            $locked->status = $incomingStatus;
        }

        $this->updateWebhookCurrencies($locked, $payload);
        $this->updateWebhookAmounts($locked, $payload);
        $locked->save();
        $receipt->save();
    }

    /**
     * @param  WebhookPayload  $payload
     */
    private function updateWebhookCurrencies(Payment $locked, array $payload): void
    {
        if (isset($payload['pay_currency']) && is_string($payload['pay_currency'])) {
            $locked->pay_currency = $payload['pay_currency'];
        }

        if (isset($payload['outcome_currency']) && is_string($payload['outcome_currency'])) {
            $locked->outcome_currency = $payload['outcome_currency'];
        }
    }

    /**
     * @param  WebhookPayload  $payload
     */
    private function updateWebhookAmounts(Payment $locked, array $payload): void
    {
        $this->updatePayAmount($locked, $this->numericPayloadValue($payload, 'pay_amount'));
        $this->updateOutcomeAmount($locked, $this->numericPayloadValue($payload, 'outcome_amount'));
    }

    private function updatePayAmount(Payment $locked, ?string $incomingPayAmount): void
    {
        if ($incomingPayAmount === null) {
            return;
        }

        if ($locked->pay_amount === null || Math::gt($incomingPayAmount, $locked->pay_amount)) {
            $locked->pay_amount = $incomingPayAmount;
        }
    }

    private function updateOutcomeAmount(Payment $locked, ?string $incomingOutcome): void
    {
        if ($incomingOutcome === null) {
            return;
        }

        if ($locked->outcome_amount === null || Math::gt($incomingOutcome, $locked->outcome_amount)) {
            $locked->outcome_amount = $incomingOutcome;
        }
    }

    /**
     * @param  WebhookPayload  $payload
     */
    private function numericPayloadValue(array $payload, string $key): ?string
    {
        if (! isset($payload[$key]) || ! is_numeric($payload[$key])) {
            return null;
        }

        return (string) $payload[$key];
    }

    /**
     * @param  WebhookPayload  $payload
     * @return WebhookCreditResult
     */
    private function creditWebhook(
        Payment $locked,
        PaymentReceipt $receipt,
        array $payload,
        string $paymentId,
        ?string $parentPaymentId,
        string $incomingStatus,
        bool $isLinkedDeposit,
    ): array {
        if (! in_array($incomingStatus, Payment::CREDITABLE_STATUSES, true)) {
            return $this->noCreditResult();
        }

        $creditAmounts = $this->creditAmounts($locked, $receipt, $payload, $paymentId, $incomingStatus, $isLinkedDeposit);

        if ($creditAmounts === null) {
            return $this->noCreditResult();
        }

        return $this->applyCredit(
            $locked,
            $receipt,
            $paymentId,
            $parentPaymentId,
            $creditAmounts['cumulative_outcome'],
            $creditAmounts['already_credited'],
            $creditAmounts['credit_delta'],
        );
    }

    /**
     * @param  WebhookPayload  $payload
     * @return array{cumulative_outcome: string, already_credited: string, credit_delta: string}|null
     */
    private function creditAmounts(
        Payment $locked,
        PaymentReceipt $receipt,
        array $payload,
        string $paymentId,
        string $incomingStatus,
        bool $isLinkedDeposit,
    ): ?array {
        $incomingOutcome = $this->numericPayloadValue($payload, 'outcome_amount');

        if ($this->missingCreditOutcome($locked, $paymentId, $incomingStatus, $incomingOutcome, $isLinkedDeposit)) {
            return null;
        }

        $cumulativeOutcome = $incomingOutcome ?? (string) $locked->price_amount;
        $alreadyCredited = (string) $receipt->credited_amount;
        $creditDelta = Math::sub($cumulativeOutcome, $alreadyCredited);

        return Math::lte($creditDelta, '0')
            ? null
            : [
                'cumulative_outcome' => $cumulativeOutcome,
                'already_credited' => $alreadyCredited,
                'credit_delta' => $creditDelta,
            ];
    }

    private function missingCreditOutcome(
        Payment $locked,
        string $paymentId,
        string $incomingStatus,
        ?string $incomingOutcome,
        bool $isLinkedDeposit,
    ): bool {
        if ($incomingOutcome !== null || ($incomingStatus !== Payment::STATUS_PARTIALLY_PAID && ! $isLinkedDeposit)) {
            return false;
        }

        Log::warning('[NOWPayments] creditable webhook omitted outcome_amount', [
            'payment_id' => $locked->id,
            'gateway_payment_id' => $paymentId,
        ]);

        return true;
    }

    /** @return WebhookCreditResult */
    private function applyCredit(
        Payment $locked,
        PaymentReceipt $receipt,
        string $paymentId,
        ?string $parentPaymentId,
        string $cumulativeOutcome,
        string $alreadyCredited,
        string $creditDelta,
    ): array {
        $amount = (float) $creditDelta;
        $user = $locked->user;

        if ($user === null) {
            throw new \RuntimeException('Payment user relationship is missing.');
        }

        $this->creditWallet($user, $locked, $amount, $paymentId, $parentPaymentId, $cumulativeOutcome, $alreadyCredited);
        $this->persistCreditState($locked, $receipt, $cumulativeOutcome, $creditDelta);

        return [
            'renewal_ran' => $this->attemptRenewal($user, $locked->id),
            'credited' => true,
            'amount' => $amount,
        ];
    }

    private function creditWallet(
        User $user,
        Payment $locked,
        float $amount,
        string $paymentId,
        ?string $parentPaymentId,
        string $cumulativeOutcome,
        string $alreadyCredited,
    ): void {
        $this->wallet->credit(
            user: $user,
            amount: $amount,
            type: WalletTransaction::TYPE_CREDIT_TOPUP,
            description: sprintf(
                'NOWPayments top-up #%s',
                $locked->nowpayments_payment_id ?? $locked->order_id,
            ),
            meta: $this->creditMetadata($locked, $paymentId, $parentPaymentId, $cumulativeOutcome, $alreadyCredited),
        );
    }

    /** @return array<string, mixed> */
    private function creditMetadata(
        Payment $locked,
        string $paymentId,
        ?string $parentPaymentId,
        string $cumulativeOutcome,
        string $alreadyCredited,
    ): array {
        return [
            'payment_id' => $locked->id,
            'nowpayments_payment_id' => $paymentId,
            'parent_payment_id' => $parentPaymentId,
            'pay_currency' => $locked->pay_currency,
            'pay_amount' => $locked->pay_amount,
            'status' => $locked->status,
            'cumulative_outcome_amount' => $cumulativeOutcome,
            'credited_amount_before' => $alreadyCredited,
        ];
    }

    private function persistCreditState(
        Payment $locked,
        PaymentReceipt $receipt,
        string $cumulativeOutcome,
        string $creditDelta,
    ): void {
        $receipt->credited_amount = $cumulativeOutcome;
        $receipt->save();

        $locked->credited_amount = Math::add((string) ($locked->credited_amount ?? '0'), $creditDelta);
        $locked->outcome_amount = $locked->credited_amount;
        $locked->credited_at = now();
        $locked->save();
    }

    private function attemptRenewal(User $user, int $paymentId): bool
    {
        $user->refresh();
        $user->load('subscription');

        if (! $user->isInClosingMode() || ! $user->subscriptionCoversNextRenewal()) {
            return false;
        }

        try {
            $this->wallet->runRenewal(
                user: $user,
                newRenewsAt: now()->addMonth()->subDay(),
            );

            return true;
        } catch (Throwable $e) {
            Log::error('[NOWPayments] auto-renewal retry failed after top-up', [
                'user_id' => $user->id,
                'payment_id' => $paymentId,
                'error' => $e->getMessage(),
            ]);

            return false;
        }
    }

    /** @return WebhookCreditResult */
    private function noCreditResult(): array
    {
        return [
            'renewal_ran' => false,
            'credited' => false,
            'amount' => 0.0,
        ];
    }

    private function statusRank(string $status): int
    {
        return match ($status) {
            Payment::STATUS_PENDING => 0,
            Payment::STATUS_WAITING => 10,
            Payment::STATUS_CONFIRMING => 20,
            Payment::STATUS_CONFIRMED => 30,
            Payment::STATUS_SENDING => 40,
            Payment::STATUS_PARTIALLY_PAID => 50,
            Payment::STATUS_FAILED, Payment::STATUS_EXPIRED => 80,
            Payment::STATUS_FINISHED => 100,
            Payment::STATUS_REFUNDED => 110,
            default => 0,
        };
    }

    private function gatewayPaymentId(mixed $value): ?string
    {
        if (! is_string($value) && ! is_int($value)) {
            return null;
        }

        $paymentId = trim((string) $value);

        return $this->validGatewayPaymentId($paymentId) ? $paymentId : null;
    }

    private function validGatewayPaymentId(string $paymentId): bool
    {
        return $paymentId !== '' && strlen($paymentId) <= 255;
    }

    private function notifyTopupConfirmed(
        User $user,
        float $amount,
        bool $renewalRan,
        ?string $payCurrency,
    ): void {
        try {
            $user->refresh();
            $user->load('subscription');

            NotificationService::send(
                user: $user,
                canonical: 'subscription_topup_confirmed',
                referenceData: $this->topupNotificationData($user, $amount, $renewalRan, $payCurrency),
            );
        } catch (Throwable $e) {
            Log::warning('[NOWPayments] topup_confirmed notification failed', [
                'user_id' => $user->id,
                'message' => $e->getMessage(),
            ]);
        }
    }

    /** @return array<string, mixed> */
    private function topupNotificationData(User $user, float $amount, bool $renewalRan, ?string $payCurrency): array
    {
        return [
            'amount_usdt' => $amount,
            'balance_after' => (float) $user->wallet_balance_usdt,
            'monthly_rate_usdt' => (float) ($user->subscription?->monthly_rate_usdt ?? 0),
            'shortfall_usdt' => $user->renewalShortfallUsdt(),
            'source' => 'NOWPayments',
            'renewal_ran' => $renewalRan,
            'pay_currency' => $payCurrency,
        ];
    }
}
