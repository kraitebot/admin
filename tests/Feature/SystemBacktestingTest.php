<?php

declare(strict_types=1);

use App\Models\User;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Kraite\Core\Support\Backtest\CandleCoverageVerifier;

/**
 * The console backtesting index reads three kraitebot/core-owned tables
 * (exchange_symbols ⋈ symbols ⋈ api_systems) plus the accounts row for form
 * defaults. Core's real schema is MySQL-coupled and excluded from the SQLite
 * suite (see TestCase), so stub the minimum shape the listing query selects.
 */
beforeEach(function (): void {
    if (! Schema::hasTable('api_systems')) {
        Schema::create('api_systems', function (Blueprint $table): void {
            $table->id();
            $table->string('canonical')->nullable();
        });
    }
    if (! Schema::hasTable('symbols')) {
        Schema::create('symbols', function (Blueprint $table): void {
            $table->id();
            $table->string('token')->nullable();
            $table->integer('cmc_ranking')->nullable();
            $table->string('cmc_category')->nullable();
            $table->string('image_url')->nullable();
        });
    }
    if (! Schema::hasTable('exchange_symbols')) {
        Schema::create('exchange_symbols', function (Blueprint $table): void {
            $table->id();
            $table->unsignedBigInteger('symbol_id');
            $table->unsignedBigInteger('api_system_id');
            $table->string('quote')->nullable();
            $table->string('percentage_gap_long')->nullable();
            $table->string('percentage_gap_short')->nullable();
            $table->integer('total_limit_orders')->nullable();
            $table->text('limit_quantity_multipliers')->nullable();
            $table->boolean('was_backtesting_approved')->default(false);
            $table->string('backtesting_review_status')->nullable();
            $table->boolean('is_manually_enabled')->default(false);
        });
    }
    if (! Schema::hasTable('accounts')) {
        Schema::create('accounts', function (Blueprint $table): void {
            $table->id();
            $table->string('profit_percentage')->nullable();
            $table->string('stop_market_initial_percentage')->nullable();
            $table->softDeletes();
        });
    }
    // CandleCoverageVerifier::verify() only reads candles.timestamp for a
    // (symbol, timeframe) — stub the minimum so the run/approve risk gate runs.
    if (! Schema::hasTable('candles')) {
        Schema::create('candles', function (Blueprint $table): void {
            $table->id();
            $table->unsignedBigInteger('exchange_symbol_id');
            $table->string('timeframe');
            $table->unsignedBigInteger('timestamp');
        });
    }
});

function seedBacktestableToken(): int
{
    $apiSystemId = DB::table('api_systems')->insertGetId(['canonical' => 'binance']);
    $symbolId = DB::table('symbols')->insertGetId([
        'token' => 'BTC',
        'cmc_ranking' => 1,
        'cmc_category' => 'Layer 1',
        'image_url' => 'https://s2.coinmarketcap.com/static/img/coins/64x64/1.png',
    ]);

    return (int) DB::table('exchange_symbols')->insertGetId([
        'symbol_id' => $symbolId,
        'api_system_id' => $apiSystemId,
        'quote' => 'USDT',
        'percentage_gap_long' => '0.60',
        'percentage_gap_short' => '0.60',
        'total_limit_orders' => 4,
        'limit_quantity_multipliers' => '[2,2,2,2]',
        'was_backtesting_approved' => true,
        'backtesting_review_status' => 'approved',
        'is_manually_enabled' => true,
    ]);
}

/**
 * Seed `$count` contiguous candles for a symbol+timeframe ending at `$endTs`.
 */
function seedCandles(int $exchangeSymbolId, string $timeframe, int $count, int $endTs): void
{
    $iv = CandleCoverageVerifier::INTERVAL_SECONDS[$timeframe];
    $rows = [];
    for ($i = 0; $i < $count; $i++) {
        $rows[] = [
            'exchange_symbol_id' => $exchangeSymbolId,
            'timeframe' => $timeframe,
            'timestamp' => $endTs - ($i * $iv),
        ];
    }
    DB::table('candles')->insert($rows);
}

