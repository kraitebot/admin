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
 */
final class NowPaymentsWebhookController extends Controller
{
    public function __construct(private readonly Wallet $wallet) {}

    public function handle(Request $request): Response
    {
        $payload = $request->all();

        $paymentId = $this->gatewayPaymentId($payload['payment_id'] ?? null);
        $parentPaymentId = $this->gatewayPaymentId($payload['parent_payment_id'] ?? null);
        $orderId = $payload['order_id'] ?? null;
        $status = $payload['payment_status'] ?? null;

        if ($paymentId === null || ! is_string($orderId) || ! is_string($status)) {
            Log::warning('[NOWPayments] missing required fields in webhook', [
                'payment_id' => $paymentId,
                'order_id' => is_scalar($orderId) ? (string) $orderId : null,
                'payment_status' => is_scalar($status) ? (string) $status : null,
            ]);

            return response()->noContent();
        }

        $payment = Payment::where('order_id', $orderId)->first();

        if ($payment === null) {
            Log::warning('[NOWPayments] no Payment row matches order_id', [
                'order_id' => $orderId,
                'payment_id' => $paymentId,
            ]);

            return response()->noContent();
        }

        $this->applyWebhook($payment, $payload, $paymentId, $parentPaymentId, $status);

        return response()->noContent();
    }

