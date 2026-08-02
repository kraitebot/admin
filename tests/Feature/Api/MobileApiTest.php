<?php

declare(strict_types=1);

use App\Http\Controllers\DashboardController as WebDashboardController;
use App\Models\User;
use Carbon\CarbonImmutable;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Laravel\Sanctum\Sanctum;

beforeEach(function (): void {
    Schema::create('personal_access_tokens', function (Blueprint $table): void {
        $table->id();
        $table->morphs('tokenable');
        $table->text('name');
        $table->string('token', 64)->unique();
        $table->text('abilities')->nullable();
        $table->timestamp('last_used_at')->nullable();
        $table->timestamp('expires_at')->nullable()->index();
        $table->timestamps();
    });

    Schema::create('api_systems', function (Blueprint $table): void {
        $table->id();
        $table->string('name');
        $table->string('canonical')->nullable();
    });

    Schema::create('accounts', function (Blueprint $table): void {
        $table->id();
        $table->unsignedBigInteger('user_id');
        $table->unsignedBigInteger('api_system_id');
        $table->string('name');
        $table->boolean('is_active')->default(false);
        $table->boolean('can_trade')->default(false);
        $table->unsignedInteger('total_positions_long')->default(6);
        $table->unsignedInteger('total_positions_short')->default(6);
        $table->timestamps();
        $table->softDeletes();
    });
});

afterEach(function (): void {
    Schema::dropIfExists('candles');
    Schema::dropIfExists('exchange_symbol_prices');
    Schema::dropIfExists('exchange_symbols');
    Schema::dropIfExists('symbols');
    Schema::dropIfExists('market_regime_snapshots');
    Schema::dropIfExists('account_balance_history');
    Schema::dropIfExists('positions');
    Schema::dropIfExists('accounts');
    Schema::dropIfExists('api_systems');
    Schema::dropIfExists('personal_access_tokens');
});

function prepareMobileDashboardDataSchema(): void
{
    Schema::create('symbols', function (Blueprint $table): void {
        $table->id();
        $table->string('token');
        $table->string('name')->nullable();
        $table->string('image_url')->nullable();
    });

    Schema::create('exchange_symbols', function (Blueprint $table): void {
        $table->id();
        $table->unsignedBigInteger('symbol_id');
        $table->unsignedBigInteger('api_system_id');
        $table->string('quote');
        $table->unsignedTinyInteger('price_precision')->default(8);
        $table->decimal('tick_size', 20, 8)->default(0);
    });

    Schema::create('exchange_symbol_prices', function (Blueprint $table): void {
        $table->id();
        $table->unsignedBigInteger('exchange_symbol_id')->unique();
        $table->decimal('mark_price', 20, 8)->nullable();
        $table->dateTime('mark_price_synced_at')->nullable();
        $table->timestamps();
    });

    Schema::create('candles', function (Blueprint $table): void {
        $table->id();
        $table->unsignedBigInteger('exchange_symbol_id');
        $table->string('timeframe');
        $table->decimal('open', 20, 8);
        $table->decimal('close', 20, 8);
        $table->unsignedBigInteger('timestamp');
    });

    Schema::create('positions', function (Blueprint $table): void {
        $table->id();
        $table->unsignedBigInteger('account_id');
        $table->unsignedBigInteger('exchange_symbol_id')->nullable();
        $table->string('status');
        $table->dateTime('opened_at')->nullable();
        $table->dateTime('closed_at')->nullable();
        $table->decimal('pnl', 18, 8)->nullable();
        $table->timestamps();
    });

    Schema::create('account_balance_history', function (Blueprint $table): void {
        $table->id();
        $table->unsignedBigInteger('account_id');
        $table->decimal('total_wallet_balance', 18, 8);
        $table->timestamps();
    });

    Schema::create('market_regime_snapshots', function (Blueprint $table): void {
        $table->id();
        $table->dateTime('computed_at')->nullable();
        $table->decimal('vol_expansion_value', 8, 4)->nullable();
        $table->boolean('vol_expansion_fired')->default(false);
        $table->decimal('range_blowout_value', 8, 4)->nullable();
        $table->boolean('range_blowout_fired')->default(false);
        $table->decimal('corr_regime_value', 6, 4)->nullable();
        $table->boolean('corr_regime_fired')->default(false);
        $table->decimal('rejection_pct_value', 6, 2)->nullable();
        $table->boolean('rejection_pct_fired')->default(false);
        $table->decimal('fut_vol_value', 8, 4)->nullable();
        $table->boolean('fut_vol_fired')->default(false);
    });
}

