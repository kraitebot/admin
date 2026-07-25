<?php

declare(strict_types=1);

use App\Models\User;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Str;
use Kraite\Core\Jobs\Atomic\Account\TestServerConnectivityStep;
use Kraite\Core\Jobs\Lifecycles\Account\TestExchangeConnectivityStep;
use Kraite\Core\Models\Account;
use Laravel\Sanctum\Sanctum;
use StepDispatcher\Models\Step;
use StepDispatcher\States\Completed;

beforeEach(function (): void {
    prepareCoreBillingSchema();

    Schema::create('servers', function (Blueprint $table): void {
        $table->id();
        $table->string('hostname');
        $table->string('ip_address')->nullable();
        $table->boolean('is_apiable')->default(false);
        $table->boolean('needs_whitelisting')->default(false);
        $table->string('type')->nullable();
        $table->string('own_queue_name')->nullable();
        $table->text('description')->nullable();
        $table->timestamps();
    });

    Schema::create('forbidden_hostnames', function (Blueprint $table): void {
        $table->id();
        $table->unsignedBigInteger('api_system_id');
        $table->unsignedBigInteger('account_id')->nullable();
        $table->string('ip_address', 45);
        $table->string('type', 32)->default('ip_not_whitelisted');
        $table->timestamp('forbidden_until')->nullable();
        $table->string('error_code', 32)->nullable();
        $table->string('error_message')->nullable();
        $table->timestamps();
    });

    Schema::create('positions', function (Blueprint $table): void {
        $table->id();
        $table->unsignedBigInteger('account_id');
        $table->string('status');
        $table->timestamps();
    });

    Schema::table('accounts', function (Blueprint $table): void {
        $table->string('portfolio_quote')->nullable();
        $table->string('trading_quote')->nullable();
        $table->decimal('margin', 14, 4)->nullable();
        $table->string('balance_for_trading_basis')->default('total');
        $table->text('disabled_reason')->nullable();
        $table->timestamp('disabled_at')->nullable();
        $table->decimal('profit_percentage', 8, 4)->default(0);
        $table->unsignedInteger('total_limit_orders_filled_to_notify')->default(0);
        $table->decimal('stop_market_initial_percentage', 8, 4)->default(0);
        $table->boolean('override_tp')->default(false);
        $table->boolean('override_sl')->default(false);
        $table->unsignedInteger('total_positions_long')->default(0);
        $table->unsignedInteger('total_positions_short')->default(0);
        $table->unsignedInteger('position_leverage_long')->default(1);
        $table->unsignedInteger('position_leverage_short')->default(1);
        $table->decimal('margin_percentage_long', 8, 4)->default(0);
        $table->decimal('margin_percentage_short', 8, 4)->default(0);
        $table->string('margin_mode')->default('cross');
        $table->boolean('on_hedge_mode')->default(false);
        $table->boolean('allow_other_positions')->default(false);
        $table->boolean('allow_other_orders')->default(false);
        $table->text('binance_api_key')->nullable();
        $table->text('binance_api_secret')->nullable();
        $table->text('bybit_api_key')->nullable();
        $table->text('bybit_api_secret')->nullable();
        $table->text('kraken_api_key')->nullable();
        $table->text('kraken_private_key')->nullable();
        $table->text('kucoin_api_key')->nullable();
        $table->text('kucoin_api_secret')->nullable();
        $table->text('kucoin_passphrase')->nullable();
        $table->text('bitget_api_key')->nullable();
        $table->text('bitget_api_secret')->nullable();
        $table->text('bitget_passphrase')->nullable();
    });

    $migration = require base_path('vendor/brunocfalcao/step-dispatcher/database/migrations/2024_01_01_000000_create_step_dispatcher_tables.php');
    $migration->up();
});

afterEach(function (): void {
    $migration = require base_path('vendor/brunocfalcao/step-dispatcher/database/migrations/2024_01_01_000000_create_step_dispatcher_tables.php');
    $migration->down();
    Schema::dropIfExists('positions');
    Schema::dropIfExists('forbidden_hostnames');
    Schema::dropIfExists('servers');
});

function createAccountForConnectivityTest(User $user, bool $withCredentials): Account
{
    $apiSystemId = DB::table('api_systems')->where('canonical', 'binance')->value('id')
        ?? DB::table('api_systems')->insertGetId([
            'name' => 'Binance',
            'canonical' => 'binance',
            'created_at' => now(),
            'updated_at' => now(),
        ]);

    $accountId = DB::table('accounts')->insertGetId([
        'uuid' => fake()->uuid(),
        'name' => 'Connectivity Test Account '.fake()->uuid(),
        'user_id' => $user->id,
        'api_system_id' => $apiSystemId,
        'trade_configuration_id' => 1,
        'can_trade' => false,
        'is_active' => true,
        'created_at' => now(),
        'updated_at' => now(),
    ]);

    $account = Account::query()->findOrFail($accountId);

    if ($withCredentials) {
        $account->all_credentials = [
            'binance_api_key' => 'saved-connectivity-key',
            'binance_api_secret' => 'saved-connectivity-secret',
        ];
        $account->save();
    }

    return $account->refresh();
}

/**
 * @return array<string, mixed>
 */
function validAccountConfigurationPayload(Account $account, array $overrides = []): array
{
    return array_merge([
        'account_id' => $account->id,
        'name' => $account->name,
        'portfolio_quote' => $account->portfolio_quote ?: 'USDT',
        'trading_quote' => $account->trading_quote ?: 'USDT',
        'can_trade' => (bool) $account->can_trade,
        'profit_percentage' => '0.360',
        'stop_market_initial_percentage' => '2.50',
        'total_positions_long' => 4,
        'total_positions_short' => 4,
        'position_leverage_long' => 10,
        'position_leverage_short' => 10,
        'margin_percentage_long' => '4.00',
        'margin_percentage_short' => '4.00',
    ], $overrides);
}

