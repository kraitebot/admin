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
use Kraite\Core\Support\NotificationService;
use Symfony\Component\HttpFoundation\Response;
use Throwable;

/**
 * Receives NOWPayments IPN webhooks. Signature has already been
 * verified by the VerifyNowPaymentsSignature middleware.
 *
 * Idempotent: each Payment row carries a `credited_at` timestamp.
 * The first creditable status update applies the wallet credit + a
 * possible immediate-renewal retry; subsequent webhook retries on
 * the same payment_id update only the status/raw_payload columns.
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

        $payment->status = $status;
        $payment->raw_payload = $payload;

        if (is_string($paymentId)) {
            $payment->nowpayments_payment_id = $paymentId;
        }

        if (isset($payload['pay_currency']) && is_string($payload['pay_currency'])) {
            $payment->pay_currency = $payload['pay_currency'];
        }

        if (isset($payload['pay_amount']) && is_numeric($payload['pay_amount'])) {
            $payment->pay_amount = (string) $payload['pay_amount'];
        }

        if (isset($payload['outcome_amount']) && is_numeric($payload['outcome_amount'])) {
            $payment->outcome_amount = (string) $payload['outcome_amount'];
        }

        if (isset($payload['outcome_currency']) && is_string($payload['outcome_currency'])) {
            $payment->outcome_currency = $payload['outcome_currency'];
        }

        $payment->save();

        if ($payment->isCreditable() && ! $payment->isCredited()) {
            $this->creditPayment($payment);
        }

        return response()->noContent();
    }

    private function creditPayment(Payment $payment): void
    {
        $amount = (float) ($payment->outcome_amount ?? $payment->price_amount);

        if ($amount <= 0) {
            Log::warning('[NOWPayments] zero/negative outcome_amount, skipping credit', [
                'payment_id' => $payment->id,
            ]);

            return;
        }

        $renewalRan = false;

        DB::transaction(function () use ($payment, $amount, &$renewalRan) {
            $user = $payment->user;

            $this->wallet->credit(
                user: $user,
                amount: $amount,
                type: WalletTransaction::TYPE_CREDIT_TOPUP,
                description: sprintf(
                    'NOWPayments top-up #%s',
                    $payment->nowpayments_payment_id ?? $payment->order_id,
                ),
                meta: [
                    'payment_id' => $payment->id,
                    'nowpayments_payment_id' => $payment->nowpayments_payment_id,
                    'pay_currency' => $payment->pay_currency,
                    'pay_amount' => $payment->pay_amount,
                    'status' => $payment->status,
                ],
            );

            $payment->credited_at = now();
            $payment->save();

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
                        'payment_id' => $payment->id,
                        'error' => $e->getMessage(),
                    ]);
                }
            }
        });

        $payment->refresh();
        $this->notifyTopupConfirmed($payment->user, $amount, $renewalRan);
    }

    private function notifyTopupConfirmed(User $user, float $amount, bool $renewalRan): void
    {
        try {
            $user->refresh();
            $user->load('subscription');

            $payment = \Kraite\Core\Models\Payment::where('user_id', $user->id)
                ->whereNotNull('credited_at')
                ->orderByDesc('credited_at')
                ->first();

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
                    'pay_currency' => $payment?->pay_currency,
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