it('issues a scoped expiring token for valid credentials', function (): void {
    $now = CarbonImmutable::parse('2026-07-19 12:00:00');
    $this->travelTo($now);

    $user = User::factory()->create([
        'email' => 'trader@kraite.test',
        'password' => 'correct-password',
    ]);

    $response = $this->postJson('https://api.kraite.com/v1/auth/token', [
        'email' => 'TRADER@kraite.test',
        'password' => 'correct-password',
        'device_name' => 'Bruno iPhone',
    ])->assertOk()
        ->assertHeader('Strict-Transport-Security', 'max-age=31536000; includeSubDomains')
        ->assertJsonPath('token_type', 'Bearer')
        ->assertJsonPath('user.id', $user->id)
        ->assertJsonMissingPath('user.password');

    expect($response->json('token'))->toBeString()->not->toBeEmpty()
        ->and($response->json('expires_at'))->toBeString();

    $this->assertDatabaseHas('personal_access_tokens', [
        'tokenable_id' => $user->id,
        'name' => 'Bruno iPhone',
        'abilities' => json_encode(['dashboard:read', 'accounts:write']),
        'expires_at' => $now->addDays(30)->format('Y-m-d H:i:s'),
    ]);
});

it('refuses to issue a trader token to a sysadmin', function (): void {
    $admin = User::factory()->create([
        'email' => 'mobile-admin@kraite.test',
        'password' => 'correct-password',
        'is_admin' => true,
    ]);

    $this->postJson('https://api.kraite.com/v1/auth/token', [
        'email' => $admin->email,
        'password' => 'correct-password',
        'device_name' => 'Admin iPhone',
    ])->assertForbidden();

    expect(DB::table('personal_access_tokens')
        ->where('tokenable_type', $admin->getMorphClass())
        ->where('tokenable_id', $admin->id)
        ->exists())->toBeFalse();
});

it('rejects an existing sysadmin token from trader mobile routes', function (): void {
    $admin = User::factory()->create([
        'email' => 'existing-token-admin@kraite.test',
        'is_admin' => true,
    ]);

    Sanctum::actingAs($admin, ['dashboard:read', 'accounts:write']);

    $this->getJson('https://api.kraite.com/v1/accounts')
        ->assertForbidden();
});

it('rejects invalid credentials without issuing a token', function (): void {
    User::factory()->create([
        'email' => 'trader@kraite.test',
        'password' => 'correct-password',
    ]);

    $this->postJson('https://api.kraite.com/v1/auth/token', [
        'email' => 'trader@kraite.test',
        'password' => 'wrong-password',
    ])->assertUnprocessable()
        ->assertJsonValidationErrors('email');

    $this->assertDatabaseCount('personal_access_tokens', 0);
});

it('locks repeated invalid mobile login attempts', function (): void {
    User::factory()->create([
        'email' => 'locked-trader@kraite.test',
        'password' => 'correct-password',
    ]);

    foreach (range(1, 5) as $attempt) {
        $this->postJson('https://api.kraite.com/v1/auth/token', [
            'email' => 'locked-trader@kraite.test',
            'password' => 'wrong-password-'.$attempt,
        ])->assertUnprocessable();
    }

    $this->postJson('https://api.kraite.com/v1/auth/token', [
        'email' => 'locked-trader@kraite.test',
        'password' => 'correct-password',
    ])->assertUnprocessable()
        ->assertJsonPath(
            'errors.email.0',
            fn (string $message): bool => str_contains($message, 'Too many login attempts'),
        );

    $this->assertDatabaseCount('personal_access_tokens', 0);
});

