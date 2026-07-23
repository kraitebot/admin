<?php

declare(strict_types=1);

use App\Models\User;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Kraite\Core\Enums\BacktestTimeframe;
use Kraite\Core\Models\ExchangeSymbol;

/**
 * The console backtesting index reads three kraitebot/core-owned tables
 * (exchange_symbols ⋈ symbols ⋈ api_systems) plus the accounts row for form
 * defaults. Core's real schema is MySQL-coupled and excluded from the SQLite
 * suite (see TestCase), so stub the minimum shape the listing query selects.
 */
beforeEach(function (): void {
    DB::connection()->getPdo()->sqliteCreateFunction(
        'CONCAT',
        static fn (mixed ...$parts): string => implode('', $parts),
    );

    if (! Schema::hasTable('api_systems')) {
        Schema::create('api_systems', function (Blueprint $table): void {
            $table->id();
            $table->string('canonical')->nullable();
            $table->boolean('is_active')->default(true);
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
            $table->string('token')->nullable();
            $table->string('quote')->nullable();
            $table->string('percentage_gap_long')->nullable();
            $table->string('percentage_gap_short')->nullable();
            $table->integer('total_limit_orders')->nullable();
            $table->text('limit_quantity_multipliers')->nullable();
            $table->boolean('was_backtesting_approved')->default(false);
            $table->string('backtesting_review_status')->nullable();
            $table->boolean('is_manually_enabled')->default(false);
            $table->json('api_statuses')->nullable();
            $table->boolean('has_no_indicator_data')->default(false);
            $table->boolean('is_marked_for_delisting')->default(false);
            $table->dateTime('system_disabled_at')->nullable();
            $table->boolean('is_price_aligned')->default(true);
            $table->boolean('has_price_trend_misalignment')->default(false);
            $table->boolean('has_early_direction_change')->default(false);
            $table->boolean('has_invalid_indicator_direction')->default(false);
            $table->json('leverage_brackets')->nullable();
            $table->string('direction')->nullable();
            $table->dateTime('tradeable_at')->nullable();
            $table->string('indicators_timeframe')->nullable();
            $table->json('btc_correlation_rolling')->nullable();
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
        'token' => 'BTC',
        'quote' => 'USDT',
        'percentage_gap_long' => '0.60',
        'percentage_gap_short' => '0.60',
        'total_limit_orders' => 4,
        'limit_quantity_multipliers' => '[2,2,2,2]',
        'was_backtesting_approved' => true,
        'backtesting_review_status' => 'approved',
        'is_manually_enabled' => true,
        'direction' => 'long',
    ]);
}

/**
 * Seed a Binance symbol that becomes fully tradeable when approval also
 * enables it manually, unless one of the supplied gate overrides blocks it.
 *
 * @param  array<string, mixed>  $overrides
 */
function seedImmediateTradeableToken(array $overrides = []): int
{
    $token = $overrides['token'] ?? 'ETH';
    $cmcRanking = $overrides['cmc_ranking'] ?? 2;
    unset($overrides['token'], $overrides['cmc_ranking']);

    $apiSystemId = DB::table('api_systems')->insertGetId([
        'canonical' => 'binance',
        'is_active' => true,
    ]);
    $symbolId = DB::table('symbols')->insertGetId([
        'token' => $token,
        'cmc_ranking' => $cmcRanking,
        'cmc_category' => 'Layer 1',
        'image_url' => null,
    ]);

    return (int) DB::table('exchange_symbols')->insertGetId(array_merge([
        'symbol_id' => $symbolId,
        'api_system_id' => $apiSystemId,
        'token' => $token,
        'quote' => 'USDT',
        'percentage_gap_long' => '0.60',
        'percentage_gap_short' => '0.60',
        'total_limit_orders' => 4,
        'limit_quantity_multipliers' => '[2,2,2,2]',
        'was_backtesting_approved' => false,
        'backtesting_review_status' => null,
        // Approval deliberately changes this false gate to true.
        'is_manually_enabled' => false,
        'api_statuses' => json_encode(['has_taapi_data' => true], JSON_THROW_ON_ERROR),
        'has_no_indicator_data' => false,
        'is_marked_for_delisting' => false,
        'system_disabled_at' => null,
        'is_price_aligned' => true,
        'has_price_trend_misalignment' => false,
        'has_early_direction_change' => false,
        'has_invalid_indicator_direction' => false,
        'leverage_brackets' => '[]',
        'direction' => 'long',
        'tradeable_at' => null,
        'indicators_timeframe' => '1d',
        'btc_correlation_rolling' => json_encode(['1d' => 0.72], JSON_THROW_ON_ERROR),
    ], $overrides));
}

/**
 * Seed `$count` contiguous candles for a symbol+timeframe ending at `$endTs`.
 */
function seedCandles(int $exchangeSymbolId, string $timeframe, int $count, int $endTs): void
{
    $iv = BacktestTimeframe::from($timeframe)->seconds();
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
    expect(data_get($response->viewData('symbols'), 'USDT.0.direction'))->toBe('LONG');
    $response->assertSee('x-show="s.direction"', false);
    $response->assertSee('x-text="s.direction"', false);
    $response->assertSee('x-show="selected.direction"', false);
    $response->assertSee('x-text="selected.direction"', false);
    // The endpoints the workspace drives are wired into the bootstrap.
    // (@js escapes slashes, so assert the unique hyphenated path segments.)
    $response->assertSee('fetch-candles', false);
    $response->assertSee('verify-coverage', false);
    $response->assertSee('ensure-coverage', false);
    $response->assertSee('coverage-status', false);
    $response->assertSee('toggle-approval', false);
    $response->assertSee('ai-insights', false);
    // The proposal evidence floor is wired in: a zero-resolved run must never
    // read as "Recommend approve", thin samples fall back to manual review,
    // and Approve locks on nothing-simulated runs.
    $response->assertSee('Cannot recommend — nothing simulated', false);
    $response->assertSee('Thin sample — review manually', false);
    $response->assertSee('resolvedSims === 0', false);
    // The SL-coverage tiers panel renders from totals.sl_coverage.
    $response->assertSee('Stop-loss coverage', false);
    $response->assertSee('Latest SL · ', false);
    $response->assertSee('relativeAge(d.latest_stop_at)', false);
    $response->assertSee("if (!value) return '—';", false);
    // The toast is teleported outside the scroll shell and centered without
    // Tailwind transform utilities, whose defaults are absent with Preflight off.
    $response->assertSee('x-teleport="body"', false);
    $response->assertSee(':data-theme="contentDark ? \'dark\' : \'light\'"', false);
    $response->assertSee('fixed inset-x-0 bottom-[calc(41px+1.5rem)] z-[90] flex justify-center px-4', false);
    $response->assertSee('w-max max-w-full flex items-center gap-2.5', false);
    $response->assertDontSee('left-1/2 -translate-x-1/2 z-[90] w-max', false);
    // Every adjustment candidate row carries a one-click apply-and-re-run button.
    $response->assertSee('Apply this config and backtest again', false);
    $response->assertSee('Immediate Tradeable', false);
    $response->assertSee('filters.top100 && (s.rank == null || s.rank > 100)', false);
    $response->assertSee('s.rank != null && s.rank <= 100', false);
    $response->assertSee('filters.immediateTradeable && ! s.immediateTradeable', false);
    $response->assertSee('coverageHealthy(c)', false);
    $response->assertSee('coverageWindowCovered(c)', false);
    $response->assertSee('Thin history — ${c.candles} candles', false);
    expect(file_get_contents(app_path('Http/Controllers/System/BacktrackingController.php')))
        ->toContain('Coverage: contiguous')
        ->not->toContain('Coverage: complete');
});

it('marks only symbols where approval is the final tradeability action', function (): void {
    $immediateId = seedImmediateTradeableToken();
    $blockedId = seedImmediateTradeableToken([
        'token' => 'SOL',
        'cmc_ranking' => 5,
        'direction' => null,
    ]);
    $admin = User::factory()->create(['is_admin' => true]);

    $response = $this->actingAs($admin)
        ->get('https://admin.kraite.test/system/backtesting')
        ->assertSuccessful();

    $symbols = collect($response->viewData('symbols'))
        ->flatMap(static fn (array $group): array => $group)
        ->keyBy('id');

    expect($symbols[$immediateId]['is_immediately_tradeable'])->toBeTrue()
        ->and($symbols[$blockedId]['is_immediately_tradeable'])->toBeFalse();
});

it('turns an immediate candidate tradeable through approval alone', function (): void {
    $exchangeSymbolId = seedImmediateTradeableToken();

    expect(ExchangeSymbol::query()->whereKey($exchangeSymbolId)->awaitingBacktestingApproval()->exists())->toBeTrue()
        ->and(ExchangeSymbol::query()->whereKey($exchangeSymbolId)->tradeable()->exists())->toBeFalse();

    DB::table('exchange_symbols')->where('id', $exchangeSymbolId)->update([
        'was_backtesting_approved' => true,
        'is_manually_enabled' => true,
    ]);

    expect(ExchangeSymbol::query()->whereKey($exchangeSymbolId)->awaitingBacktestingApproval()->exists())->toBeFalse()
        ->and(ExchangeSymbol::query()->whereKey($exchangeSymbolId)->tradeable()->exists())->toBeTrue();
});

it('no longer hard-blocks a backtest run on stale candle data (soft coverage gate)', function (): void {
    $esId = seedBacktestableToken();
    $iv = BacktestTimeframe::OneDay->seconds();
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
    $iv = BacktestTimeframe::OneDay->seconds();
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