it('redirects guests on the backtesting console page to login', function (): void {
    $this->get('https://admin.kraite.test/system/backtesting')
        ->assertRedirect();
});

it('forbids non-admin users from the backtesting console page', function (): void {
    $user = User::factory()->create(['is_admin' => false]);

    $this->actingAs($user)
        ->get('https://admin.kraite.test/system/backtesting')
        ->assertForbidden();
});

it('renders the backtesting workspace for admins', function (): void {
    seedBacktestableToken();
    $admin = User::factory()->create(['is_admin' => true]);

    $response = $this->actingAs($admin)
        ->get('https://admin.kraite.test/system/backtesting');

    $response->assertSuccessful();
    // Page chrome + the Alpine workspace bootstrap are present.
    $response->assertSee('Backtesting', false);
    $response->assertSee('btConsole(', false);
    // The seeded token reached the client config.
    $response->assertSee('BTC', false);
    // The token's logo URL is wired into the selector config for the avatar.
    // (@js escapes slashes, so assert the slash-free host segment.)
    $response->assertSee('s2.coinmarketcap.com', false);
    // The endpoints the workspace drives are wired into the bootstrap.
    // (@js escapes slashes, so assert the unique hyphenated path segments.)
    $response->assertSee('fetch-candles', false);
    $response->assertSee('verify-coverage', false);
    $response->assertSee('ensure-coverage', false);
    $response->assertSee('coverage-status', false);
    $response->assertSee('toggle-approval', false);
    $response->assertSee('ai-insights', false);
});

it('no longer hard-blocks a backtest run on stale candle data (soft coverage gate)', function (): void {
    $esId = seedBacktestableToken();
    $iv = CandleCoverageVerifier::INTERVAL_SECONDS['1d'];
    // Contiguous daily candles, but the latest is ~6 days old → stale for 1d.
    $staleLatest = intdiv(time(), $iv) * $iv - (6 * $iv);
    seedCandles($esId, '1d', 60, $staleLatest);
    $admin = User::factory()->create(['is_admin' => true]);

    $response = $this->actingAs($admin)
        ->postJson('https://admin.kraite.test/system/backtesting/run', [
            'exchange_symbol_id' => $esId,
            'timeframe' => '1d',
            'tp_percent' => 1.5,
            'sl_percent' => 8,
        ]);

    // The coverage gate no longer refuses the grade on stale data — it grades on
    // the available candles and attaches a warning instead. (The MySQL-coupled
    // simulator can't actually run under the SQLite stub, so we only assert the
    // coverage gate is no longer the blocker — `data_not_ready` is gone.)
    expect($response->json('error'))->not->toBe('data_not_ready');
});

it('no longer blocks approval on stale candle data (admin final call)', function (): void {
    $esId = seedBacktestableToken();
    $iv = CandleCoverageVerifier::INTERVAL_SECONDS['1d'];
    $staleLatest = intdiv(time(), $iv) * $iv - (6 * $iv);
    seedCandles($esId, '1d', 60, $staleLatest);
    $admin = User::factory()->create(['is_admin' => true]);

    $response = $this->actingAs($admin)
        ->postJson('https://admin.kraite.test/system/backtesting/toggle-approval', [
            'exchange_symbol_id' => $esId,
            'approve' => true,
            'timeframe' => '1d',
        ]);

    // Coverage no longer blocks the decision — approve / reject is the admin's
    // final call. (The ExchangeSymbolObserver's cross-exchange propagation is
    // MySQL/core-coupled and can't complete under the SQLite stub, so we only
    // assert the coverage gate is gone — `data_not_ready` no longer appears.)
    expect($response->json('error'))->not->toBe('data_not_ready');
});
