<?php

declare(strict_types=1);

use App\Models\User;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/*
|--------------------------------------------------------------------------
| Fleet positions — per-account collapsible overview
|--------------------------------------------------------------------------
|
| Core owns the trading tables (excluded from the SQLite suite), so the
| minimal shapes are stubbed. Every query on this page is portable SQL,
| which lets the tests pin the real math: margin ratio, alpha-path bands,
| roll-up, worst-first ordering, and the longs/shorts split.
|
*/

function stubPositionTables(): void
{
    Schema::create('api_systems', function (Blueprint $t): void {
        $t->id();
        $t->string('name');
        $t->boolean('is_exchange')->default(true);
    });
    Schema::create('accounts', function (Blueprint $t): void {
        $t->id();
        $t->unsignedBigInteger('user_id');
        $t->unsignedBigInteger('api_system_id');
        $t->timestamps();
        $t->softDeletes(); // Account model uses SoftDeletes — binding queries it
    });
    Schema::create('exchange_symbols', function (Blueprint $t): void {
        $t->id();
        $t->string('token')->nullable();
        $t->decimal('mark_price', 20, 8)->nullable();
    });
    Schema::create('positions', function (Blueprint $t): void {
        $t->id();
        $t->unsignedBigInteger('account_id');
        $t->unsignedBigInteger('exchange_symbol_id')->nullable();
        $t->string('parsed_trading_pair')->nullable();
        $t->string('direction');
        $t->tinyInteger('is_open')->default(1);
        $t->decimal('first_profit_price', 20, 8)->nullable();
        $t->decimal('opening_price', 20, 8)->nullable();
        $t->decimal('quantity', 20, 8)->nullable();
        $t->unsignedInteger('total_limit_orders')->default(4);
        $t->timestamps();
    });
    Schema::create('orders', function (Blueprint $t): void {
        $t->id();
        $t->unsignedBigInteger('position_id');
        $t->string('type');
        $t->string('status');
        $t->string('exchange_order_id')->nullable();
        $t->decimal('quantity', 20, 8)->nullable();
        $t->decimal('price', 20, 8)->nullable();
        $t->timestamps();
    });
    Schema::create('account_balance_history', function (Blueprint $t): void {
        $t->id();
        $t->unsignedBigInteger('account_id');
        $t->decimal('total_maintenance_margin', 20, 5)->default(0);
        $t->decimal('total_margin_balance', 20, 5)->default(0);
        $t->decimal('total_unrealized_profit', 20, 5)->default(0);
        $t->timestamps();
    });
}

afterEach(function (): void {
    foreach (['api_systems', 'accounts', 'exchange_symbols', 'positions', 'orders', 'account_balance_history'] as $table) {
        Schema::dropIfExists($table);
    }
});

/**
 * One account with one open LONG walked 90% down its corridor (red),
 * plus supporting fixtures. Returns [accountId, positionId].
 */
function seedRedAccount(int $userId): array
{
    $ex = DB::table('api_systems')->insertGetId(['name' => 'Binance']);
    $accountId = DB::table('accounts')->insertGetId(['user_id' => $userId, 'api_system_id' => $ex]);
    // Corridor: TP at 100, deepest rung at 80, mark at 82 → (100-82)/(100-80) = 90%.
    $symbol = DB::table('exchange_symbols')->insertGetId(['token' => 'SOL', 'mark_price' => 82]);
    $positionId = DB::table('positions')->insertGetId([
        'account_id' => $accountId, 'exchange_symbol_id' => $symbol,
        'parsed_trading_pair' => 'SOLUSDT', 'direction' => 'LONG', 'is_open' => 1,
        'first_profit_price' => 100, 'opening_price' => 95, 'quantity' => 2,
        'total_limit_orders' => 4, 'created_at' => now(),
    ]);
    // Deepest rung = largest quantity live LIMIT (price 80); 2 of 4 rungs filled.
    DB::table('orders')->insert([
        ['position_id' => $positionId, 'type' => 'LIMIT', 'status' => 'FILLED', 'exchange_order_id' => 'x1', 'quantity' => 2, 'price' => 92],
        ['position_id' => $positionId, 'type' => 'LIMIT', 'status' => 'FILLED', 'exchange_order_id' => 'x2', 'quantity' => 4, 'price' => 88],
        ['position_id' => $positionId, 'type' => 'LIMIT', 'status' => 'NEW', 'exchange_order_id' => 'x3', 'quantity' => 8, 'price' => 84],
        ['position_id' => $positionId, 'type' => 'LIMIT', 'status' => 'NEW', 'exchange_order_id' => 'x4', 'quantity' => 16, 'price' => 80],
        // Noise that must not count: TP order + an unplaced LIMIT (no exchange id).
        ['position_id' => $positionId, 'type' => 'PROFIT-LIMIT', 'status' => 'NEW', 'exchange_order_id' => 'x5', 'quantity' => 2, 'price' => 100],
        ['position_id' => $positionId, 'type' => 'LIMIT', 'status' => 'NEW', 'exchange_order_id' => null, 'quantity' => 32, 'price' => 60],
    ]);
    // Margin ratio 5%: maintenance 50 over balance 1000; PnL −26.
    DB::table('account_balance_history')->insert([
        'account_id' => $accountId, 'total_maintenance_margin' => 50,
        'total_margin_balance' => 1000, 'total_unrealized_profit' => -26, 'created_at' => now(),
    ]);

    return [$accountId, $positionId];
}

