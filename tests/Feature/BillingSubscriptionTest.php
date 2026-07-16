<?php

declare(strict_types=1);

use Illuminate\Routing\Middleware\ThrottleRequests;
use Illuminate\Support\Facades\Http;
use Kraite\Core\Models\Account;
use Kraite\Core\Models\Kraite;
use Kraite\Core\Models\Payment;
use Kraite\Core\Models\Subscription;
use Kraite\Core\Models\User;
use Kraite\Core\Models\WalletTransaction;

beforeEach(function (): void {
    $this->withoutMiddleware(ThrottleRequests::class);

    prepareBillingSchema();
});

/**
 * Build the minimum kraitebot/core-owned schema the billing
 * subscription flow touches. Mirrors the scaffold pattern used by
 * RegistrationTest — admin owns no migrations for these shared tables.
 */
function prepareBillingSchema(): void
{
    prepareCoreBillingSchema();
}

function makeBillingUser(array $attributes = []): User
{
    return User::create(array_merge([
        'name' => 'Trader',
        'email' => 'trader'.uniqid().'@example.test',
        'password' => bcrypt('password'),
        'status' => 'active',
        'is_active' => true,
    ], $attributes));
}

function makeCappedPlan(): Subscription
{
    return Subscription::create([
        'name' => 'Solo',
        'canonical' => 'solo-'.uniqid(),
        'monthly_rate_usdt' => 30,
        'trial_days' => 7,
        'max_accounts' => 1,
        'is_active' => true,
    ]);
}

it('rejects setting an active account that belongs to another user', function (): void {
    $plan = makeCappedPlan();
    $attacker = makeBillingUser();
    $victim = makeBillingUser();

    $victimAccount = Account::create([
        'name' => 'Victim Binance',
        'user_id' => $victim->id,
    ]);

    $this->actingAs($attacker)
        ->from(route('billing'))
        ->post(route('billing.subscription'), [
            'subscription_id' => $plan->id,
            'active_account_id' => $victimAccount->id,
        ])
        ->assertRedirect(route('billing'))
        ->assertSessionHasErrors('active_account_id');

    expect($attacker->fresh()->active_account_id)->toBeNull();
});

it('allows setting an active account the user owns', function (): void {
    $plan = makeCappedPlan();
    $user = makeBillingUser();

    $ownAccount = Account::create([
        'name' => 'My Binance',
        'user_id' => $user->id,
    ]);

    $this->actingAs($user)
        ->from(route('billing'))
        ->post(route('billing.subscription'), [
            'subscription_id' => $plan->id,
            'active_account_id' => $ownAccount->id,
        ])
        ->assertRedirect(route('billing'))
        ->assertSessionHasNoErrors();

    expect($user->fresh()->active_account_id)->toBe($ownAccount->id);
});

it('starts a trial with a matching first renewal anchor', function (): void {
    $this->travelTo(now()->startOfSecond());

    $plan = makeCappedPlan();
    $user = makeBillingUser(['subscription_id' => $plan->id]);

    $this->actingAs($user)
        ->post(route('billing.start-trading'))
        ->assertRedirect(route('billing'));

    $user->refresh();

    expect($user->trial_started_at?->equalTo(now()))->toBeTrue();
    expect($user->subscription_renews_at?->equalTo(now()->addDays(7)))->toBeTrue();
});

it('repairs a legacy started trial that is missing its renewal anchor', function (): void {
    $plan = makeCappedPlan();
    $trialStartedAt = now()->subDays(3)->startOfSecond();
    $user = makeBillingUser([
        'subscription_id' => $plan->id,
        'trial_started_at' => $trialStartedAt,
        'subscription_renews_at' => null,
    ]);

    $this->actingAs($user)
        ->post(route('billing.start-trading'))
        ->assertRedirect(route('billing'));

    $user->refresh();

    expect($user->trial_started_at?->equalTo($trialStartedAt))->toBeTrue();
    expect($user->subscription_renews_at?->equalTo($trialStartedAt->copy()->addDays(7)))->toBeTrue();
});