it('returns only trader-owned account configuration to the mobile app', function (): void {
    $subscriptionId = DB::table('subscriptions')->insertGetId([
        'name' => 'Mobile accounts plan',
        'canonical' => 'mobile-accounts-plan',
        'monthly_rate_usdt' => 0,
        'trial_days' => 0,
        'is_active' => true,
        'created_at' => now(),
        'updated_at' => now(),
    ]);
    $owner = User::factory()->create(['subscription_id' => $subscriptionId]);
    $otherTrader = User::factory()->create();
    $account = createAccountForConnectivityTest($owner, true);
    $account->forceFill([
        'name' => 'Main mobile account',
        'profit_percentage' => '0.380',
        'stop_market_initial_percentage' => '5.00',
        'total_positions_long' => 5,
        'total_positions_short' => 4,
        'position_leverage_long' => 15,
        'position_leverage_short' => 10,
        'margin_percentage_long' => '5.00',
        'margin_percentage_short' => '4.00',
    ])->save();

    createAccountForConnectivityTest($otherTrader, true);
    $draft = createAccountForConnectivityTest($owner, true);
    $draft->forceFill(['name' => 'Connection test for account '.$account->id])->save();

    DB::table('servers')->insert([
        'hostname' => 'mobile-worker',
        'ip_address' => '203.0.113.30',
        'is_apiable' => true,
        'needs_whitelisting' => true,
        'type' => 'worker',
    ]);

    Sanctum::actingAs($owner, ['dashboard:read']);

    $this->getJson('https://api.kraite.com/v1/accounts')
        ->assertSuccessful()
        ->assertJsonCount(1, 'data.accounts')
        ->assertJsonPath('data.accounts.0.id', $account->id)
        ->assertJsonPath('data.accounts.0.name', 'Main mobile account')
        ->assertJsonPath('data.accounts.0.profit_percentage', '0.380')
        ->assertJsonPath('data.accounts.0.stop_market_initial_percentage', '5.00')
        ->assertJsonPath('data.accounts.0.connectivity_health.kind', 'healthy')
        ->assertJsonPath('data.accounts.0.configuration_locked', false)
        ->assertJsonPath('data.accounts.0.quotes_locked', false)
        ->assertJsonPath('data.accounts.0.can_enable_trading', true)
        ->assertJsonPath('data.options.position_leverage', [10, 15, 20]);
});

it('requires explicit mobile write access and preserves trader ownership', function (): void {
    $owner = User::factory()->create();
    $otherTrader = User::factory()->create();
    $account = createAccountForConnectivityTest($owner, true);
    $foreignAccount = createAccountForConnectivityTest($otherTrader, true);
    $payload = validAccountConfigurationPayload($account, [
        'name' => 'Updated from iPhone',
        'position_leverage_long' => 15,
    ]);

    Sanctum::actingAs($owner, ['dashboard:read']);
    $this->patchJson('https://api.kraite.com/v1/accounts', $payload)
        ->assertForbidden();

    Sanctum::actingAs($owner, ['dashboard:read', 'accounts:write']);
    $this->patchJson('https://api.kraite.com/v1/accounts', $payload)
        ->assertSuccessful()
        ->assertJsonPath('account.id', $account->id);

    expect($account->refresh())
        ->name->toBe('Updated from iPhone')
        ->position_leverage_long->toBe(15);

    $this->patchJson(
        'https://api.kraite.com/v1/accounts',
        validAccountConfigurationPayload($foreignAccount),
    )->assertForbidden();
});

it('keeps mobile account access owner-scoped for administrators', function (): void {
    $administrator = User::factory()->create(['is_admin' => true]);
    $otherTrader = User::factory()->create();
    $ownedAccount = createAccountForConnectivityTest($administrator, true);
    $foreignAccount = createAccountForConnectivityTest($otherTrader, true);

    Sanctum::actingAs($administrator, ['dashboard:read', 'accounts:write']);

    $this->getJson('https://api.kraite.com/v1/accounts')
        ->assertSuccessful()
        ->assertJsonCount(1, 'data.accounts')
        ->assertJsonPath('data.accounts.0.id', $ownedAccount->id);

    $this->patchJson(
        'https://api.kraite.com/v1/accounts',
        validAccountConfigurationPayload($foreignAccount),
    )->assertNotFound();
});

it('serves owned quote assets to the mobile app behind the write ability', function (): void {
    $owner = User::factory()->create();
    $otherTrader = User::factory()->create();
    $account = createAccountForConnectivityTest($owner, true);
    $foreignAccount = createAccountForConnectivityTest($otherTrader, true);

    Cache::put("account.{$account->id}.available-quotes", ['USDT', 'BFUSD'], 60);
    Cache::put("account.{$foreignAccount->id}.available-quotes", ['USDC'], 60);

    Sanctum::actingAs($owner, ['dashboard:read']);
    $this->getJson("https://api.kraite.com/v1/accounts/quotes?account_id={$account->id}")
        ->assertForbidden();

    Sanctum::actingAs($owner, ['dashboard:read', 'accounts:write']);
    $this->getJson("https://api.kraite.com/v1/accounts/quotes?account_id={$account->id}")
        ->assertSuccessful()
        ->assertExactJson([
            'account_id' => $account->id,
            'assets' => ['USDT', 'BFUSD'],
        ]);

    $this->getJson("https://api.kraite.com/v1/accounts/quotes?account_id={$foreignAccount->id}")
        ->assertNotFound();
});

