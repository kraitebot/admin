<?php

declare(strict_types=1);

use Illuminate\Testing\TestResponse;
use Kraite\Core\Models\Kraite;
use Kraite\Core\Models\Payment;
use Kraite\Core\Models\Subscription;
use Kraite\Core\Models\User;
use Kraite\Core\Models\WalletTransaction;

beforeEach(function (): void {
    prepareCoreBillingSchema();

    $engine = Kraite::findOrFail(1);
    $engine->nowpayments_ipn_secret = 'database-ipn-secret';
    $engine->save();

    config(['services.nowpayments.ipn_secret' => 'wrong-environment-secret']);
});

function webhookBillingUser(float $monthlyRate = 100): User
{
    $subscription = Subscription::create([
        'name' => 'Webhook plan',
        'canonical' => 'webhook-'.uniqid(),
        'monthly_rate_usdt' => $monthlyRate,
        'trial_days' => 7,
        'max_accounts' => null,
        'is_active' => true,
    ]);

    return User::create([
        'name' => 'Webhook User',
        'email' => 'webhook-'.uniqid().'@example.test',
        'password' => bcrypt('password'),
        'status' => 'active',
        'is_active' => true,
        'subscription_id' => $subscription->id,
        'wallet_balance_usdt' => 0,
        'trial_started_at' => now()->subDays(30),
        'subscription_renews_at' => now()->subDay(),
    ]);
}

function webhookPayment(User $user, float $priceAmount = 100): Payment
{
    return Payment::create([
        'user_id' => $user->id,
        'order_id' => 'order-'.uniqid(),
        'price_amount' => $priceAmount,
        'outcome_currency' => 'usdttrc20',
        'status' => Payment::STATUS_PENDING,
    ]);
}

/**
 * @param  array<string, mixed>  $payload
 */
function postNowPaymentsWebhook(array $payload): TestResponse
{
    $sorted = $payload;
    ksort($sorted);
    $signature = hash_hmac(
        'sha512',
        (string) json_encode($sorted, JSON_UNESCAPED_SLASHES),
        'database-ipn-secret',
    );

    return test()->withHeader('x-nowpayments-sig', $signature)
        ->postJson(route('webhooks.payments'), $payload);
}

it('credits only the positive delta across partial and final payment webhooks', function (): void {
    $user = webhookBillingUser(monthlyRate: 100);
    $payment = webhookPayment($user, priceAmount: 100);

    $basePayload = [
        'payment_id' => 'np-123',
        'order_id' => $payment->order_id,
        'pay_currency' => 'btc',
        'outcome_currency' => 'usdttrc20',
    ];

    postNowPaymentsWebhook($basePayload + [
        'payment_status' => Payment::STATUS_PARTIALLY_PAID,
        'outcome_amount' => 25,
    ])->assertNoContent();

    expect((float) $user->fresh()->wallet_balance_usdt)->toBe(25.0);
    expect((float) $payment->fresh()->credited_amount)->toBe(25.0);

    postNowPaymentsWebhook($basePayload + [
        'payment_status' => Payment::STATUS_PARTIALLY_PAID,
        'outcome_amount' => 40,
    ])->assertNoContent();

    expect((float) $user->fresh()->wallet_balance_usdt)->toBe(40.0);
    expect((float) $payment->fresh()->credited_amount)->toBe(40.0);

    postNowPaymentsWebhook($basePayload + [
        'payment_status' => Payment::STATUS_PARTIALLY_PAID,
        'outcome_amount' => 40,
    ])->assertNoContent();

    postNowPaymentsWebhook($basePayload + [
        'payment_status' => Payment::STATUS_FINISHED,
        'outcome_amount' => 75,
    ])->assertNoContent();

    postNowPaymentsWebhook($basePayload + [
        'payment_status' => Payment::STATUS_PARTIALLY_PAID,
        'outcome_amount' => 50,
    ])->assertNoContent();

    expect((float) $user->fresh()->wallet_balance_usdt)->toBe(75.0);
    expect((float) $payment->fresh()->credited_amount)->toBe(75.0);
    expect((float) $payment->fresh()->outcome_amount)->toBe(75.0);
    expect($payment->fresh()->status)->toBe(Payment::STATUS_FINISHED);
    expect(WalletTransaction::where('user_id', $user->id)->orderBy('id')->pluck('amount_usdt')->map(fn ($amount): float => (float) $amount)->all())
        ->toBe([25.0, 15.0, 35.0]);
});

it('starts a full calendar-month cycle when a top-up recovers closing mode', function (): void {
    $this->travelTo(now()->startOfSecond());

    $user = webhookBillingUser(monthlyRate: 75);
    $payment = webhookPayment($user, priceAmount: 75);

    postNowPaymentsWebhook([
        'payment_id' => 'np-renewal',
        'order_id' => $payment->order_id,
        'payment_status' => Payment::STATUS_FINISHED,
        'pay_currency' => 'usdttrc20',
        'outcome_currency' => 'usdttrc20',
        'outcome_amount' => 75,
    ])->assertNoContent();

    $user->refresh();

    expect((float) $user->wallet_balance_usdt)->toBe(0.0);
    expect($user->subscription_renews_at?->equalTo(now()->addMonth()->subDay()))->toBeTrue();
    expect(WalletTransaction::where('user_id', $user->id)->orderBy('id')->pluck('type')->all())
        ->toBe([
            WalletTransaction::TYPE_CREDIT_TOPUP,
            WalletTransaction::TYPE_DEBIT_SUBSCRIPTION,
        ]);
});