it('realigns the first renewal when an active trial changes plan', function (): void {
    $this->travelTo(now()->startOfSecond());

    $currentPlan = makeCappedPlan();
    $newPlan = Subscription::create([
        'name' => 'Extended',
        'canonical' => 'extended-'.uniqid(),
        'monthly_rate_usdt' => 60,
        'trial_days' => 14,
        'max_accounts' => null,
        'is_active' => true,
    ]);
    $trialStartedAt = now()->subDays(2);
    $user = makeBillingUser([
        'subscription_id' => $currentPlan->id,
        'trial_started_at' => $trialStartedAt,
        'subscription_renews_at' => $trialStartedAt->copy()->addDays(7),
    ]);

    $this->actingAs($user)
        ->post(route('billing.subscription'), ['subscription_id' => $newPlan->id])
        ->assertRedirect(route('billing'))
        ->assertSessionHasNoErrors();

    $user->refresh();

    expect($user->subscription_id)->toBe($newPlan->id);
    expect($user->subscription_renews_at?->equalTo($trialStartedAt->copy()->addDays(14)))->toBeTrue();
});

it('atomically prorates and starts a fresh paid cycle when changing plan', function (): void {
    $this->travelTo(now()->startOfSecond());

    $currentPlan = makeCappedPlan();
    $newPlan = Subscription::create([
        'name' => 'Unlimited',
        'canonical' => 'unlimited-'.uniqid(),
        'monthly_rate_usdt' => 60,
        'trial_days' => 7,
        'max_accounts' => null,
        'is_active' => true,
    ]);
    $user = makeBillingUser([
        'subscription_id' => $currentPlan->id,
        'wallet_balance_usdt' => 100,
        'trial_started_at' => now()->subDays(30),
        'subscription_renews_at' => now()->addDays(15),
    ]);

    $this->actingAs($user)
        ->post(route('billing.subscription'), ['subscription_id' => $newPlan->id])
        ->assertRedirect(route('billing'))
        ->assertSessionHasNoErrors();

    $user->refresh();

    expect($user->subscription_id)->toBe($newPlan->id);
    expect((float) $user->wallet_balance_usdt)->toBe(55.0);
    expect($user->subscription_renews_at?->equalTo(now()->addMonth()->subDay()))->toBeTrue();
    expect(WalletTransaction::where('user_id', $user->id)->orderBy('id')->pluck('type')->all())
        ->toBe([
            WalletTransaction::TYPE_CREDIT_PRORATE_REFUND,
            WalletTransaction::TYPE_DEBIT_SUBSCRIPTION,
        ]);
});

it('refunds unused paid time without attempting a debit when switching to a free plan', function (): void {
    $this->travelTo(now()->startOfSecond());

    $currentPlan = makeCappedPlan();
    $freePlan = Subscription::create([
        'name' => 'Free',
        'canonical' => 'free-'.uniqid(),
        'monthly_rate_usdt' => 0,
        'trial_days' => 0,
        'max_accounts' => null,
        'is_active' => true,
    ]);
    $user = makeBillingUser([
        'subscription_id' => $currentPlan->id,
        'wallet_balance_usdt' => 10,
        'trial_started_at' => now()->subDays(30),
        'subscription_renews_at' => now()->addDays(15),
    ]);

    $this->actingAs($user)
        ->post(route('billing.subscription'), ['subscription_id' => $freePlan->id])
        ->assertRedirect(route('billing'))
        ->assertSessionHasNoErrors();

    $user->refresh();

    expect($user->subscription_id)->toBe($freePlan->id);
    expect((float) $user->wallet_balance_usdt)->toBe(25.0);
    expect($user->subscription_renews_at)->toBeNull();
    expect(WalletTransaction::where('user_id', $user->id)->sole()->type)
        ->toBe(WalletTransaction::TYPE_CREDIT_PRORATE_REFUND);
});

it('requires an active account whenever a one-account plan has multiple accounts', function (): void {
    $plan = makeCappedPlan();
    $user = makeBillingUser();

    Account::create(['name' => 'First', 'user_id' => $user->id]);
    Account::create(['name' => 'Second', 'user_id' => $user->id]);

    $this->actingAs($user)
        ->post(route('billing.subscription'), ['subscription_id' => $plan->id])
        ->assertRedirect(route('billing'))
        ->assertSessionHas('error');

    expect($user->fresh()->subscription_id)->toBeNull();
});

