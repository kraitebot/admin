<?php

declare(strict_types=1);

use App\Models\User;
use Carbon\CarbonImmutable;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Redis\Connections\Connection;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Redis;
use Illuminate\Support\Facades\Schema;

/**
 * The fleet-overview dashboard serves one payload (page seed + 15s poll) that
 * aggregates trader counts, dispatcher health, capital, regime, deploy drift,
 * revenue, venue connectivity, and the incident feed. Core owns most of those
 * tables (excluded from the SQLite suite), so these tests pin two contracts:
 * the graceful-degradation shape when a source is absent, and the exact math
 * of the sections whose schema can be stubbed portably (regime, revenue,
 * deploy drift).
 */
afterEach(function (): void {
    Schema::dropIfExists('market_regime_snapshots');
    Schema::dropIfExists('subscriptions');
    Schema::dropIfExists('wallet_transactions');
    Schema::dropIfExists('servers');
    Schema::dropIfExists('accounts');
});

it('gates the overview data feed to sysadmins', function (): void {
    $this->get('https://admin.kraite.test/system/dashboard/data')
        ->assertRedirect();

    $trader = User::factory()->create(['is_admin' => false]);
    $this->actingAs($trader)
        ->get('https://admin.kraite.test/system/dashboard/data')
        ->assertForbidden();
});

it('serves real trader counts and degrades every core-owned section to its placeholder shape', function (): void {
    $admin = User::factory()->create(['is_admin' => true, 'email' => 'ovw-admin@kraite.test']);
    $connectedTrader = User::factory()->create(['is_admin' => false, 'email' => 'ovw-active@kraite.test']);
    User::factory()->create(['is_admin' => false, 'email' => 'ovw-no-account@kraite.test']);
    $inactiveTrader = User::factory()->create(['is_admin' => false, 'is_active' => false, 'email' => 'ovw-inactive@kraite.test']);

    // The suite runs without core migrations, so the accounts table the
    // traders KPI joins against is created inline like the other core-owned
    // sources stubbed in this file.
    Schema::create('accounts', function (Blueprint $table): void {
        $table->id();
        $table->unsignedBigInteger('user_id');
        $table->boolean('is_active')->default(false);
        $table->timestamps();
    });
    DB::table('accounts')->insert([
        ['user_id' => $connectedTrader->id, 'is_active' => true, 'created_at' => now(), 'updated_at' => now()],
        ['user_id' => $inactiveTrader->id, 'is_active' => true, 'created_at' => now(), 'updated_at' => now()],
    ]);

    $response = $this->actingAs($admin)
        ->get('https://admin.kraite.test/system/dashboard/data')
        ->assertSuccessful();

    // Traders: only active users holding an active trading account count —
    // the sysadmin, the accountless signup, and the deactivated trader are
    // all excluded. The lone trader signed up inside the 24h window, so the
    // count grew only by signups and no delta badge renders (previous <= 0).
    $response->assertJsonPath('kpis.traders.count', 1)
        ->assertJsonPath('kpis.traders.signups_24h', 1)
        ->assertJsonPath('kpis.traders.delta_pct', null);

    // Every core-owned source is absent in this suite — each section must
    // land on its placeholder instead of taking the payload down.
    $response->assertJsonPath('kpis.tradeable.total', null)
        ->assertJsonPath('kpis.tradeable.exchanges', [])
        ->assertJsonPath('kpis.capital.aum', null)
        ->assertJsonPath('kpis.capital.accounts', 0)
        ->assertJsonPath('kpis.throughput.fleets', [])
        ->assertJsonPath('kpis.open_positions', null)
        ->assertJsonPath('regime.band', null)
        ->assertJsonPath('regime.posture', 'No signal yet')
        ->assertJsonPath('deploy.version', null)
        ->assertJsonPath('deploy.in_sync', null)
        ->assertJsonPath('revenue.mrr', null)
        ->assertJsonPath('venues', [])
        ->assertJsonPath('incidents', [])
        ->assertJsonPath('fleet', []);
});

