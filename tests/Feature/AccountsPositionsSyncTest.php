<?php

declare(strict_types=1);

use App\Models\User;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Kraite\Core\Models\Account;

/**
 * The accounts/positions comparator must read the ENGINE'S exchange
 * snapshots (written by the whitelisted trading fleet), never call the
 * exchange from the web box — its IP is deliberately outside the egress
 * allowlist, so a direct call can only 401 and the page then mislabeled
 * every position "out of sync". These tests pin the snapshot data path
 * and the honest degraded states.
 */
beforeEach(function (): void {
    Schema::create('api_systems', function (Blueprint $t): void {
        $t->id();
        $t->string('name')->nullable();
        $t->string('canonical')->nullable();
    });
    Schema::create('accounts', function (Blueprint $t): void {
        $t->id();
        $t->unsignedBigInteger('user_id');
        $t->unsignedBigInteger('api_system_id');
        $t->string('name')->nullable();
        $t->string('margin_mode')->nullable();
        $t->boolean('can_trade')->default(true);
        $t->timestamps();
        $t->softDeletes();
    });
    Schema::create('symbols', function (Blueprint $t): void {
        $t->id();
        $t->string('token')->nullable();
        $t->string('name')->nullable();
        $t->string('image_url')->nullable();
    });
    Schema::create('exchange_symbols', function (Blueprint $t): void {
        $t->id();
        $t->unsignedBigInteger('symbol_id')->nullable();
        $t->string('token')->nullable();
        $t->decimal('mark_price', 20, 8)->nullable();
        $t->unsignedTinyInteger('price_precision')->default(2);
        $t->decimal('tick_size', 20, 8)->nullable();
    });
    Schema::create('exchange_symbol_prices', function (Blueprint $t): void {
        $t->id();
        $t->unsignedBigInteger('exchange_symbol_id');
        $t->decimal('price', 20, 8)->nullable();
        $t->timestamps();
    });
    Schema::create('positions', function (Blueprint $t): void {
        $t->id();
        $t->unsignedBigInteger('account_id');
        $t->unsignedBigInteger('exchange_symbol_id')->nullable();
        $t->string('parsed_trading_pair')->nullable();
        $t->string('direction');
        $t->string('status');
        $t->decimal('quantity', 20, 8)->nullable();
        $t->decimal('opening_price', 20, 8)->nullable();
        $t->decimal('margin', 20, 8)->nullable();
        $t->unsignedTinyInteger('leverage')->nullable();
        $t->timestamp('opened_at')->nullable();
        $t->timestamps();
    });
    Schema::create('orders', function (Blueprint $t): void {
        $t->id();
        $t->unsignedBigInteger('position_id');
        $t->string('type');
        $t->string('status');
        $t->string('side')->nullable();
        $t->string('client_order_id')->nullable();
        $t->string('exchange_order_id')->nullable();
        $t->decimal('quantity', 20, 8)->nullable();
        $t->decimal('price', 20, 8)->nullable();
        $t->timestamps();
    });
    Schema::create('api_snapshots', function (Blueprint $t): void {
        $t->id();
        $t->string('responsable_type');
        $t->unsignedBigInteger('responsable_id');
        $t->string('canonical');
        $t->longText('api_response')->nullable();
        $t->timestamps();
    });
});

afterEach(function (): void {
    foreach (['api_snapshots', 'orders', 'positions', 'exchange_symbol_prices', 'exchange_symbols', 'symbols', 'accounts', 'api_systems'] as $table) {
        Schema::dropIfExists($table);
    }
});

/**
 * One ACTIVE HYPEUSDT LONG with a pending entry rung (matched on the
 * exchange) and a stop-loss (algo endpoint — no snapshot source yet).
 * Returns [userId, accountId].
 */
function seedSyncedAccount(): array
{
    $owner = User::factory()->create();
    $apiSystem = DB::table('api_systems')->insertGetId(['name' => 'Binance', 'canonical' => 'binance']);
    $accountId = DB::table('accounts')->insertGetId([
        'user_id' => $owner->id, 'api_system_id' => $apiSystem,
        'name' => 'Main', 'margin_mode' => 'CROSSED', 'created_at' => now(), 'updated_at' => now(),
    ]);

    $positionId = DB::table('positions')->insertGetId([
        'account_id' => $accountId, 'parsed_trading_pair' => 'HYPEUSDT',
        'direction' => 'LONG', 'status' => 'active', 'quantity' => 0.56,
        'opening_price' => 67.764, 'leverage' => 20, 'margin' => 61,
        'opened_at' => now()->subDay(), 'created_at' => now()->subDay(), 'updated_at' => now(),
    ]);
    DB::table('orders')->insert([
        ['position_id' => $positionId, 'type' => 'LIMIT', 'status' => 'NEW', 'side' => 'BUY', 'client_order_id' => 'k1', 'exchange_order_id' => 'x1', 'price' => 61.665, 'quantity' => 1.12],
        ['position_id' => $positionId, 'type' => 'STOP-MARKET', 'status' => 'NEW', 'side' => 'SELL', 'client_order_id' => 'k2', 'exchange_order_id' => 'x2', 'price' => 42.066, 'quantity' => 0],
    ]);

    return [$owner->id, $accountId];
}

