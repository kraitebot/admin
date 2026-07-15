<?php

declare(strict_types=1);

use Kraite\Core\Models\Subscription;
use Kraite\Core\Models\TopUpCoin;
use Kraite\Core\Models\User;

beforeEach(function (): void {
    prepareCoreBillingSchema();
});

function systemBillingUser(array $attributes = []): User
{
    return User::create(array_merge([
        'name' => 'Billing Operator Target',
        'email' => 'system-billing-'.uniqid().'@example.test',
        'password' => bcrypt('password'),
        'status' => 'active',
        'is_active' => true,
        'wallet_balance_usdt' => 0,
    ], $attributes));
}

function systemBillingPlan(): Subscription
{
    return Subscription::create([
        'name' => 'Basic',
        'canonical' => 'basic-'.uniqid(),
        'monthly_rate_usdt' => 75,
        'trial_days' => 7,
        'max_accounts' => 1,
        'max_exchanges' => 1,
        'max_balance' => 10000,
        'is_active' => true,
    ]);
}

it('renders functional users plans and coins administration pages', function (): void {
    $admin = systemBillingUser(['is_admin' => true]);
    $plan = systemBillingPlan();
    $target = systemBillingUser(['subscription_id' => $plan->id]);

    $this->actingAs($admin)
        ->get(route('system.users', $target))
        ->assertOk()
        ->assertSee($target->email)
        ->assertSee(route('system.users.credit', $target), false)
        ->assertSee(route('system.users.subscription', $target), false)
        ->assertSee(route('system.users.start-trial', $target), false);

    $this->actingAs($admin)
        ->get(route('system.billing.plans'))
        ->assertOk()
        ->assertSee($plan->name)
        ->assertSee(route('system.billing.plans.update', $plan), false)
        ->assertSee(route('system.billing.plans.store'), false);

    $this->actingAs($admin)
        ->get(route('system.billing.coins'))
        ->assertOk()
        ->assertSee(route('system.billing.coins.store'), false)
        ->assertSee(route('system.billing.coins.engine'), false);
});

it('rejects an admin debit larger than the wallet balance without a server error', function (): void {
    $admin = systemBillingUser(['is_admin' => true]);
    $target = systemBillingUser(['wallet_balance_usdt' => 10]);

    $this->actingAs($admin)
        ->post(route('system.users.credit', $target), [
            'amount_usdt' => -25,
            'description' => 'Correction',
        ])
        ->assertRedirect(route('system.users', $target))
        ->assertSessionHas('error');

    expect((float) $target->fresh()->wallet_balance_usdt)->toBe(10.0);
});

it('creates table-driven plans and top-up coins through the operator forms', function (): void {
    $admin = systemBillingUser(['is_admin' => true]);

    $this->actingAs($admin)
        ->post(route('system.billing.plans.store'), [
            'name' => 'Professional',
            'canonical' => 'Professional',
            'monthly_rate_usdt' => 125,
            'trial_days' => 10,
            'max_accounts' => 2,
            'max_exchanges' => 2,
            'max_balance' => 25000,
            'is_active' => 1,
        ])
        ->assertRedirect(route('system.billing.plans'))
        ->assertSessionHasNoErrors();

    $plan = Subscription::where('canonical', 'professional')->sole();

    expect($plan->max_exchanges)->toBe(2);
    expect((float) $plan->monthly_rate_usdt)->toBe(125.0);

    $this->actingAs($admin)
        ->post(route('system.billing.coins.store'), [
            'display_name' => 'Bitcoin',
            'canonical' => 'BTC',
            'sort_order' => 20,
            'min_amount_override' => 0.001,
            'is_active' => 1,
        ])
        ->assertRedirect(route('system.billing.coins'))
        ->assertSessionHasNoErrors();

    $coin = TopUpCoin::where('canonical', 'btc')->sole();

    expect($coin->display_name)->toBe('Bitcoin');
    expect((float) $coin->min_amount_override)->toBe(0.001);
});

it('starts an operator-managed trial with its first renewal anchor', function (): void {
    $this->travelTo(now()->startOfSecond());

    $admin = systemBillingUser(['is_admin' => true]);
    $plan = systemBillingPlan();
    $target = systemBillingUser(['subscription_id' => $plan->id]);

    $this->actingAs($admin)
        ->post(route('system.users.start-trial', $target))
        ->assertRedirect(route('system.users', $target));

    $target->refresh();

    expect($target->trial_started_at?->equalTo(now()))->toBeTrue();
    expect($target->subscription_renews_at?->equalTo(now()->addDays(7)))->toBeTrue();
});

it('does not offer or start a trial for a complimentary plan', function (): void {
    $admin = systemBillingUser(['is_admin' => true]);
    $blackPlan = Subscription::create([
        'name' => 'Black',
        'canonical' => 'black',
        'monthly_rate_usdt' => 0,
        'trial_days' => 7,
        'max_accounts' => null,
        'is_active' => true,
    ]);
    $target = systemBillingUser([
        'subscription_id' => $blackPlan->id,
        'trial_started_at' => null,
        'subscription_renews_at' => null,
    ]);

    $this->actingAs($admin)
        ->get(route('system.users', $target))
        ->assertOk()
        ->assertSee('Free forever — no trial or renewal.')
        ->assertDontSee(route('system.users.start-trial', $target), false)
        ->assertDontSee(route('system.users.trial-days', $target), false);

    $this->actingAs($admin)
        ->post(route('system.users.start-trial', $target))
        ->assertRedirect(route('system.users', $target))
        ->assertSessionHas('error', 'Black is free forever and does not use trials.');

    $this->actingAs($admin)
        ->post(route('system.users.trial-days', $target), ['trial_days_override' => 30])
        ->assertRedirect(route('system.users', $target))
        ->assertSessionHas('error', 'Black is free forever and does not use trials.');

    $target->refresh();

    expect($target->trial_started_at)->toBeNull()
        ->and($target->trial_days_override)->toBeNull()
        ->and($target->subscription_renews_at)->toBeNull();
});