it('stops new mobile trading without touching other saved configuration', function (): void {
    $owner = User::factory()->create();
    $otherTrader = User::factory()->create();
    $account = createAccountForConnectivityTest($owner, true);
    $foreignAccount = createAccountForConnectivityTest($otherTrader, true);
    $account->forceFill([
        'can_trade' => true,
        'name' => 'Stored mobile name',
        'profit_percentage' => '0.4000',
        'position_leverage_long' => 20,
    ])->save();

    Sanctum::actingAs($owner, ['dashboard:read']);
    $this->patchJson('https://api.kraite.com/v1/accounts/trading/disable', [
        'account_id' => $account->id,
    ])->assertForbidden();

    expect($account->refresh()->can_trade)->toBeTrue();

    Sanctum::actingAs($owner, ['dashboard:read', 'accounts:write']);
    $this->patchJson('https://api.kraite.com/v1/accounts/trading/disable', [
        'account_id' => $account->id,
    ])
        ->assertSuccessful()
        ->assertJsonPath('account.id', $account->id)
        ->assertJsonPath('account.can_trade', false);

    expect($account->refresh())
        ->can_trade->toBeFalse()
        ->name->toBe('Stored mobile name')
        ->profit_percentage->toBe(0.4)
        ->position_leverage_long->toBe(20);

    $this->patchJson('https://api.kraite.com/v1/accounts/trading/disable', [
        'account_id' => $foreignAccount->id,
    ])->assertNotFound();
});

it('turns trading off immediately without saving other edited configuration', function (): void {
    $user = User::factory()->create([
        'name' => 'Immediate Stop Owner',
        'email' => 'immediate-stop-owner@kraite.test',
    ]);
    $account = createAccountForConnectivityTest($user, true);
    $account->forceFill([
        'can_trade' => true,
        'name' => 'Stored account name',
        'profit_percentage' => '0.3600',
        'position_leverage_long' => 20,
    ])->save();

    $this->actingAs($user)
        ->patchJson('https://admin.kraite.test/accounts/trading/disable', [
            'account_id' => $account->id,
        ])
        ->assertSuccessful()
        ->assertJsonPath('account.id', $account->id)
        ->assertJsonPath('account.can_trade', false);

    expect($account->refresh())
        ->can_trade->toBeFalse()
        ->name->toBe('Stored account name')
        ->profit_percentage->toBe(0.36)
        ->position_leverage_long->toBe(20);
});

it('cannot turn trading off for another traders account', function (): void {
    $owner = User::factory()->create();
    $otherTrader = User::factory()->create();
    $account = createAccountForConnectivityTest($owner, true);
    $account->forceFill(['can_trade' => true])->save();

    $this->actingAs($otherTrader)
        ->patchJson('https://admin.kraite.test/accounts/trading/disable', [
            'account_id' => $account->id,
        ])
        ->assertNotFound();

    expect($account->refresh()->can_trade)->toBeTrue();
});

it('wires the trading off toggle to the immediate disable endpoint', function (): void {
    expect(file_get_contents(resource_path('js/app.js')))
        ->toContain('window.hubUiFetch(init.urls.disableTrading')
        ->toContain('await this.disableTrading();');

    expect(file_get_contents(resource_path('views/accounts/edit.blade.php')))
        ->toContain("'disableTrading' => route('accounts.trading.disable')")
        ->toContain('checkedExpr="cfg.canTrade"')
        ->not->toContain('checkedExpr="tradingActive()"');
});

it('passes only live API connectivity servers to the accounts page', function (): void {
    DB::table('servers')->insert([
        ['hostname' => 'test-athena', 'ip_address' => '203.0.113.10', 'is_apiable' => true, 'needs_whitelisting' => true, 'type' => 'ingestion'],
        ['hostname' => 'test-eos', 'ip_address' => '203.0.113.11', 'is_apiable' => true, 'needs_whitelisting' => true, 'type' => 'worker'],
        ['hostname' => 'test-pheme', 'ip_address' => '203.0.113.12', 'is_apiable' => false, 'needs_whitelisting' => false, 'type' => 'web'],
        ['hostname' => 'test-no-ip', 'ip_address' => null, 'is_apiable' => true, 'needs_whitelisting' => true, 'type' => 'worker'],
    ]);

    $user = User::factory()->create([
        'name' => 'Connectivity Owner',
        'email' => 'accounts-connectivity-owner@kraite.test',
    ]);

    $this->actingAs($user)
        ->get('https://admin.kraite.test/accounts/edit')
        ->assertSuccessful()
        ->assertViewHas('connectivityServers', [
            ['id' => 1, 'hostname' => 'test-athena', 'ip_address' => '203.0.113.10'],
            ['id' => 2, 'hostname' => 'test-eos', 'ip_address' => '203.0.113.11'],
        ]);
});

it('explains how runtime overrides apply to saved trading configuration', function (): void {
    $user = User::factory()->create([
        'name' => 'Configuration Override Owner',
        'email' => 'configuration-override-owner@kraite.test',
    ]);
    createAccountForConnectivityTest($user, true);

    $response = $this->actingAs($user)
        ->get('https://admin.kraite.test/accounts/edit')
        ->assertSuccessful()
        ->assertSee('Runtime overrides may apply')
        ->assertSee('How overrides work')
        ->assertSee('BSCS does not change profit target or stop-loss.', false)
        ->assertSee('Existing positions', false);

    $document = new DOMDocument;
    $document->loadHTML((string) $response->getContent(), LIBXML_NOERROR | LIBXML_NOWARNING);
    $xpath = new DOMXPath($document);
    $trigger = $xpath->query('//*[@data-config-overrides-help]')->item(0);

    expect($trigger)->not->toBeNull()
        ->and($trigger->getAttribute('x-on:click.stop'))->toContain('$store.help.showInline');
});