it('gates the positions surfaces to sysadmins', function (): void {
    $this->get('https://admin.kraite.test/system/positions')->assertRedirect();

    $trader = User::factory()->create(['is_admin' => false]);
    $this->actingAs($trader)->get('https://admin.kraite.test/system/positions')->assertForbidden();
    $this->actingAs($trader)->get('https://admin.kraite.test/system/positions/data')->assertForbidden();
});

it('lists only accounts holding open positions, with exact margin ratio, pnl and red roll-up', function (): void {
    stubPositionTables();
    $admin = User::factory()->create(['is_admin' => true, 'email' => 'pos-admin@kraite.test']);
    $owner = User::factory()->create(['name' => 'Karine Esnault', 'email' => 'pos-owner@kraite.test']);
    [$accountId] = seedRedAccount($owner->id);

    // Idle account (no open positions) must NOT appear.
    $idle = DB::table('accounts')->insertGetId(['user_id' => $owner->id, 'api_system_id' => DB::table('api_systems')->insertGetId(['name' => 'Bybit'])]);
    DB::table('positions')->insert(['account_id' => $idle, 'direction' => 'LONG', 'is_open' => 0, 'created_at' => now()]);

    $response = $this->actingAs($admin)
        ->get('https://admin.kraite.test/system/positions/data')
        ->assertSuccessful();

    $response->assertJsonCount(1, 'accounts')
        ->assertJsonPath('accounts.0.id', $accountId)
        ->assertJsonPath('accounts.0.owner', 'Karine Esnault')
        ->assertJsonPath('accounts.0.exchange', 'Binance')
        ->assertJsonPath('accounts.0.positions', 1)
        ->assertJsonPath('accounts.0.margin_ratio', 5)
        ->assertJsonPath('accounts.0.pnl', -26)
        // Corridor math: TP 100 → deepest 80, mark 82 = 90% walked → red.
        ->assertJsonPath('accounts.0.worst_alpha_pct', 90)
        ->assertJsonPath('accounts.0.band', 'red');
});