it('requires the read ability for the mobile dashboard', function (): void {
    Sanctum::actingAs(User::factory()->create(), ['profile:read']);

    $this->getJson('https://api.kraite.com/v1/dashboard')
        ->assertForbidden();
});

it('rejects a non-integer dashboard account identifier', function (): void {
    Sanctum::actingAs(User::factory()->create(), ['dashboard:read']);

    $this->getJson('https://api.kraite.com/v1/dashboard?account_id=not-an-id')
        ->assertUnprocessable()
        ->assertJsonValidationErrors('account_id');
});

it('returns only the authenticated traders accounts', function (): void {
    $owner = User::factory()->create();
    $intruder = User::factory()->create();
    $apiSystemId = DB::table('api_systems')->insertGetId(['name' => 'Binance']);
    $foreignAccountId = DB::table('accounts')->insertGetId([
        'user_id' => $owner->id,
        'api_system_id' => $apiSystemId,
        'name' => 'Owner account',
        'created_at' => now(),
        'updated_at' => now(),
    ]);

    Sanctum::actingAs($intruder, ['dashboard:read']);

    $this->getJson('https://api.kraite.com/v1/dashboard')
        ->assertOk()
        ->assertJsonPath('data.accounts', [])
        ->assertJsonPath('data.dashboard', null);

    $this->getJson('https://api.kraite.com/v1/dashboard?account_id='.$foreignAccountId)
        ->assertNotFound();
});

it('returns the bounded dashboard payload for an owned account', function (): void {
    $owner = User::factory()->create();
    $apiSystemId = DB::table('api_systems')->insertGetId(['name' => 'Binance']);
    $accountId = DB::table('accounts')->insertGetId([
        'user_id' => $owner->id,
        'api_system_id' => $apiSystemId,
        'name' => 'Main account',
        'is_active' => false,
        'can_trade' => false,
        'created_at' => now(),
        'updated_at' => now(),
    ]);

    $dashboard = Mockery::mock(WebDashboardController::class);
    $dashboard->shouldReceive('mobilePayload')->once()->andReturn([
        'account' => ['id' => $accountId, 'name' => 'Main account', 'exchange' => 'Binance'],
        'kpis' => ['open_count' => 0],
        'last_position_closed_at' => null,
        'positions' => [],
        'generated_at' => now()->toIso8601String(),
    ]);
    app()->instance(WebDashboardController::class, $dashboard);

    Sanctum::actingAs($owner, ['dashboard:read']);

    $this->getJson('https://api.kraite.com/v1/dashboard?account_id='.$accountId)
        ->assertOk()
        ->assertJsonPath('data.accounts.0.id', $accountId)
        ->assertJsonPath('data.selected_account_id', $accountId)
        ->assertJsonPath('data.dashboard.last_position_closed_at', null)
        ->assertJsonPath('data.dashboard.positions', []);
});