it('serializes inactive subscription state and only currently open positions', function (): void {
    $subscriptionId = DB::table('subscriptions')->insertGetId([
        'name' => 'Expired test plan',
        'canonical' => 'expired-test-plan',
        'monthly_rate_usdt' => 25,
        'trial_days' => 7,
        'is_active' => true,
        'created_at' => now(),
        'updated_at' => now(),
    ]);
    $user = User::factory()->create([
        'name' => 'Expired Subscription Owner',
        'email' => 'expired-subscription-owner@kraite.test',
    ]);
    DB::table('users')->where('id', $user->id)->update([
        'subscription_id' => $subscriptionId,
        'subscription_renews_at' => now()->subMinute(),
    ]);
    $account = createAccountForConnectivityTest($user, true);

    DB::table('positions')->insert([
        ['account_id' => $account->id, 'status' => 'active', 'created_at' => now(), 'updated_at' => now()],
        ['account_id' => $account->id, 'status' => 'closing', 'created_at' => now(), 'updated_at' => now()],
        ['account_id' => $account->id, 'status' => 'closed', 'created_at' => now(), 'updated_at' => now()],
    ]);

    $this->actingAs($user)
        ->get('https://admin.kraite.test/accounts/edit')
        ->assertSuccessful()
        ->assertViewHas('accounts', function (array $accounts) use ($account): bool {
            $serialized = collect($accounts)->firstWhere('id', $account->id);

            return $serialized['subscription_active'] === false
                && $serialized['open_positions_count'] === 2;
        });
});

it('serializes the engine subscription gate for every billing state', function (string $state, bool $expectedActive): void {
    $user = User::factory()->create([
        'name' => "Subscription State {$state}",
        'email' => "subscription-state-{$state}@kraite.test",
    ]);

    if ($state !== 'absent') {
        $subscriptionId = DB::table('subscriptions')->insertGetId([
            'name' => "Subscription {$state}",
            'canonical' => "subscription-{$state}",
            'monthly_rate_usdt' => $state === 'expired' ? 25 : 0,
            'trial_days' => 0,
            'is_active' => true,
            'created_at' => now(),
            'updated_at' => now(),
        ]);
        DB::table('users')->where('id', $user->id)->update([
            'subscription_id' => $subscriptionId,
            'subscription_renews_at' => $state === 'expired' ? now()->subMinute() : null,
            'subscription_paused_at' => $state === 'paused' ? now() : null,
        ]);
    }

    $account = createAccountForConnectivityTest($user, true);
    $account->forceFill(['can_trade' => true])->save();

    $this->actingAs($user)
        ->get('https://admin.kraite.test/accounts/edit')
        ->assertSuccessful()
        ->assertViewHas('accounts', function (array $accounts) use ($account, $expectedActive): bool {
            $serialized = collect($accounts)->firstWhere('id', $account->id);

            return $serialized['subscription_active'] === $expectedActive;
        });
})->with([
    'no subscription' => ['absent', false],
    'paused subscription' => ['paused', false],
    'expired subscription' => ['expired', false],
    'active free subscription' => ['active', true],
]);

it('rejects quote changes while preserving every field when an open position exists', function (): void {
    $subscriptionId = DB::table('subscriptions')->insertGetId([
        'name' => 'Active test plan',
        'canonical' => 'active-test-plan',
        'monthly_rate_usdt' => 0,
        'trial_days' => 0,
        'is_active' => true,
        'created_at' => now(),
        'updated_at' => now(),
    ]);
    $user = User::factory()->create([
        'name' => 'Quote Lock Owner',
        'email' => 'quote-lock-owner@kraite.test',
    ]);
    DB::table('users')->where('id', $user->id)->update(['subscription_id' => $subscriptionId]);
    $account = createAccountForConnectivityTest($user, false);
    $account->forceFill([
        'name' => 'Quote Lock Account',
        'portfolio_quote' => 'USDT',
        'trading_quote' => 'USDT',
    ])->save();
    DB::table('positions')->insert([
        'account_id' => $account->id,
        'status' => 'active',
        'created_at' => now(),
        'updated_at' => now(),
    ]);

    expect($account->fresh()->name)->toBe('Quote Lock Account')
        ->and($account->portfolio_quote)->toBe('USDT')
        ->and($account->trading_quote)->toBe('USDT');

    $this->actingAs($user)
        ->patchJson('https://admin.kraite.test/accounts/edit', validAccountConfigurationPayload($account, [
            'name' => 'Must Not Be Saved',
            'portfolio_quote' => 'USDC',
            'trading_quote' => 'USDC',
        ]))
        ->assertUnprocessable()
        ->assertJsonValidationErrors(['portfolio_quote', 'trading_quote']);

    $account->refresh();
    expect($account->name)->toBe('Quote Lock Account')
        ->and($account->portfolio_quote)->toBe('USDT')
        ->and($account->trading_quote)->toBe('USDT');
});

it('allows non-quote configuration changes with open positions and quote changes after closure', function (): void {
    $subscriptionId = DB::table('subscriptions')->insertGetId([
        'name' => 'Editable test plan',
        'canonical' => 'editable-test-plan',
        'monthly_rate_usdt' => 0,
        'trial_days' => 0,
        'is_active' => true,
        'created_at' => now(),
        'updated_at' => now(),
    ]);
    $user = User::factory()->create([
        'name' => 'Editable Account Owner',
        'email' => 'editable-account-owner@kraite.test',
    ]);
    DB::table('users')->where('id', $user->id)->update(['subscription_id' => $subscriptionId]);
    $account = createAccountForConnectivityTest($user, false);
    $account->forceFill([
        'name' => 'Before Rename',
        'portfolio_quote' => 'USDT',
        'trading_quote' => 'USDT',
    ])->save();
    $positionId = DB::table('positions')->insertGetId([
        'account_id' => $account->id,
        'status' => 'closing',
        'created_at' => now(),
        'updated_at' => now(),
    ]);

    $this->actingAs($user)
        ->patchJson('https://admin.kraite.test/accounts/edit', validAccountConfigurationPayload($account, [
            'name' => 'Renamed While Open',
        ]))
        ->assertSuccessful();

    $account->refresh();
    expect($account->name)->toBe('Renamed While Open')
        ->and($account->portfolio_quote)->toBe('USDT')
        ->and($account->trading_quote)->toBe('USDT');

    DB::table('positions')->where('id', $positionId)->update(['status' => 'closed']);

    $this->actingAs($user)
        ->patchJson('https://admin.kraite.test/accounts/edit', validAccountConfigurationPayload($account, [
            'portfolio_quote' => 'USDC',
            'trading_quote' => 'USDC',
        ]))
        ->assertSuccessful();

    $account->refresh();
    expect($account->portfolio_quote)->toBe('USDC')
        ->and($account->trading_quote)->toBe('USDC');
});

