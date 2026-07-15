<?php

use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;
use Tests\TestCase;

/*
|--------------------------------------------------------------------------
| Test Case
|--------------------------------------------------------------------------
|
| The closure you provide to your test functions is always bound to a specific PHPUnit test
| case class. By default, that class is "PHPUnit\Framework\TestCase". Of course, you may
| need to change it using the "pest()" function to bind different classes or traits.
|
*/

pest()->extend(TestCase::class)
    ->in('Feature');

/*
|--------------------------------------------------------------------------
| Expectations
|--------------------------------------------------------------------------
|
| When you're writing tests, you often need to check that values meet certain conditions. The
| "expect()" function gives you access to a set of "expectations" methods that you can use
| to assert different things. Of course, you may extend the Expectation API at any time.
|
*/

expect()->extend('toBeOne', function () {
    return $this->toBe(1);
});

/*
|--------------------------------------------------------------------------
| Functions
|--------------------------------------------------------------------------
|
| While Pest is very powerful out-of-the-box, you may have some testing code specific to your
| project that you don't want to repeat in every file. Here you can also expose helpers as
| global functions to help you to reduce the number of lines of code in your test files.
|
*/

function something()
{
    // ..
}

function prepareCoreBillingSchema(): void
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
            'can_trade' => fn () => $table->boolean('can_trade')->default(true),
        ] as $column => $add) {
            if (! Schema::hasColumn('users', $column)) {
                $add();
            }
        }
    });

    if (! Schema::hasColumn('kraite', 'nowpayments_api_key')) {
        Schema::table('kraite', function (Blueprint $table): void {
            $table->longText('nowpayments_api_key')->nullable();
            $table->longText('nowpayments_ipn_secret')->nullable();
        });
    }

    if (! Schema::hasTable('subscriptions')) {
        Schema::create('subscriptions', function (Blueprint $table): void {
            $table->id();
            $table->string('name');
            $table->string('canonical')->unique();
            $table->text('description')->nullable();
            $table->decimal('monthly_rate_usdt', 12, 4)->default(0);
            $table->unsignedSmallInteger('trial_days')->default(7);
            $table->unsignedInteger('max_accounts')->nullable();
            $table->unsignedInteger('max_exchanges')->nullable();
            $table->decimal('max_balance', 15, 2)->nullable();
            $table->boolean('is_active')->default(true);
            $table->timestamps();
        });
    }

    if (! Schema::hasTable('api_systems')) {
        Schema::create('api_systems', function (Blueprint $table): void {
            $table->id();
            $table->string('name');
            $table->string('canonical')->unique();
            $table->timestamps();
        });
    }

    if (! Schema::hasTable('accounts')) {
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
    }

    if (! Schema::hasTable('wallet_transactions')) {
        Schema::create('wallet_transactions', function (Blueprint $table): void {
            $table->id();
            $table->unsignedBigInteger('user_id');
            $table->string('type', 32);
            $table->decimal('amount_usdt', 14, 4);
            $table->decimal('balance_after', 14, 4);
            $table->string('description');
            $table->json('meta')->nullable();
            $table->timestamp('created_at')->useCurrent();
        });
    }

    if (! Schema::hasTable('payments')) {
        Schema::create('payments', function (Blueprint $table): void {
            $table->id();
            $table->unsignedBigInteger('user_id');
            $table->string('nowpayments_payment_id')->nullable()->unique();
            $table->string('order_id')->index();
            $table->string('pay_currency', 32)->nullable();
            $table->decimal('pay_amount', 24, 12)->nullable();
            $table->decimal('price_amount', 14, 4);
            $table->decimal('outcome_amount', 14, 4)->nullable();
            $table->decimal('credited_amount', 14, 4)->default(0);
            $table->string('outcome_currency', 16)->default('usdt');
            $table->string('status', 32)->default('pending');
            $table->string('invoice_url', 500)->nullable();
            $table->timestamp('credited_at')->nullable();
            $table->json('raw_payload')->nullable();
            $table->timestamps();
        });
    }

    if (! Schema::hasTable('top_up_coins')) {
        Schema::create('top_up_coins', function (Blueprint $table): void {
            $table->id();
            $table->string('canonical', 64)->unique();
            $table->string('display_name', 128);
            $table->unsignedSmallInteger('sort_order')->default(100);
            $table->boolean('is_active')->default(true);
            $table->decimal('min_amount_override', 14, 6)->nullable();
            $table->timestamps();
        });
    }

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