it('rejects inactive and invite-only plans from the trader endpoint', function (array $attributes): void {
    $plan = Subscription::create(array_merge([
        'name' => 'Restricted',
        'canonical' => 'restricted-'.uniqid(),
        'monthly_rate_usdt' => 0,
        'trial_days' => 0,
        'max_accounts' => null,
        'is_active' => true,
    ], $attributes));
    $user = makeBillingUser();

    $this->actingAs($user)
        ->post(route('billing.subscription'), ['subscription_id' => $plan->id])
        ->assertInvalid('subscription_id');

    expect($user->fresh()->subscription_id)->toBeNull();
})->with([
    'inactive plan' => [['is_active' => false]],
    'black plan' => [['canonical' => Subscription::CANONICAL_BLACK]],
]);

it('renders real billing forms without subscription pause controls and hides the invite-only plan', function (): void {
    $plan = makeCappedPlan();
    Subscription::create([
        'name' => 'Black',
        'canonical' => Subscription::CANONICAL_BLACK,
        'monthly_rate_usdt' => 0,
        'trial_days' => 0,
        'max_accounts' => null,
        'is_active' => true,
    ]);
    $user = makeBillingUser(['subscription_id' => $plan->id]);

    $response = $this->actingAs($user)
        ->get(route('billing'));

    $response
        ->assertOk()
        ->assertSee(route('billing.subscription'), false)
        ->assertSee(route('billing.start-trading'), false)
        ->assertSee(route('billing.topup'), false)
        ->assertDontSee('/billing/pause', false)
        ->assertDontSee('/billing/resume', false)
        ->assertDontSee('Pause subscription')
        ->assertDontSee('Resume subscription')
        ->assertDontSee('Black');

    expect($response->getContent())->toMatch(
        '/<button[^>]+class="[^"]*bg-accent[^"]*"[^>]+x-text="[^"]*Switch to '.preg_quote($plan->name, '/').'[^"]*"/s',
    );

    $this->actingAs($user)
        ->post('/billing/pause')
        ->assertNotFound();

    $this->actingAs($user)
        ->post('/billing/resume')
        ->assertNotFound();
});

it('creates a hosted invoice while letting the gateway choose the payment coin', function (): void {
    Http::preventStrayRequests();
    Http::fake([
        'https://api.nowpayments.io/v1/invoice' => Http::response([
            'id' => 'invoice-123',
            'invoice_url' => 'https://nowpayments.test/invoice-123',
        ]),
    ]);

    $engine = Kraite::findOrFail(1);
    $engine->nowpayments_api_key = 'test-api-key';
    $engine->save();

    $plan = makeCappedPlan();
    $user = makeBillingUser([
        'subscription_id' => $plan->id,
        'wallet_balance_usdt' => 0,
    ]);

    $this->actingAs($user)
        ->post(route('billing.topup'), ['amount_usdt' => 75])
        ->assertRedirect('https://nowpayments.test/invoice-123');

    $payment = Payment::sole();

    expect($payment->pay_currency)->toBeNull();
    expect((float) $payment->price_amount)->toBe(75.0);

    Http::assertSent(fn ($request): bool => $request->url() === 'https://api.nowpayments.io/v1/invoice'
        && ! array_key_exists('pay_currency', $request->data()));
});

it('does not retry invoice creation after an ambiguous gateway failure', function (): void {
    Http::preventStrayRequests();
    Http::fake([
        'https://api.nowpayments.io/v1/invoice' => Http::sequence()
            ->pushStatus(500)
            ->push([
                'id' => 'duplicate-invoice',
                'invoice_url' => 'https://nowpayments.test/duplicate-invoice',
            ]),
    ]);

    $engine = Kraite::findOrFail(1);
    $engine->nowpayments_api_key = 'test-api-key';
    $engine->save();

    $plan = makeCappedPlan();
    $user = makeBillingUser([
        'subscription_id' => $plan->id,
        'wallet_balance_usdt' => 0,
    ]);

    $this->actingAs($user)
        ->post(route('billing.topup'), ['amount_usdt' => 75])
        ->assertRedirect(route('billing'))
        ->assertSessionHas('error');

    expect(Payment::sole()->status)->toBe(Payment::STATUS_FAILED);

    Http::assertSentCount(1);
});