it('rejects quote changes while account trading remains enabled', function (): void {
    $subscriptionId = DB::table('subscriptions')->insertGetId([
        'name' => 'Trading quote lock plan',
        'canonical' => 'trading-quote-lock-plan',
        'monthly_rate_usdt' => 0,
        'trial_days' => 0,
        'is_active' => true,
        'created_at' => now(),
        'updated_at' => now(),
    ]);
    $user = User::factory()->create([
        'name' => 'Trading Quote Lock Owner',
        'email' => 'trading-quote-lock-owner@kraite.test',
    ]);
    DB::table('users')->where('id', $user->id)->update(['subscription_id' => $subscriptionId]);
    $account = createAccountForConnectivityTest($user, false);
    $account->forceFill([
        'can_trade' => true,
        'portfolio_quote' => 'USDT',
        'trading_quote' => 'USDT',
    ])->save();

    $this->actingAs($user)
        ->patchJson('https://admin.kraite.test/accounts/edit', validAccountConfigurationPayload($account, [
            'portfolio_quote' => 'USDC',
            'trading_quote' => 'USDC',
        ]))
        ->assertUnprocessable()
        ->assertJsonValidationErrors(['portfolio_quote', 'trading_quote']);

    $account->refresh();
    expect($account->can_trade)->toBeTrue()
        ->and($account->portfolio_quote)->toBe('USDT')
        ->and($account->trading_quote)->toBe('USDT');
});

it('allows turning trading off and changing quotes in one save without open positions', function (): void {
    $subscriptionId = DB::table('subscriptions')->insertGetId([
        'name' => 'Quote unlock plan',
        'canonical' => 'quote-unlock-plan',
        'monthly_rate_usdt' => 0,
        'trial_days' => 0,
        'is_active' => true,
        'created_at' => now(),
        'updated_at' => now(),
    ]);
    $user = User::factory()->create([
        'name' => 'Quote Unlock Owner',
        'email' => 'quote-unlock-owner@kraite.test',
    ]);
    DB::table('users')->where('id', $user->id)->update(['subscription_id' => $subscriptionId]);
    $account = createAccountForConnectivityTest($user, false);
    $account->forceFill([
        'can_trade' => true,
        'portfolio_quote' => 'USDT',
        'trading_quote' => 'USDT',
    ])->save();

    $this->actingAs($user)
        ->patchJson('https://admin.kraite.test/accounts/edit', validAccountConfigurationPayload($account, [
            'can_trade' => false,
            'portfolio_quote' => 'USDC',
            'trading_quote' => 'USDC',
        ]))
        ->assertSuccessful();

    $account->refresh();
    expect($account->can_trade)->toBeFalse()
        ->and($account->portfolio_quote)->toBe('USDC')
        ->and($account->trading_quote)->toBe('USDC');
});

it('rejects enabling account trading while the subscription is inactive', function (): void {
    $subscriptionId = DB::table('subscriptions')->insertGetId([
        'name' => 'Inactive test plan',
        'canonical' => 'inactive-test-plan',
        'monthly_rate_usdt' => 25,
        'trial_days' => 7,
        'is_active' => true,
        'created_at' => now(),
        'updated_at' => now(),
    ]);
    $user = User::factory()->create([
        'name' => 'Inactive Subscription Owner',
        'email' => 'inactive-subscription-owner@kraite.test',
    ]);
    DB::table('users')->where('id', $user->id)->update([
        'subscription_id' => $subscriptionId,
        'subscription_renews_at' => now()->subMinute(),
    ]);
    $account = createAccountForConnectivityTest($user, false);
    $account->forceFill([
        'name' => 'Inactive Subscription Account',
        'portfolio_quote' => 'USDT',
        'trading_quote' => 'USDT',
        'can_trade' => false,
    ])->save();

    expect($account->fresh()->can_trade)->toBeFalse()
        ->and($account->name)->toBe('Inactive Subscription Account');

    $this->actingAs($user)
        ->patchJson('https://admin.kraite.test/accounts/edit', validAccountConfigurationPayload($account, [
            'name' => 'Must Not Be Saved',
            'can_trade' => true,
        ]))
        ->assertUnprocessable()
        ->assertJsonValidationErrors('can_trade');

    $account->refresh();
    expect($account->can_trade)->toBeFalse()
        ->and($account->name)->toBe('Inactive Subscription Account');
});

it('reports healthy connectivity when no eligible server has an active block', function (): void {
    DB::table('servers')->insert([
        ['hostname' => 'test-healthy-a', 'ip_address' => '203.0.113.20', 'is_apiable' => true, 'needs_whitelisting' => true, 'type' => 'worker'],
        ['hostname' => 'test-healthy-b', 'ip_address' => '203.0.113.21', 'is_apiable' => true, 'needs_whitelisting' => true, 'type' => 'worker'],
    ]);

    $user = User::factory()->create([
        'name' => 'Healthy Connectivity Owner',
        'email' => 'healthy-connectivity-owner@kraite.test',
    ]);
    $account = createAccountForConnectivityTest($user, true);
    $account->forceFill(['can_trade' => true])->save();

    $this->actingAs($user)
        ->get('https://admin.kraite.test/accounts/edit')
        ->assertSuccessful()
        ->assertViewHas('accounts', function (array $accounts) use ($account): bool {
            $serialized = collect($accounts)->firstWhere('id', $account->id);

            return $serialized['connectivity_health'] === [
                'kind' => 'healthy',
                'label' => 'No active server blocks',
                'blocked_servers' => 0,
                'total_servers' => 2,
            ];
        });
});