it('returns exact BTC price, four-hour closes, and four candle directions with the mobile dashboard', function (): void {
    $now = CarbonImmutable::parse('2026-08-02 12:00:00');
    $this->travelTo($now);
    prepareMobileDashboardDataSchema();

    $owner = User::factory()->create([
        'name' => 'BTC dashboard owner',
        'email' => 'btc-dashboard-owner@kraite.test',
    ]);
    $apiSystemId = DB::table('api_systems')->insertGetId([
        'name' => 'Binance',
        'canonical' => 'binance',
    ]);
    DB::table('kraite')->where('id', 1)->update([
        'timeframes' => json_encode(['1h', '4h', '12h', '1d'], JSON_THROW_ON_ERROR),
    ]);
    $accountId = DB::table('accounts')->insertGetId([
        'user_id' => $owner->id,
        'api_system_id' => $apiSystemId,
        'name' => 'Main account',
        'created_at' => now(),
        'updated_at' => now(),
    ]);
    $symbolId = DB::table('symbols')->insertGetId([
        'token' => 'BTC',
        'name' => 'Bitcoin',
        'image_url' => 'https://assets.kraite.test/btc.png',
    ]);
    $exchangeSymbolId = DB::table('exchange_symbols')->insertGetId([
        'symbol_id' => $symbolId,
        'api_system_id' => $apiSystemId,
        'quote' => 'USDT',
        'price_precision' => 2,
        'tick_size' => '0.01',
    ]);
    DB::table('exchange_symbol_prices')->insert([
        'exchange_symbol_id' => $exchangeSymbolId,
        'mark_price' => '68234.56700000',
        'mark_price_synced_at' => now(),
        'created_at' => now(),
        'updated_at' => now(),
    ]);
    DB::table('candles')->insert([
        'exchange_symbol_id' => $exchangeSymbolId,
        'timeframe' => '15m',
        'open' => '99999',
        'close' => '99999',
        'timestamp' => $now->subMinutes(255)->timestamp,
    ]);

    foreach (['1d' => '67000', '12h' => '69000', '4h' => '68234.567', '1h' => '68000'] as $timeframe => $open) {
        DB::table('candles')->insert([
            'exchange_symbol_id' => $exchangeSymbolId,
            'timeframe' => $timeframe,
            'open' => $open,
            'close' => '68234.567',
            'timestamp' => $now->timestamp,
        ]);
    }

    foreach (range(0, 16) as $index) {
        DB::table('candles')->insert([
            'exchange_symbol_id' => $exchangeSymbolId,
            'timeframe' => '15m',
            'open' => (string) (68000 + $index),
            'close' => (string) (68000 + $index),
            'timestamp' => $now->subMinutes((16 - $index) * 15)->timestamp,
        ]);
    }

    $beforeCandles = DB::table('candles')
        ->where('exchange_symbol_id', $exchangeSymbolId)
        ->orderBy('id')
        ->get()
        ->all();
    $beforePrice = DB::table('exchange_symbol_prices')
        ->where('exchange_symbol_id', $exchangeSymbolId)
        ->first();

    Sanctum::actingAs($owner, ['dashboard:read']);

    $this->getJson('https://api.kraite.com/v1/dashboard?account_id='.$accountId)
        ->assertOk()
        ->assertJsonPath('data.dashboard.btc.token', 'BTC')
        ->assertJsonPath('data.dashboard.btc.name', 'Bitcoin')
        ->assertJsonPath('data.dashboard.btc.mark', '68234.56')
        ->assertJsonCount(17, 'data.dashboard.btc.spark_4h')
        ->assertJsonPath('data.dashboard.btc.spark_4h.0', 68000)
        ->assertJsonPath('data.dashboard.btc.spark_4h.16', 68016)
        ->assertJsonCount(4, 'data.dashboard.btc.dots')
        ->assertJsonPath('data.dashboard.btc.dots.0.timeframe', '1d')
        ->assertJsonPath('data.dashboard.btc.dots.0.direction', 'up')
        ->assertJsonPath('data.dashboard.btc.dots.1.direction', 'down')
        ->assertJsonPath('data.dashboard.btc.dots.2.direction', 'flat')
        ->assertJsonPath('data.dashboard.btc.dots.3.direction', 'up');

    expect(DB::table('candles')
        ->where('exchange_symbol_id', $exchangeSymbolId)
        ->orderBy('id')
        ->get()
        ->all())->toEqual($beforeCandles)
        ->and(DB::table('exchange_symbol_prices')
            ->where('exchange_symbol_id', $exchangeSymbolId)
            ->first())->toEqual($beforePrice);
});