it('reports the live BSCS band, posture, sparkline, and override audit fields', function (): void {
    Schema::create('market_regime_snapshots', function (Blueprint $table): void {
        $table->id();
        $table->dateTime('computed_at');
        $table->unsignedTinyInteger('bscs_score');
        $table->string('bscs_band', 16);
        $table->timestamps();
    });

    DB::table('market_regime_snapshots')->insert([
        ['computed_at' => now()->subHour(), 'bscs_score' => 55, 'bscs_band' => 'elevated', 'created_at' => now(), 'updated_at' => now()],
        ['computed_at' => now(), 'bscs_score' => 67, 'bscs_band' => 'fragile', 'created_at' => now(), 'updated_at' => now()],
    ]);

    DB::table('kraite')->where('id', 1)->update([
        'bscs_score' => 67,
        'bscs_band' => 'fragile',
        'bscs_synced_at' => now(),
        'bscs_override_reason' => 'Exchange-side IP fix in flight',
    ]);

    $admin = User::factory()->create(['is_admin' => true, 'email' => 'ovw-regime@kraite.test']);

    $response = $this->actingAs($admin)
        ->get('https://admin.kraite.test/system/dashboard/data')
        ->assertSuccessful();

    $response->assertJsonPath('regime.score', 67)
        ->assertJsonPath('regime.band', 'fragile')
        ->assertJsonPath('regime.is_stale', false)
        ->assertJsonPath('regime.posture', 'Margin slice reduced on new opens')
        ->assertJsonPath('regime.override_reason', 'Exchange-side IP fix in flight')
        // Sparkline is chronological: the older elevated point precedes the
        // fragile head.
        ->assertJsonPath('regime.sparkline.0.score', 55)
        ->assertJsonPath('regime.sparkline.1.score', 67)
        ->assertJsonCount(2, 'regime.sparkline');
});

it('computes revenue from unpaused subscribers and only today\'s confirmed top-ups', function (): void {
    Schema::create('subscriptions', function (Blueprint $table): void {
        $table->id();
        $table->string('name');
        $table->decimal('monthly_rate_usdt', 12, 4);
    });
    Schema::create('wallet_transactions', function (Blueprint $table): void {
        $table->id();
        $table->unsignedBigInteger('user_id');
        $table->string('type', 32);
        $table->decimal('amount_usdt', 14, 4);
        $table->timestamp('created_at')->nullable();
    });
    Schema::table('users', function (Blueprint $table): void {
        $table->unsignedBigInteger('subscription_id')->nullable();
        $table->timestamp('subscription_paused_at')->nullable();
        $table->decimal('wallet_balance_usdt', 14, 4)->default(0);
    });

    $proId = DB::table('subscriptions')->insertGetId(['name' => 'Pro', 'monthly_rate_usdt' => 100]);
    $liteId = DB::table('subscriptions')->insertGetId(['name' => 'Lite', 'monthly_rate_usdt' => 50]);

    $admin = User::factory()->create(['is_admin' => true, 'email' => 'ovw-rev-admin@kraite.test']);
    $paying = User::factory()->create(['email' => 'ovw-rev-paying@kraite.test']);
    $paused = User::factory()->create(['email' => 'ovw-rev-paused@kraite.test']);

    DB::table('users')->where('id', $paying->id)->update(['subscription_id' => $proId, 'wallet_balance_usdt' => 30]);
    DB::table('users')->where('id', $paused->id)->update(['subscription_id' => $liteId, 'subscription_paused_at' => now(), 'wallet_balance_usdt' => 20]);

    DB::table('wallet_transactions')->insert([
        // Counts: a confirmed top-up today.
        ['user_id' => $paying->id, 'type' => 'credit_topup', 'amount_usdt' => 25, 'created_at' => now()],
        // Excluded: yesterday's top-up and today's non-top-up ledger row.
        ['user_id' => $paying->id, 'type' => 'credit_topup', 'amount_usdt' => 40, 'created_at' => now()->subDay()->startOfDay()],
        ['user_id' => $paused->id, 'type' => 'debit_admin', 'amount_usdt' => 5, 'created_at' => now()],
    ]);

    $response = $this->actingAs($admin)
        ->get('https://admin.kraite.test/system/dashboard/data')
        ->assertSuccessful();

    // MRR counts the paying user only — the paused subscriber and the
    // unsubscribed admin contribute nothing. Float sums every wallet.
    $response->assertJsonPath('revenue.mrr', 100)
        ->assertJsonPath('revenue.topups_today', 25)
        ->assertJsonPath('revenue.topups_count', 1)
        ->assertJsonPath('revenue.wallet_float', 50);
});