it('reports degraded connectivity for active blocks and ignores expired or retired server blocks', function (): void {
    DB::table('servers')->insert([
        ['hostname' => 'test-degraded-a', 'ip_address' => '203.0.113.30', 'is_apiable' => true, 'needs_whitelisting' => true, 'type' => 'worker'],
        ['hostname' => 'test-degraded-b', 'ip_address' => '203.0.113.31', 'is_apiable' => true, 'needs_whitelisting' => true, 'type' => 'worker'],
    ]);

    $user = User::factory()->create([
        'name' => 'Degraded Connectivity Owner',
        'email' => 'degraded-connectivity-owner@kraite.test',
    ]);
    $account = createAccountForConnectivityTest($user, true);

    DB::table('forbidden_hostnames')->insert([
        [
            'api_system_id' => $account->api_system_id,
            'account_id' => $account->id,
            'ip_address' => '203.0.113.30',
            'type' => 'ip_not_whitelisted',
            'forbidden_until' => null,
            'created_at' => now(),
            'updated_at' => now(),
        ],
        [
            'api_system_id' => $account->api_system_id,
            'account_id' => $account->id,
            'ip_address' => '203.0.113.31',
            'type' => 'ip_rate_limited',
            'forbidden_until' => now()->subMinute(),
            'created_at' => now(),
            'updated_at' => now(),
        ],
        [
            'api_system_id' => $account->api_system_id,
            'account_id' => $account->id,
            'ip_address' => '203.0.113.99',
            'type' => 'ip_not_whitelisted',
            'forbidden_until' => null,
            'created_at' => now(),
            'updated_at' => now(),
        ],
    ]);

    $this->actingAs($user)
        ->get('https://admin.kraite.test/accounts/edit')
        ->assertSuccessful()
        ->assertViewHas('accounts', function (array $accounts) use ($account): bool {
            $serialized = collect($accounts)->firstWhere('id', $account->id);

            return $serialized['connectivity_health'] === [
                'kind' => 'degraded',
                'label' => '1 of 2 servers blocked',
                'blocked_servers' => 1,
                'total_servers' => 2,
            ];
        });
});

it('reports critical connectivity only when the engine auto-stopped the account', function (): void {
    DB::table('servers')->insert([
        ['hostname' => 'test-critical-a', 'ip_address' => '203.0.113.40', 'is_apiable' => true, 'needs_whitelisting' => true, 'type' => 'worker'],
        ['hostname' => 'test-critical-b', 'ip_address' => '203.0.113.41', 'is_apiable' => true, 'needs_whitelisting' => true, 'type' => 'worker'],
    ]);

    $user = User::factory()->create([
        'name' => 'Critical Connectivity Owner',
        'email' => 'critical-connectivity-owner@kraite.test',
    ]);
    $account = createAccountForConnectivityTest($user, true);
    $account->forceFill([
        'is_active' => false,
        'can_trade' => true,
        'disabled_reason' => 'All worker IPs blacklisted on Binance — fix whitelist/API key and reactivate',
        'disabled_at' => now(),
    ])->save();

    $this->actingAs($user)
        ->get('https://admin.kraite.test/accounts/edit')
        ->assertSuccessful()
        ->assertViewHas('accounts', function (array $accounts) use ($account): bool {
            $serialized = collect($accounts)->firstWhere('id', $account->id);

            return $serialized['connectivity_health'] === [
                'kind' => 'critical',
                'label' => 'Trading stopped: no safe server route',
                'blocked_servers' => 0,
                'total_servers' => 2,
            ];
        });
});

it('renders the supplied server roster without placeholder connectivity results', function (): void {
    $html = view('accounts.edit', [
        'accounts' => [[
            'id' => 42,
            'exchange' => 'Binance',
            'exchange_canonical' => 'binance',
            'owner' => 'Connectivity Owner',
            'is_active' => true,
            'disabled_reason' => null,
            'disabled_at' => null,
            'has_credentials' => true,
            'requires_passphrase' => false,
            'connectivity_health' => [
                'kind' => 'healthy',
                'label' => 'No active server blocks',
                'blocked_servers' => 0,
                'total_servers' => 1,
            ],
            'name' => 'Test Binance Account',
            'portfolio_quote' => 'USDT',
            'trading_quote' => 'USDT',
            'can_trade' => true,
            'profit_percentage' => 0.36,
            'stop_market_initial_percentage' => 2.5,
            'total_positions_long' => 4,
            'total_positions_short' => 4,
            'position_leverage_long' => 10,
            'position_leverage_short' => 10,
            'margin_percentage_long' => 4,
            'margin_percentage_short' => 4,
        ]],
        'connectivityServers' => [[
            'id' => 42,
            'hostname' => 'test-eos-42',
            'ip_address' => '203.0.113.42',
        ]],
        'isAdmin' => false,
    ])->render();

    expect($html)
        ->toContain('test-eos-42')
        ->toContain('203.0.113.42')
        ->not->toContain('51.158.10.21')
        ->not->toContain('kx_live_8f3a')
        ->not->toContain('mock behavior');
});