it('returns the latest clean close for only the selected account', function (): void {
    $now = CarbonImmutable::parse('2026-07-21 12:00:00');
    $this->travelTo($now);
    prepareMobileDashboardDataSchema();

    $owner = User::factory()->create();
    $otherOwner = User::factory()->create();
    $apiSystemId = DB::table('api_systems')->insertGetId(['name' => 'Binance']);
    $accountId = DB::table('accounts')->insertGetId([
        'user_id' => $owner->id,
        'api_system_id' => $apiSystemId,
        'name' => 'Selected account',
        'created_at' => now(),
        'updated_at' => now(),
    ]);
    $otherAccountId = DB::table('accounts')->insertGetId([
        'user_id' => $otherOwner->id,
        'api_system_id' => $apiSystemId,
        'name' => 'Other account',
        'created_at' => now(),
        'updated_at' => now(),
    ]);

    DB::table('positions')->insert([
        [
            'account_id' => $accountId,
            'status' => 'closed',
            'closed_at' => $now->subMinutes(20),
            'created_at' => now(),
            'updated_at' => now(),
        ],
        [
            'account_id' => $accountId,
            'status' => 'closed',
            'closed_at' => $now->subMinutes(5),
            'created_at' => now(),
            'updated_at' => now(),
        ],
        [
            'account_id' => $accountId,
            'status' => 'cancelled',
            'closed_at' => $now->subMinute(),
            'created_at' => now(),
            'updated_at' => now(),
        ],
        [
            'account_id' => $otherAccountId,
            'status' => 'closed',
            'closed_at' => $now,
            'created_at' => now(),
            'updated_at' => now(),
        ],
    ]);
    $before = DB::table('positions')->orderBy('id')->get()->all();

    Sanctum::actingAs($owner, ['dashboard:read']);

    $this->getJson('https://api.kraite.com/v1/dashboard?account_id='.$accountId)
        ->assertOk()
        ->assertJsonPath('data.dashboard.btc', null)
        ->assertJsonPath(
            'data.dashboard.last_position_closed_at',
            $now->subMinutes(5)->toIso8601String(),
        );

    expect(DB::table('positions')->orderBy('id')->get()->all())->toEqual($before);
});

it('returns no last close when the account has never cleanly closed a position', function (): void {
    prepareMobileDashboardDataSchema();

    $owner = User::factory()->create();
    $apiSystemId = DB::table('api_systems')->insertGetId(['name' => 'Binance']);
    $accountId = DB::table('accounts')->insertGetId([
        'user_id' => $owner->id,
        'api_system_id' => $apiSystemId,
        'name' => 'Never closed account',
        'created_at' => now(),
        'updated_at' => now(),
    ]);
    DB::table('positions')->insert([
        'account_id' => $accountId,
        'status' => 'failed',
        'closed_at' => now(),
        'created_at' => now(),
        'updated_at' => now(),
    ]);

    Sanctum::actingAs($owner, ['dashboard:read']);

    $this->getJson('https://api.kraite.com/v1/dashboard?account_id='.$accountId)
        ->assertOk()
        ->assertJsonPath('data.dashboard.last_position_closed_at', null);
});