it('derives deploy rollout drift from the versions the fleet heartbeat reports', function (): void {
    Schema::create('servers', function (Blueprint $table): void {
        $table->id();
        $table->string('hostname');
        $table->string('ip_address')->nullable();
        $table->string('type')->nullable();
    });
    DB::table('servers')->insert([
        ['hostname' => 'eos', 'ip_address' => '10.0.0.4', 'type' => 'worker'],
        ['hostname' => 'iris', 'ip_address' => '10.0.0.5', 'type' => 'worker'],
        ['hostname' => 'hyperion', 'ip_address' => '10.0.0.2', 'type' => 'database'],
    ]);
    config(['kraite.fleet_metrics.key_prefix' => 'kraite:fleet:', 'kraite.fleet_metrics.stale_after_seconds' => 720]);

    $conn = Mockery::mock(Connection::class);
    $conn->shouldReceive('get')->with('kraite:fleet:eos')->andReturn(json_encode([
        'reported_at' => CarbonImmutable::now()->toIso8601String(),
        'version' => 'v1.62.0',
    ]));
    $conn->shouldReceive('get')->with('kraite:fleet:iris')->andReturn(json_encode([
        'reported_at' => CarbonImmutable::now()->toIso8601String(),
        'version' => 'v1.61.2',
    ]));
    // hyperion's bash agent reports no version key at all.
    $conn->shouldReceive('get')->with('kraite:fleet:hyperion')->andReturn(json_encode([
        'reported_at' => CarbonImmutable::now()->toIso8601String(),
    ]));
    Redis::shouldReceive('connection')->with('fleet')->andReturn($conn);

    $admin = User::factory()->create(['is_admin' => true, 'email' => 'ovw-deploy@kraite.test']);

    $response = $this->actingAs($admin)
        ->get('https://admin.kraite.test/system/dashboard/data')
        ->assertSuccessful();

    // Drift math: two version-reporting nodes, one on latest; hyperion is
    // excluded from the version universe but still present in the fleet rows.
    $response->assertJsonPath('deploy.version', 'v1.62.0')
        ->assertJsonPath('deploy.reporting', 2)
        ->assertJsonPath('deploy.on_latest', 1)
        ->assertJsonPath('deploy.in_sync', false)
        ->assertJsonPath('deploy.lagging.0.hostname', 'iris')
        ->assertJsonPath('deploy.lagging.0.version', 'v1.61.2')
        ->assertJsonCount(1, 'deploy.lagging')
        ->assertJsonPath('fleet.0.hostname', 'eos')
        ->assertJsonPath('fleet.0.version', 'v1.62.0')
        ->assertJsonPath('fleet.2.hostname', 'hyperion')
        ->assertJsonPath('fleet.2.version', null);
});

it('renders the overview page for admins with the live state seeded', function (): void {
    $admin = User::factory()->create(['is_admin' => true, 'email' => 'ovw-page@kraite.test']);

    $response = $this->actingAs($admin)
        ->get('https://admin.kraite.test/system/dashboard')
        ->assertSuccessful();

    $response->assertSee('Fleet overview', false);
    $response->assertSee('systemDash(', false);
    // The poll + override endpoints are bootstrapped in (@js escapes slashes).
    $response->assertSee('dashboard\/data', false);
    $response->assertSee('bscs\/override\/engage', false);
    $response->assertSee('bscs\/override\/clear', false);
});