it('renders the real remaining time for an active paid trial', function (): void {
    $this->travelTo(now()->startOfSecond());

    $plan = makeCappedPlan();
    $user = makeBillingUser([
        'subscription_id' => $plan->id,
        'trial_started_at' => now()->subDays(2),
        'subscription_renews_at' => now()->addDays(5),
    ]);

    $this->actingAs($user)
        ->get(route('billing'))
        ->assertOk()
        ->assertSee('\u0022view\u0022:\u0022trial\u0022', false)
        ->assertSee('\u0022trialSecs\u0022:432000', false)
        ->assertSee('\u0022renewalLabel\u0022:\u0022'.now()->addDays(5)->format('M j, Y').'\u0022', false);
});

it('renders a complimentary plan without a trial or renewal', function (): void {
    $blackPlan = Subscription::create([
        'name' => 'Black',
        'canonical' => Subscription::CANONICAL_BLACK,
        'description' => 'Invite-only plan. Free forever.',
        'monthly_rate_usdt' => 0,
        'trial_days' => 7,
        'max_accounts' => null,
        'is_active' => true,
    ]);
    $user = makeBillingUser([
        'subscription_id' => $blackPlan->id,
        'trial_started_at' => now()->subDays(2),
        'subscription_renews_at' => now()->setDate(2038, 1, 1),
    ]);

    $this->actingAs($user)
        ->get(route('billing'))
        ->assertOk()
        ->assertSee('\u0022view\u0022:\u0022complimentary\u0022', false)
        ->assertSee('\u0022trialSecs\u0022:0', false)
        ->assertDontSee('Jan 1, 2038')
        ->assertSee('Free forever. No trial, renewal date, or wallet debit.');
});

it('does not start a trial for a complimentary plan', function (): void {
    $blackPlan = Subscription::create([
        'name' => 'Black',
        'canonical' => Subscription::CANONICAL_BLACK,
        'monthly_rate_usdt' => 0,
        'trial_days' => 7,
        'max_accounts' => null,
        'is_active' => true,
    ]);
    $user = makeBillingUser([
        'subscription_id' => $blackPlan->id,
        'trial_started_at' => null,
        'subscription_renews_at' => null,
    ]);

    $this->actingAs($user)
        ->post(route('billing.start-trading'))
        ->assertRedirect(route('billing'))
        ->assertSessionHas('status', 'Black is free forever. No trial or renewal is needed.');

    $user->refresh();

    expect($user->trial_started_at)->toBeNull()
        ->and($user->subscription_renews_at)->toBeNull();
});

it('does not treat legacy complimentary timestamps as a free paid-plan trial', function (): void {
    $blackPlan = Subscription::create([
        'name' => 'Black',
        'canonical' => Subscription::CANONICAL_BLACK,
        'monthly_rate_usdt' => 0,
        'trial_days' => 7,
        'max_accounts' => null,
        'is_active' => true,
    ]);
    $paidPlan = makeCappedPlan();
    $legacyRenewal = now()->setDate(2038, 1, 1)->startOfSecond();
    $user = makeBillingUser([
        'subscription_id' => $blackPlan->id,
        'wallet_balance_usdt' => 0,
        'trial_started_at' => now()->subDays(2),
        'subscription_renews_at' => $legacyRenewal,
    ]);

    $this->actingAs($user)
        ->post(route('billing.subscription'), ['subscription_id' => $paidPlan->id])
        ->assertRedirect(route('billing'))
        ->assertSessionHas('error', 'Wallet does not cover the new plan after prorate. Top up first.');

    $user->refresh();

    expect($user->subscription_id)->toBe($blackPlan->id)
        ->and((float) $user->wallet_balance_usdt)->toBe(0.0)
        ->and($user->subscription_renews_at?->equalTo($legacyRenewal))->toBeTrue();
});