it('returns the exact bounded BSCS summary for the mobile market-regime tile', function (): void {
    $now = CarbonImmutable::parse('2026-07-19 12:00:00');
    $this->travelTo($now);
    prepareMobileDashboardDataSchema();

    $owner = User::factory()->create([
        'name' => 'Mobile BSCS fragile owner',
        'email' => 'mobile-bscs-fragile@kraite.test',
    ]);
    $apiSystemId = DB::table('api_systems')->insertGetId(['name' => 'Binance']);
    $accountId = DB::table('accounts')->insertGetId([
        'user_id' => $owner->id,
        'api_system_id' => $apiSystemId,
        'name' => 'BSCS fragile account',
        'total_positions_long' => 6,
        'total_positions_short' => 6,
        'created_at' => now(),
        'updated_at' => now(),
    ]);
    DB::table('kraite')->where('id', 1)->update([
        'allow_opening_positions' => true,
        'bscs_score' => 67,
        'bscs_band' => 'fragile',
        'bscs_synced_at' => now()->subMinutes(30),
        'bscs_block_threshold' => 80,
        'bscs_cooldown_until' => null,
    ]);
    DB::table('market_regime_snapshots')->insert([
        'computed_at' => now()->subMinutes(12),
        'vol_expansion_value' => 1.4213,
        'vol_expansion_fired' => true,
        'range_blowout_value' => 1.6800,
        'range_blowout_fired' => true,
        'corr_regime_value' => 0.5122,
        'corr_regime_fired' => false,
        'rejection_pct_value' => -7.25,
        'rejection_pct_fired' => true,
        'fut_vol_value' => 1.0500,
        'fut_vol_fired' => false,
    ]);
    $before = DB::table('kraite')->where('id', 1)->first();

    Sanctum::actingAs($owner, ['dashboard:read']);

    $response = $this->getJson('https://api.kraite.com/v1/dashboard?account_id='.$accountId)
        ->assertOk()
        ->assertJsonPath('data.dashboard.bscs.score', 67)
        ->assertJsonPath('data.dashboard.bscs.band', 'fragile')
        ->assertJsonPath('data.dashboard.bscs.blocked', false)
        ->assertJsonPath('data.dashboard.bscs.status', 'New trades use smaller size.')
        ->assertJsonPath('data.dashboard.bscs.is_stale', false)
        ->assertJsonPath('data.dashboard.bscs.block_threshold', 80)
        ->assertJsonPath('data.dashboard.bscs.computed_ago', '12m ago')
        // Frozen at 12:00 sharp; the hourly recompute lands at minute 50.
        ->assertJsonPath('data.dashboard.bscs.next_compute_in', 'in 50m')
        ->assertJsonPath('data.dashboard.bscs.position_cap.long.effective', 3)
        ->assertJsonPath('data.dashboard.bscs.position_cap.long.maximum', 6)
        ->assertJsonPath('data.dashboard.bscs.position_cap.short.effective', 3)
        ->assertJsonPath('data.dashboard.bscs.position_cap.short.maximum', 6)
        ->assertJsonPath('data.dashboard.bscs.position_cap.ratio_percent', 50)
        ->assertJsonPath('data.dashboard.bscs.paused', false)
        ->assertJsonPath('data.dashboard.bscs.pause_reason', null)
        ->assertJsonPath('data.dashboard.bscs.cooldown_until', null);

    expect(array_keys($response->json('data.dashboard.bscs')))->toBe([
        'score',
        'band',
        'blocked',
        'paused',
        'pause_reason',
        'cooldown_remaining',
        'cooldown_until',
        'status',
        'is_stale',
        'block_threshold',
        'computed_ago',
        'next_compute_in',
        'position_cap',
        'components',
    ])->and($response->json('data.dashboard.bscs.components'))->toBe([
        ['key' => 'vol_expansion', 'label' => 'Vol expansion', 'value' => 1.42, 'fired' => true],
        ['key' => 'range_blowout', 'label' => 'Range blowout', 'value' => 1.68, 'fired' => true],
        ['key' => 'corr_regime', 'label' => 'Correlation regime', 'value' => 0.51, 'fired' => false],
        ['key' => 'rejection_pct', 'label' => 'Rejection %', 'value' => -7.25, 'fired' => true],
        ['key' => 'fut_vol', 'label' => 'Futures vol', 'value' => 1.05, 'fired' => false],
    ])->and(DB::table('kraite')->where('id', 1)->first())->toEqual($before);
});