    /**
     * Persist the gateway event and apply any wallet delta under one payment
     * lock. NOWPayments can deliver retries and status changes out of order.
     *
     * @param  array<string, mixed>  $payload
     */
    private function applyWebhook(
        Payment $payment,
        array $payload,
        string $paymentId,
        ?string $parentPaymentId,
        string $incomingStatus,
    ): void {
        $renewalRan = false;
        $credited = false;
        $amount = 0.0;

        DB::transaction(function () use (
            $payment,
            $payload,
            $paymentId,
            $parentPaymentId,
            $incomingStatus,
            &$renewalRan,
            &$credited,
            &$amount,
        ): void {
            $locked = Payment::whereKey($payment->getKey())->lockForUpdate()->first();

            if ($locked === null) {
                return;
            }

            $parentOwnerPaymentId = $parentPaymentId === null
                ? null
                : PaymentReceipt::query()
                    ->where('gateway_payment_id', $parentPaymentId)
                    ->value('payment_id')
                    ?? Payment::query()
                        ->where('nowpayments_payment_id', $parentPaymentId)
                        ->value('id');

            if ($parentOwnerPaymentId !== null && (int) $parentOwnerPaymentId !== (int) $locked->id) {
                Log::warning('[NOWPayments] parent_payment_id belongs to another order', [
                    'payment_id' => $paymentId,
                    'parent_payment_id' => $parentPaymentId,
                    'expected_order_payment_id' => $locked->id,
                    'actual_order_payment_id' => $parentOwnerPaymentId,
                ]);

                return;
            }

            $originalPaymentId = $locked->nowpayments_payment_id;

            if ($originalPaymentId === null) {
                $originalPaymentId = $parentPaymentId ?? $paymentId;
                $locked->nowpayments_payment_id = $originalPaymentId;
            }

            $isOriginalPayment = hash_equals($originalPaymentId, $paymentId);
            $isLinkedDeposit = $parentPaymentId !== null
                && hash_equals($originalPaymentId, $parentPaymentId);

            if (! $isOriginalPayment && ! $isLinkedDeposit) {
                Log::warning('[NOWPayments] payment_id does not match the existing order', [
                    'payment_id' => $locked->id,
                    'expected_nowpayments_payment_id' => $originalPaymentId,
                    'received_nowpayments_payment_id' => $paymentId,
                    'received_parent_payment_id' => $parentPaymentId,
                ]);

                return;
            }

            $receipt = PaymentReceipt::query()->firstOrNew([
                'gateway_payment_id' => $paymentId,
            ]);

            if ($receipt->exists && (int) $receipt->payment_id !== (int) $locked->id) {
                Log::warning('[NOWPayments] payment_id already belongs to another order', [
                    'payment_id' => $paymentId,
                    'expected_order_payment_id' => $locked->id,
                    'actual_order_payment_id' => $receipt->payment_id,
                ]);

                return;
            }

            if (! $receipt->exists) {
                $receipt->payment_id = $locked->id;
                $receipt->parent_gateway_payment_id = $parentPaymentId;
                $receipt->credited_amount = $isOriginalPayment
                    ? (string) ($locked->credited_amount ?? '0')
                    : '0';
            }

            $locked->raw_payload = $payload;

            if ($this->statusRank($incomingStatus) >= $this->statusRank($locked->status)) {
                $locked->status = $incomingStatus;
            }

            if (isset($payload['pay_currency']) && is_string($payload['pay_currency'])) {
                $locked->pay_currency = $payload['pay_currency'];
            }

            if (isset($payload['pay_amount']) && is_numeric($payload['pay_amount'])) {
                $incomingPayAmount = (string) $payload['pay_amount'];

                if ($locked->pay_amount === null || Math::gt($incomingPayAmount, $locked->pay_amount)) {
                    $locked->pay_amount = $incomingPayAmount;
                }
            }

            if (isset($payload['outcome_amount']) && is_numeric($payload['outcome_amount'])) {
                $incomingOutcome = (string) $payload['outcome_amount'];

                if ($locked->outcome_amount === null || Math::gt($incomingOutcome, $locked->outcome_amount)) {
                    $locked->outcome_amount = $incomingOutcome;
                }
            }

            if (isset($payload['outcome_currency']) && is_string($payload['outcome_currency'])) {
                $locked->outcome_currency = $payload['outcome_currency'];
            }

            $locked->save();
            $receipt->save();

            if (! in_array($incomingStatus, Payment::CREDITABLE_STATUSES, true)) {
                return;
            }

            $incomingOutcome = isset($payload['outcome_amount']) && is_numeric($payload['outcome_amount'])
                ? (string) $payload['outcome_amount']
                : null;

            if ($incomingOutcome === null && ($incomingStatus === Payment::STATUS_PARTIALLY_PAID || $isLinkedDeposit)) {
                Log::warning('[NOWPayments] creditable webhook omitted outcome_amount', [
                    'payment_id' => $locked->id,
                    'gateway_payment_id' => $paymentId,
                ]);

                return;
            }

            $cumulativeOutcome = $incomingOutcome ?? (string) $locked->price_amount;
            $alreadyCredited = (string) $receipt->credited_amount;
            $creditDelta = Math::sub($cumulativeOutcome, $alreadyCredited);

            if (Math::lte($creditDelta, '0')) {
                return;
            }

            $amount = (float) $creditDelta;

            $user = $locked->user;

            $this->wallet->credit(
                user: $user,
                amount: $amount,
                type: WalletTransaction::TYPE_CREDIT_TOPUP,
                description: sprintf(
                    'NOWPayments top-up #%s',
                    $locked->nowpayments_payment_id ?? $locked->order_id,
                ),
                meta: [
                    'payment_id' => $locked->id,
                    'nowpayments_payment_id' => $paymentId,
                    'parent_payment_id' => $parentPaymentId,
                    'pay_currency' => $locked->pay_currency,
                    'pay_amount' => $locked->pay_amount,
                    'status' => $locked->status,
                    'cumulative_outcome_amount' => $cumulativeOutcome,
                    'credited_amount_before' => $alreadyCredited,
                ],
            );

            $receipt->credited_amount = $cumulativeOutcome;
            $receipt->save();

            $locked->credited_amount = Math::add((string) ($locked->credited_amount ?? '0'), $creditDelta);
            $locked->outcome_amount = $locked->credited_amount;
            $locked->credited_at = now();
            $locked->save();

            $credited = true;

            $user->refresh();
            $user->load('subscription');

            if ($user->isInClosingMode() && $user->subscriptionCoversNextRenewal()) {
                try {
                    $this->wallet->runRenewal(
                        user: $user,
                        newRenewsAt: now()->addMonth()->subDay(),
                    );

                    $renewalRan = true;
                } catch (Throwable $e) {
                    Log::error('[NOWPayments] auto-renewal retry failed after top-up', [
                        'user_id' => $user->id,
                        'payment_id' => $locked->id,
                        'error' => $e->getMessage(),
                    ]);
                }
            }
        });

        if (! $credited) {
            return;
        }

        $payment->refresh();
        $this->notifyTopupConfirmed($payment->user, $amount, $renewalRan, $payment->pay_currency);
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

        return $paymentId !== '' && strlen($paymentId) <= 255 ? $paymentId : null;
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
                referenceData: [
                    'amount_usdt' => $amount,
                    'balance_after' => (float) $user->wallet_balance_usdt,
                    'monthly_rate_usdt' => (float) ($user->subscription?->monthly_rate_usdt ?? 0),
                    'shortfall_usdt' => $user->renewalShortfallUsdt(),
                    'source' => 'NOWPayments',
                    'renewal_ran' => $renewalRan,
                    'pay_currency' => $payCurrency,
                ],
            );
        } catch (Throwable $e) {
            Log::warning('[NOWPayments] topup_confirmed notification failed', [
                'user_id' => $user->id,
                'message' => $e->getMessage(),
            ]);
        }
    }
}
