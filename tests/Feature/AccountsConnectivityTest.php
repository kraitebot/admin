<?php

declare(strict_types=1);

use App\Models\User;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Str;
use Kraite\Core\Jobs\Atomic\Account\TestServerConnectivityStep;
use Kraite\Core\Jobs\Lifecycles\Account\TestExchangeConnectivityStep;
use Kraite\Core\Models\Account;
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
    Schema::dropIfExists('servers');
});

function createAccountForConnectivityTest(User $user, bool $withCredentials): Account
{
    $apiSystemId = DB::table('api_systems')->insertGetId([
        'name' => 'Binance',
        'canonical' => 'binance',
        'created_at' => now(),
        'updated_at' => now(),
    ]);

    $accountId = DB::table('accounts')->insertGetId([
        'uuid' => fake()->uuid(),
        'name' => 'Connectivity Test Account',
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
        ->assertJsonPath('account.can_trade', true);

    expect($account->refresh()->all_credentials)->toBe($credentialsBefore)
        ->and($account->can_trade)->toBeTrue()
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
        ->assertJsonPath('account.can_trade', true)
        ->assertJsonPath('account.has_credentials', true);

    $account->refresh();

    expect($account->binance_api_key)->toBe('replacement-key')
        ->and($account->binance_api_secret)->toBe('replacement-secret')
        ->and($account->can_trade)->toBeTrue()
        ->and(Account::withTrashed()->find($draft->id))->toBeNull();
});