it('returns an empty BSCS sub-signal list before the first compute lands', function (): void {
    $this->travelTo(CarbonImmutable::parse('2026-07-19 12:00:00'));
    prepareMobileDashboardDataSchema();

    $owner = User::factory()->create([
        'name' => 'Mobile BSCS pre-compute owner',
        'email' => 'mobile-bscs-precompute@kraite.test',
    ]);
    $apiSystemId = DB::table('api_systems')->insertGetId(['name' => 'Binance']);
    $accountId = DB::table('accounts')->insertGetId([
        'user_id' => $owner->id,
        'api_system_id' => $apiSystemId,
        'name' => 'BSCS pre-compute account',
        'total_positions_long' => 6,
        'total_positions_short' => 6,
        'created_at' => now(),
        'updated_at' => now(),
    ]);

    Sanctum::actingAs($owner, ['dashboard:read']);

    $this->getJson('https://api.kraite.com/v1/dashboard?account_id='.$accountId)
        ->assertOk()
        ->assertJsonPath('data.dashboard.bscs.components', [])
        ->assertJsonPath('data.dashboard.bscs.computed_ago', null);
});

it('surfaces the error-storm monitor latch on the mobile tile with no false resumption time', function (): void {
    prepareMobileDashboardDataSchema();

    $owner = User::factory()->create([
        'name' => 'Mobile latch owner',
        'email' => 'mobile-bscs-latch@kraite.test',
    ]);
    $apiSystemId = DB::table('api_systems')->insertGetId(['name' => 'Binance']);
    $accountId = DB::table('accounts')->insertGetId([
        'user_id' => $owner->id,
        'api_system_id' => $apiSystemId,
        'name' => 'Latch account',
        'created_at' => now(),
        'updated_at' => now(),
    ]);

    // The 2026-07-27 shape: BSCS calm, no cooldown — but the error-storm
    // monitor flipped the openings switch off. The tile must say paused,
    // name the monitor, and promise no resumption time.
    DB::table('kraite')->where('id', 1)->update([
        'allow_opening_positions' => false,
        'bscs_score' => 0,
        'bscs_band' => 'calm',
        'bscs_synced_at' => now(),
        'bscs_block_threshold' => 80,
        'bscs_cooldown_until' => null,
    ]);

    Sanctum::actingAs($owner, ['dashboard:read']);

    $this->getJson('https://api.kraite.com/v1/dashboard?account_id='.$accountId)
        ->assertOk()
        ->assertJsonPath('data.dashboard.bscs.paused', true)
        ->assertJsonPath('data.dashboard.bscs.pause_reason', 'monitor')
        ->assertJsonPath('data.dashboard.bscs.blocked', false)
        ->assertJsonPath('data.dashboard.bscs.cooldown_until', null)
        ->assertJsonPath('data.dashboard.bscs.cooldown_remaining', null)
        ->assertJsonPath('data.dashboard.bscs.status', 'New trades paused.');
});

it('surfaces a shock cooldown on the mobile tile with its until-time', function (): void {
    $now = CarbonImmutable::parse('2026-07-19 12:00:00');
    $this->travelTo($now);
    prepareMobileDashboardDataSchema();

    $owner = User::factory()->create([
        'name' => 'Mobile shock owner',
        'email' => 'mobile-bscs-shock@kraite.test',
    ]);
    $apiSystemId = DB::table('api_systems')->insertGetId(['name' => 'Binance']);
    $accountId = DB::table('accounts')->insertGetId([
        'user_id' => $owner->id,
        'api_system_id' => $apiSystemId,
        'name' => 'Shock account',
        'created_at' => now(),
        'updated_at' => now(),
    ]);

    // Fast breaker shape: calm sub-threshold score with an armed cooldown.
    DB::table('kraite')->where('id', 1)->update([
        'allow_opening_positions' => true,
        'bscs_score' => 10,
        'bscs_band' => 'calm',
        'bscs_synced_at' => now(),
        'bscs_block_threshold' => 80,
        'bscs_cooldown_until' => $now->addMinutes(45),
    ]);

    Sanctum::actingAs($owner, ['dashboard:read']);

    $response = $this->getJson('https://api.kraite.com/v1/dashboard?account_id='.$accountId)
        ->assertOk()
        ->assertJsonPath('data.dashboard.bscs.paused', true)
        ->assertJsonPath('data.dashboard.bscs.pause_reason', 'shock')
        ->assertJsonPath('data.dashboard.bscs.status', 'New trades paused.');

    expect((string) $response->json('data.dashboard.bscs.cooldown_until'))
        ->toContain('2026-07-19T12:45:00')
        ->and($response->json('data.dashboard.bscs.cooldown_remaining'))->not->toBeNull();
});