it('splits an expanded account into longs and shorts with exact rung and alpha figures', function (): void {
    stubPositionTables();
    $admin = User::factory()->create(['is_admin' => true, 'email' => 'pos-exp@kraite.test']);
    $owner = User::factory()->create(['email' => 'pos-exp-owner@kraite.test']);
    [$accountId, $positionId] = seedRedAccount($owner->id);

    // A healthy SHORT on the same account: TP 50, deepest rung 70, mark 54
    // → (50-54)/(50-70) = 20% → green. One of three rungs filled.
    $symbol = DB::table('exchange_symbols')->insertGetId(['token' => 'ETH', 'mark_price' => 54]);
    $shortId = DB::table('positions')->insertGetId([
        'account_id' => $accountId, 'exchange_symbol_id' => $symbol,
        'parsed_trading_pair' => 'ETHUSDT', 'direction' => 'SHORT', 'is_open' => 1,
        'first_profit_price' => 50, 'opening_price' => 55, 'quantity' => 1,
        'total_limit_orders' => 3, 'created_at' => now(),
    ]);
    DB::table('orders')->insert([
        ['position_id' => $shortId, 'type' => 'LIMIT', 'status' => 'FILLED', 'exchange_order_id' => 's1', 'quantity' => 1, 'price' => 58],
        ['position_id' => $shortId, 'type' => 'LIMIT', 'status' => 'NEW', 'exchange_order_id' => 's2', 'quantity' => 4, 'price' => 70],
    ]);
    // Foreign position on ANOTHER account must never leak in.
    $foreign = DB::table('accounts')->insertGetId(['user_id' => $owner->id, 'api_system_id' => 1]);
    DB::table('positions')->insert(['account_id' => $foreign, 'direction' => 'SHORT', 'is_open' => 1, 'created_at' => now()]);

    $response = $this->actingAs($admin)
        ->get("https://admin.kraite.test/system/positions/{$accountId}/positions")
        ->assertSuccessful();

    $response->assertJsonCount(1, 'longs')->assertJsonCount(1, 'shorts')
        ->assertJsonPath('longs.0.id', $positionId)
        ->assertJsonPath('longs.0.symbol', 'SOLUSDT')
        ->assertJsonPath('longs.0.rungs_filled', 2)
        ->assertJsonPath('longs.0.rungs_total', 4)
        ->assertJsonPath('longs.0.alpha_pct', 90)
        ->assertJsonPath('longs.0.band', 'red')
        // LONG pnl: (mark 82 − open 95) × qty 2 = −26.
        ->assertJsonPath('longs.0.pnl', -26)
        ->assertJsonPath('shorts.0.symbol', 'ETHUSDT')
        ->assertJsonPath('shorts.0.rungs_filled', 1)
        ->assertJsonPath('shorts.0.rungs_total', 3)
        ->assertJsonPath('shorts.0.alpha_pct', 20)
        ->assertJsonPath('shorts.0.band', 'green')
        // SHORT pnl: (open 55 − mark 54) × qty 1 = 1.
        ->assertJsonPath('shorts.0.pnl', 1);
});

it('orders accounts worst-first by roll-up depth', function (): void {
    stubPositionTables();
    $admin = User::factory()->create(['is_admin' => true, 'email' => 'pos-sort@kraite.test']);
    $owner = User::factory()->create(['email' => 'pos-sort-owner@kraite.test']);
    [$redAccount] = seedRedAccount($owner->id);

    // A green account: LONG barely into its corridor (TP 200, deep 100, mark 190 → 10%).
    $ex = DB::table('api_systems')->insertGetId(['name' => 'KuCoin']);
    $greenAccount = DB::table('accounts')->insertGetId(['user_id' => $owner->id, 'api_system_id' => $ex]);
    $symbol = DB::table('exchange_symbols')->insertGetId(['token' => 'BTC', 'mark_price' => 190]);
    $pid = DB::table('positions')->insertGetId([
        'account_id' => $greenAccount, 'exchange_symbol_id' => $symbol,
        'direction' => 'LONG', 'is_open' => 1, 'first_profit_price' => 200,
        'opening_price' => 195, 'quantity' => 1, 'total_limit_orders' => 2, 'created_at' => now(),
    ]);
    DB::table('orders')->insert(['position_id' => $pid, 'type' => 'LIMIT', 'status' => 'NEW', 'exchange_order_id' => 'g1', 'quantity' => 2, 'price' => 100]);

    $response = $this->actingAs($admin)
        ->get('https://admin.kraite.test/system/positions/data')
        ->assertSuccessful();

    $response->assertJsonCount(2, 'accounts')
        ->assertJsonPath('accounts.0.id', $redAccount)
        ->assertJsonPath('accounts.1.id', $greenAccount)
        ->assertJsonPath('accounts.1.band', 'green');
});

it('renders the positions page for admins with the live state seeded', function (): void {
    $admin = User::factory()->create(['is_admin' => true, 'email' => 'pos-page@kraite.test']);

    $response = $this->actingAs($admin)
        ->get('https://admin.kraite.test/system/positions')
        ->assertSuccessful();

    $response->assertSee('Fleet positions', false);
    $response->assertSee('positionsPage(', false);
    $response->assertSee('Auto-sync', false);
});