it('starts a real per-server workflow with saved credentials when replacement fields are blank', function (): void {
    $serverId = DB::table('servers')->insertGetId([
        'hostname' => 'test-eos-saved',
        'ip_address' => '203.0.113.50',
        'is_apiable' => true,
        'needs_whitelisting' => true,
        'type' => 'worker',
        'own_queue_name' => 'test-eos-saved',
        'created_at' => now(),
        'updated_at' => now(),
    ]);

    $user = User::factory()->create([
        'name' => 'Saved Credentials Owner',
        'email' => 'saved-connectivity-owner@kraite.test',
    ]);
    $account = createAccountForConnectivityTest($user, true);
    $credentialsBefore = $account->all_credentials;
    $account->forceFill([
        'is_active' => false,
        'can_trade' => true,
        'disabled_reason' => 'All worker IPs blacklisted on Binance — fix whitelist/API key and reactivate',
        'disabled_at' => now(),
    ])->save();

    $otherUser = User::factory()->create([
        'name' => 'Unrelated Connectivity Owner',
        'email' => 'unrelated-connectivity-owner@kraite.test',
    ]);
    $otherAccount = createAccountForConnectivityTest($otherUser, true);

    DB::table('forbidden_hostnames')->insert([
        [
            'api_system_id' => $account->api_system_id,
            'account_id' => $account->id,
            'ip_address' => '203.0.113.50',
            'type' => 'ip_not_whitelisted',
            'created_at' => now(),
            'updated_at' => now(),
        ],
        [
            'api_system_id' => $otherAccount->api_system_id,
            'account_id' => $otherAccount->id,
            'ip_address' => '203.0.113.50',
            'type' => 'ip_not_whitelisted',
            'created_at' => now(),
            'updated_at' => now(),
        ],
    ]);

    expect($account->is_active)->toBeFalse()
        ->and($account->can_trade)->toBeTrue()
        ->and(DB::table('forbidden_hostnames')->where('account_id', $account->id)->exists())->toBeTrue()
        ->and(DB::table('forbidden_hostnames')->where('account_id', $otherAccount->id)->exists())->toBeTrue();

    $response = $this->actingAs($user)
        ->postJson('https://admin.kraite.test/accounts/connectivity/test', [
            'account_id' => $account->id,
        ])
        ->assertSuccessful()
        ->assertJsonPath('credentials_mode', 'saved')
        ->assertJsonPath('total_servers', 1)
        ->assertJsonPath('servers.0.hostname', 'test-eos-saved')
        ->assertJsonPath('servers.0.ip_address', '203.0.113.50')
        ->assertJsonPath('servers.0.status', 'testing');

    $step = Step::query()->where('block_uuid', $response->json('block_uuid'))->sole();
    $testedAccount = Account::withTrashed()->findOrFail($step->relatable_id);

    expect($step->class)->toBe(TestExchangeConnectivityStep::class)
        ->and($testedAccount->id)->not->toBe($account->id)
        ->and($testedAccount->all_credentials)->toBe($credentialsBefore)
        ->and($account->refresh()->all_credentials)->toBe($credentialsBefore)
        ->and(Account::query()->where('user_id', $user->id)->where('name', 'like', 'Connection test for account %')->exists())->toBeTrue();

    $childBlockUuid = (string) Str::uuid();
    DB::table('steps')->where('id', $step->id)->update([
        'state' => Completed::class,
        'child_block_uuid' => $childBlockUuid,
        'completed_at' => now(),
    ]);
    Step::query()->create([
        'block_uuid' => $childBlockUuid,
        'class' => TestServerConnectivityStep::class,
        'state' => Completed::class,
        'queue' => 'test-eos-saved',
        'relatable_type' => Account::class,
        'relatable_id' => $testedAccount->id,
        'arguments' => [
            'accountId' => $testedAccount->id,
            'serverId' => $serverId,
        ],
        'index' => 1,
        'completed_at' => now(),
    ]);

    $this->actingAs($user)
        ->patchJson('https://admin.kraite.test/accounts/connectivity/credentials', [
            'account_id' => $account->id,
            'tested_block_uuid' => $response->json('block_uuid'),
        ])
        ->assertSuccessful()
        ->assertJsonPath('message', 'Connectivity result applied. The connection is ready, but an inactive subscription prevents new positions.')
        ->assertJsonPath('account.can_trade', true)
        ->assertJsonPath('account.is_active', true)
        ->assertJsonPath('account.connectivity_health.kind', 'healthy');

    expect($account->refresh()->all_credentials)->toBe($credentialsBefore)
        ->and($account->can_trade)->toBeTrue()
        ->and($account->is_active)->toBeTrue()
        ->and($account->disabled_reason)->toBeNull()
        ->and(DB::table('forbidden_hostnames')->where('account_id', $account->id)->exists())->toBeFalse()
        ->and(DB::table('forbidden_hostnames')->where('account_id', $otherAccount->id)->exists())->toBeTrue()
        ->and(Account::withTrashed()->find($testedAccount->id))->toBeNull();
});

