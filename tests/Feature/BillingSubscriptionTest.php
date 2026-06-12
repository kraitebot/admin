<?php

declare(strict_types=1);

use Illuminate\Database\Schema\Blueprint;
use Illuminate\Routing\Middleware\ThrottleRequests;
use Illuminate\Support\Facades\Schema;
use Kraite\Core\Models\Account;
use Kraite\Core\Models\Subscription;
use Kraite\Core\Models\User;

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
    Schema::table('users', function (Blueprint $table): void {
        foreach ([
            'uuid' => fn () => $table->char('uuid', 36)->nullable()->unique(),
            'subscription_id' => fn () => $table->unsignedBigInteger('subscription_id')->nullable(),
            'wallet_balance_usdt' => fn () => $table->decimal('wallet_balance_usdt', 14, 4)->default(0),
            'trial_started_at' => fn () => $table->timestamp('trial_started_at')->nullable(),
            'subscription_renews_at' => fn () => $table->timestamp('subscription_renews_at')->nullable(),
            'subscription_paused_at' => fn () => $table->timestamp('subscription_paused_at')->nullable(),
            'trial_days_override' => fn () => $table->unsignedSmallInteger('trial_days_override')->nullable(),
            'active_account_id' => fn () => $table->unsignedBigInteger('active_account_id')->nullable(),
            'status' => fn () => $table->string('status', 16)->default('active'),
        ] as $column => $add) {
            if (! Schema::hasColumn('users', $column)) {
                $add();
            }
        }
    });

    Schema::create('subscriptions', function (Blueprint $table): void {
        $table->id();
        $table->string('name');
        $table->string('canonical')->unique();
        $table->decimal('monthly_rate_usdt', 12, 4)->default(0);
        $table->unsignedSmallInteger('trial_days')->default(7);
        $table->unsignedInteger('max_accounts')->nullable();
        $table->unsignedInteger('max_exchanges')->nullable();
        $table->boolean('is_active')->default(true);
        $table->timestamps();
    });

    Schema::create('accounts', function (Blueprint $table): void {
        $table->id();
        $table->uuid('uuid')->nullable();
        $table->string('name');
        $table->unsignedBigInteger('user_id');
        $table->unsignedBigInteger('api_system_id')->default(1);
        $table->unsignedBigInteger('trade_configuration_id')->default(1);
        $table->boolean('can_trade')->default(true);
        $table->boolean('is_active')->default(true);
        $table->timestamps();
        $table->softDeletes();
    });

    if (! Schema::hasTable('model_logs')) {
        Schema::create('model_logs', function (Blueprint $table): void {
            $table->id();
            $table->string('loggable_type')->nullable();
            $table->unsignedBigInteger('loggable_id')->nullable();
            $table->string('relatable_type')->nullable();
            $table->unsignedBigInteger('relatable_id')->nullable();
            $table->string('event_type')->nullable();
            $table->string('attribute_name')->nullable();
            $table->longText('previous_value')->nullable();
            $table->longText('new_value')->nullable();
            $table->text('message')->nullable();
            $table->timestamps();
        });
    }
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
