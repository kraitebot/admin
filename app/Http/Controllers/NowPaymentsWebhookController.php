<?php

declare(strict_types=1);

namespace App\Http\Controllers;

use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Kraite\Core\Models\Payment;
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

        $paymentId = $payload['payment_id'] ?? null;
        $orderId = $payload['order_id'] ?? null;
        $status = $payload['payment_status'] ?? null;

        if (! is_string($orderId) || ! is_string($status)) {
            Log::warning('[NOWPayments] missing required fields in webhook', [
                'payload' => $payload,
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

        $this->applyWebhook($payment, $payload, $paymentId, $status);

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
        mixed $paymentId,
        string $incomingStatus,
    ): void {
        $renewalRan = false;
        $credited = false;
        $amount = 0.0;

        DB::transaction(function () use (
            $payment,
            $payload,
            $paymentId,
            $incomingStatus,
            &$renewalRan,
            &$credited,
            &$amount,
        ): void {
            $locked = Payment::whereKey($payment->getKey())->lockForUpdate()->first();

            if ($locked === null) {
                return;
            }

            if (
                is_string($paymentId)
                && $locked->nowpayments_payment_id !== null
                && ! hash_equals($locked->nowpayments_payment_id, $paymentId)
            ) {
                Log::warning('[NOWPayments] payment_id does not match the existing order', [
                    'payment_id' => $locked->id,
                    'expected_nowpayments_payment_id' => $locked->nowpayments_payment_id,
                    'received_nowpayments_payment_id' => $paymentId,
                ]);

                return;
            }

            $locked->raw_payload = $payload;

            if ($this->statusRank($incomingStatus) >= $this->statusRank($locked->status)) {
                $locked->status = $incomingStatus;
            }

            if (is_string($paymentId)) {
                $locked->nowpayments_payment_id = $paymentId;
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

            if (! in_array($incomingStatus, Payment::CREDITABLE_STATUSES, true)) {
                return;
            }

            if ($locked->outcome_amount === null && $incomingStatus === Payment::STATUS_PARTIALLY_PAID) {
                Log::warning('[NOWPayments] partially-paid webhook omitted outcome_amount', [
                    'payment_id' => $locked->id,
                ]);

                return;
            }

            $cumulativeOutcome = (string) ($locked->outcome_amount ?? $locked->price_amount);
            $alreadyCredited = (string) ($locked->credited_amount ?? '0');
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
                    'nowpayments_payment_id' => $locked->nowpayments_payment_id,
                    'pay_currency' => $locked->pay_currency,
                    'pay_amount' => $locked->pay_amount,
                    'status' => $locked->status,
                    'cumulative_outcome_amount' => $cumulativeOutcome,
                    'credited_amount_before' => $alreadyCredited,
                ],
            );

            $locked->credited_amount = $cumulativeOutcome;
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