it('rejects a saved-credential result when the account credentials changed during the test', function (): void {
    $serverId = DB::table('servers')->insertGetId([
        'hostname' => 'test-eos-stale-saved',
        'ip_address' => '203.0.113.51',
        'is_apiable' => true,
        'needs_whitelisting' => true,
        'type' => 'worker',
        'own_queue_name' => 'test-eos-stale-saved',
        'created_at' => now(),
        'updated_at' => now(),
    ]);

    $user = User::factory()->create([
        'name' => 'Stale Saved Credentials Owner',
        'email' => 'stale-saved-connectivity-owner@kraite.test',
    ]);
    $account = createAccountForConnectivityTest($user, true);

    $response = $this->actingAs($user)
        ->postJson('https://admin.kraite.test/accounts/connectivity/test', [
            'account_id' => $account->id,
        ])
        ->assertSuccessful()
        ->assertJsonPath('credentials_mode', 'saved');

    $parent = Step::query()->where('block_uuid', $response->json('block_uuid'))->sole();
    $testedAccountId = (int) $parent->relatable_id;

    $account->all_credentials = [
        'binance_api_key' => 'rotated-connectivity-key',
        'binance_api_secret' => 'rotated-connectivity-secret',
    ];
    $account->save();

    $childBlockUuid = (string) Str::uuid();
    DB::table('steps')->where('id', $parent->id)->update([
        'state' => Completed::class,
        'child_block_uuid' => $childBlockUuid,
        'completed_at' => now(),
    ]);
    Step::query()->create([
        'block_uuid' => $childBlockUuid,
        'class' => TestServerConnectivityStep::class,
        'state' => Completed::class,
        'queue' => 'test-eos-stale-saved',
        'relatable_type' => Account::class,
        'relatable_id' => $testedAccountId,
        'arguments' => [
            'accountId' => $testedAccountId,
            'serverId' => $serverId,
        ],
        'index' => 1,
        'completed_at' => now(),
    ]);

    $this->actingAs($user)
        ->patchJson('https://admin.kraite.test/accounts/connectivity/credentials', [
            'account_id' => $account->id,
            'tested_block_uuid' => $response->json('block_uuid'),
        ])
        ->assertUnprocessable()
        ->assertJsonPath('message', 'The saved API credentials changed during the test. Test them again.');

    expect($account->refresh()->can_trade)->toBeFalse()
        ->and($account->binance_api_key)->toBe('rotated-connectivity-key')
        ->and($account->binance_api_secret)->toBe('rotated-connectivity-secret');
});

it('rejects a saved-credential test when the account has no saved credentials', function (): void {
    $user = User::factory()->create([
        'name' => 'Empty Credentials Owner',
        'email' => 'empty-connectivity-owner@kraite.test',
    ]);
    $account = createAccountForConnectivityTest($user, false);

    $this->actingAs($user)
        ->postJson('https://admin.kraite.test/accounts/connectivity/test', [
            'account_id' => $account->id,
        ])
        ->assertUnprocessable()
        ->assertJsonPath('message', 'Enter API credentials before testing this account.');

    expect(Step::query()->where('relatable_id', $account->id)->exists())->toBeFalse();
});

it('rejects partial replacement credentials instead of testing the saved pair', function (): void {
    $user = User::factory()->create([
        'name' => 'Partial Credentials Owner',
        'email' => 'partial-connectivity-owner@kraite.test',
    ]);
    $account = createAccountForConnectivityTest($user, true);
    $credentialsBefore = $account->all_credentials;

    $this->actingAs($user)
        ->postJson('https://admin.kraite.test/accounts/connectivity/test', [
            'account_id' => $account->id,
            'api_key' => 'replacement-without-secret',
        ])
        ->assertUnprocessable()
        ->assertJsonValidationErrors('api_secret');

    expect(Step::query()->where('relatable_id', $account->id)->exists())->toBeFalse()
        ->and($account->refresh()->all_credentials)->toBe($credentialsBefore);
});

it('tests replacement credentials on a draft and applies only the completed result', function (): void {
    $serverId = DB::table('servers')->insertGetId([
        'hostname' => 'test-iris-replacement',
        'ip_address' => '203.0.113.60',
        'is_apiable' => true,
        'needs_whitelisting' => true,
        'type' => 'worker',
        'own_queue_name' => 'test-iris-replacement',
        'created_at' => now(),
        'updated_at' => now(),
    ]);

    $user = User::factory()->create([
        'name' => 'Replacement Credentials Owner',
        'email' => 'replacement-connectivity-owner@kraite.test',
    ]);
    $account = createAccountForConnectivityTest($user, true);

    $response = $this->actingAs($user)
        ->postJson('https://admin.kraite.test/accounts/connectivity/test', [
            'account_id' => $account->id,
            'api_key' => 'replacement-key',
            'api_secret' => 'replacement-secret',
        ])
        ->assertSuccessful()
        ->assertJsonPath('credentials_mode', 'replacement');

    $blockUuid = $response->json('block_uuid');
    $parent = Step::query()->where('block_uuid', $blockUuid)->sole();
    $draft = Account::withTrashed()->findOrFail($parent->relatable_id);

    expect($draft->id)->not->toBe($account->id)
        ->and($draft->name)->toBe("Connection test for account {$account->id}")
        ->and($draft->binance_api_key)->toBe('replacement-key')
        ->and($draft->binance_api_secret)->toBe('replacement-secret')
        ->and($account->refresh()->binance_api_key)->toBe('saved-connectivity-key')
        ->and($account->binance_api_secret)->toBe('saved-connectivity-secret');

    $childBlockUuid = (string) Str::uuid();
    DB::table('steps')->where('id', $parent->id)->update([
        'state' => Completed::class,
        'child_block_uuid' => $childBlockUuid,
        'completed_at' => now(),
    ]);
    Step::query()->create([
        'block_uuid' => $childBlockUuid,
        'class' => TestServerConnectivityStep::class,
        'state' => Completed::class,
        'queue' => 'test-iris-replacement',
        'relatable_type' => Account::class,
        'relatable_id' => $draft->id,
        'arguments' => [
            'accountId' => $draft->id,
            'serverId' => $serverId,
        ],
        'index' => 1,
        'completed_at' => now(),
    ]);

    $this->actingAs($user)
        ->patchJson('https://admin.kraite.test/accounts/connectivity/credentials', [
            'account_id' => $account->id,
            'tested_block_uuid' => $blockUuid,
            'api_key' => 'replacement-key',
            'api_secret' => 'replacement-secret',
        ])
        ->assertSuccessful()
        ->assertJsonPath('message', 'API keys saved. The connection is ready, but an inactive subscription prevents new positions.')
        ->assertJsonPath('account.can_trade', true)
        ->assertJsonPath('account.has_credentials', true);

    $account->refresh();

    expect($account->binance_api_key)->toBe('replacement-key')
        ->and($account->binance_api_secret)->toBe('replacement-secret')
        ->and($account->can_trade)->toBeTrue()
        ->and(Account::withTrashed()->find($draft->id))->toBeNull();
});