it('returns an explicit stale no-data BSCS summary before the first compute', function (): void {
    prepareMobileDashboardDataSchema();

    $owner = User::factory()->create([
        'name' => 'Mobile BSCS awaiting owner',
        'email' => 'mobile-bscs-awaiting@kraite.test',
    ]);
    $apiSystemId = DB::table('api_systems')->insertGetId(['name' => 'Binance']);
    $accountId = DB::table('accounts')->insertGetId([
        'user_id' => $owner->id,
        'api_system_id' => $apiSystemId,
        'name' => 'BSCS awaiting account',
        'created_at' => now(),
        'updated_at' => now(),
    ]);

    // The stub row defaults the openings switch OFF; production default is
    // on. Pin it so this test exercises "awaiting compute", not the pause.
    DB::table('kraite')->where('id', 1)->update(['allow_opening_positions' => true]);

    Sanctum::actingAs($owner, ['dashboard:read']);

    $this->getJson('https://api.kraite.com/v1/dashboard?account_id='.$accountId)
        ->assertOk()
        ->assertJsonPath('data.dashboard.bscs.score', null)
        ->assertJsonPath('data.dashboard.bscs.band', null)
        ->assertJsonPath('data.dashboard.bscs.blocked', false)
        ->assertJsonPath('data.dashboard.bscs.status', 'Awaiting first compute…')
        ->assertJsonPath('data.dashboard.bscs.is_stale', true)
        ->assertJsonPath('data.dashboard.bscs.block_threshold', 80);
});

it('returns the critical boundary as blocked while its cooldown is active', function (): void {
    $now = CarbonImmutable::parse('2026-07-19 12:00:00');
    $this->travelTo($now);
    prepareMobileDashboardDataSchema();

    $owner = User::factory()->create([
        'name' => 'Mobile BSCS critical owner',
        'email' => 'mobile-bscs-critical@kraite.test',
    ]);
    $apiSystemId = DB::table('api_systems')->insertGetId(['name' => 'Binance']);
    $accountId = DB::table('accounts')->insertGetId([
        'user_id' => $owner->id,
        'api_system_id' => $apiSystemId,
        'name' => 'BSCS critical account',
        'created_at' => now(),
        'updated_at' => now(),
    ]);
    DB::table('kraite')->where('id', 1)->update([
        'bscs_score' => 80,
        'bscs_band' => 'critical',
        'bscs_synced_at' => now()->subMinutes(30),
        'bscs_block_threshold' => 80,
        'bscs_cooldown_until' => now()->addHours(20),
    ]);

    Sanctum::actingAs($owner, ['dashboard:read']);

    $this->getJson('https://api.kraite.com/v1/dashboard?account_id='.$accountId)
        ->assertOk()
        ->assertJsonPath('data.dashboard.bscs.score', 80)
        ->assertJsonPath('data.dashboard.bscs.band', 'critical')
        ->assertJsonPath('data.dashboard.bscs.blocked', true)
        ->assertJsonPath('data.dashboard.bscs.status', 'New trades paused.')
        ->assertJsonPath('data.dashboard.bscs.is_stale', false)
        ->assertJsonPath('data.dashboard.bscs.block_threshold', 80);
});

it('revokes only the current token on logout', function (): void {
    $user = User::factory()->create();
    $current = $user->createToken('Current', ['dashboard:read']);
    $user->createToken('Other device', ['dashboard:read']);

    $this->withToken($current->plainTextToken)
        ->deleteJson('https://api.kraite.com/v1/auth/token')
        ->assertNoContent();

    expect($user->tokens()->pluck('name')->all())->toBe(['Other device']);
});