function seedExchangeSnapshots(int $accountId): void
{
    $rows = [
        ['canonical' => 'account-positions', 'api_response' => json_encode([
            'HYPEUSDT:LONG' => [
                'symbol' => 'HYPEUSDT', 'positionSide' => 'LONG', 'positionAmt' => '0.56',
                'entryPrice' => '67.764', 'unRealizedProfit' => '0.04', 'isolatedMargin' => '0',
            ],
        ])],
        ['canonical' => 'account-open-orders', 'api_response' => json_encode([
            [
                'symbol' => 'HYPEUSDT', 'clientOrderId' => 'k1', 'orderId' => 'x1',
                'status' => 'NEW', 'side' => 'BUY', 'type' => 'LIMIT',
                'price' => '61.665', 'origQty' => '1.12', 'positionSide' => 'LONG',
            ],
        ])],
    ];

    foreach ($rows as $row) {
        DB::table('api_snapshots')->insert($row + [
            'responsable_type' => Account::class,
            'responsable_id' => $accountId,
            'created_at' => now()->subMinute(),
            'updated_at' => now()->subMinute(),
        ]);
    }
}

it('compares against the engine snapshots and reports the matched position as synced', function (): void {
    [$userId, $accountId] = seedSyncedAccount();
    seedExchangeSnapshots($accountId);

    $response = $this->actingAs(User::findOrFail($userId))
        ->get("https://admin.kraite.test/accounts/positions/data?account_id={$accountId}")
        ->assertSuccessful();

    $response->assertJsonPath('exchange_snapshots_missing', false)
        ->assertJsonPath('pairs.0.symbol', 'HYPEUSDT')
        ->assertJsonPath('pairs.0.status', 'synced')
        ->assertJsonPath('pairs.0.position_drift_fields', [])
        ->assertJsonPath('pairs.0.exchange.entry_price', '67.764')
        // Entry rung matched by client order id against the snapshot.
        ->assertJsonPath('pairs.0.orders.0.status', 'synced')
        // Stop-loss lives on the exchange's algo endpoint, which has no
        // snapshot source yet — honestly UNVERIFIED, never fake drift.
        ->assertJsonPath('pairs.0.orders.1.status', 'unverified');

    expect($response->json('exchange_as_of_seconds'))->toBeInt()->toBeGreaterThanOrEqual(0);
});

it('reports every pair unverified when no exchange snapshots exist yet', function (): void {
    [$userId, $accountId] = seedSyncedAccount();

    $this->actingAs(User::findOrFail($userId))
        ->get("https://admin.kraite.test/accounts/positions/data?account_id={$accountId}")
        ->assertSuccessful()
        ->assertJsonPath('exchange_snapshots_missing', true)
        ->assertJsonPath('exchange_as_of_seconds', null)
        ->assertJsonPath('pairs.0.status', 'unverified')
        ->assertJsonPath('pairs.0.orders.0.status', 'unverified')
        ->assertJsonPath('pairs.0.orders.1.status', 'unverified');
});

it('treats a position newer than the snapshot as unverified, not drifting', function (): void {
    [$userId, $accountId] = seedSyncedAccount();

    // Snapshots written BEFORE this position opened — they cannot contain
    // it, so its absence proves nothing. (Exactly the race that flagged a
    // minutes-old POLUSDT as fully out of sync in production.)
    seedExchangeSnapshots($accountId);
    DB::table('api_snapshots')->update(['updated_at' => now()->subMinutes(30)]);
    DB::table('api_snapshots')
        ->where('canonical', 'account-positions')
        ->update(['api_response' => json_encode([])]); // position absent
    DB::table('api_snapshots')
        ->where('canonical', 'account-open-orders')
        ->update(['api_response' => json_encode([])]); // orders absent
    DB::table('positions')->update(['opened_at' => now()->subMinutes(5), 'created_at' => now()->subMinutes(5)]);

    $this->actingAs(User::findOrFail($userId))
        ->get("https://admin.kraite.test/accounts/positions/data?account_id={$accountId}")
        ->assertSuccessful()
        ->assertJsonPath('pairs.0.status', 'unverified')
        ->assertJsonPath('pairs.0.orders.0.status', 'unverified')
        ->assertJsonPath('pairs.0.orders.1.status', 'unverified');
});

it('flags a stop order as db_only once the algo snapshot exists and lacks it', function (): void {
    [$userId, $accountId] = seedSyncedAccount();
    seedExchangeSnapshots($accountId);
    // Engine reported the algo endpoint: EMPTY — the stop-loss is genuinely
    // missing on the exchange now, so it must surface as real drift.
    DB::table('api_snapshots')->insert([
        'responsable_type' => Account::class,
        'responsable_id' => $accountId,
        'canonical' => 'account-algo-orders',
        'api_response' => json_encode([]),
        'created_at' => now(), 'updated_at' => now(),
    ]);

    $this->actingAs(User::findOrFail($userId))
        ->get("https://admin.kraite.test/accounts/positions/data?account_id={$accountId}")
        ->assertSuccessful()
        ->assertJsonPath('pairs.0.orders.1.status', 'db_only')
        ->assertJsonPath('pairs.0.status', 'drift');
});
