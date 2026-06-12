<?php

declare(strict_types=1);

use Carbon\CarbonImmutable;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Redis\Connections\Connection;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Redis;
use Illuminate\Support\Facades\Schema;
use Kraite\Core\Support\Fleet\FleetMetricsRepository;

/**
 * The repository is what both the dashboard endpoint and the watchdog read —
 * it joins the `servers` table roster against the live Redis keys and
 * classifies each host. The `servers` table is core-owned (excluded from the
 * SQLite suite) so its minimum shape is stubbed; the `fleet` Redis connection
 * is mocked so the tests stay hermetic (no real Redis), exercising the
 * roster-join + classification the rest of the feature depends on.
 */
beforeEach(function (): void {
    Schema::create('servers', function (Blueprint $table): void {
        $table->id();
        $table->string('hostname');
        $table->string('ip_address')->nullable();
        $table->boolean('is_apiable')->default(false);
        $table->string('type')->nullable();
        $table->text('description')->nullable();
    });

    DB::table('servers')->insert([
        ['hostname' => 'eos', 'ip_address' => '10.0.0.4', 'type' => 'worker'],
        ['hostname' => 'iris', 'ip_address' => '10.0.0.5', 'type' => 'worker'],
        ['hostname' => 'hyperion', 'ip_address' => '10.0.0.2', 'type' => 'database'],
    ]);

    config([
        'kraite.fleet_metrics.key_prefix' => 'kraite:fleet:',
        'kraite.fleet_metrics.report_interval_seconds' => 300,
        'kraite.fleet_metrics.stale_after_seconds' => 720,
        'kraite.fleet_metrics.ttl_seconds' => 604800,
    ]);
});

afterEach(function (): void {
    Schema::dropIfExists('servers');
});

it('classifies online, stale, and missing hosts against the registry', function (): void {
    $fresh = json_encode([
        'hostname' => 'eos',
        'reported_at' => CarbonImmutable::now()->toIso8601String(),
        'uptime_seconds' => 999999,
        'cpu' => ['percent' => 10.0],
    ]);
    $stale = json_encode([
        'hostname' => 'iris',
        'reported_at' => CarbonImmutable::now()->subMinutes(20)->toIso8601String(),
        'uptime_seconds' => 999999,
    ]);

    $conn = Mockery::mock(Connection::class);
    $conn->shouldReceive('get')->with('kraite:fleet:eos')->andReturn($fresh);
    $conn->shouldReceive('get')->with('kraite:fleet:iris')->andReturn($stale);
    $conn->shouldReceive('get')->with('kraite:fleet:hyperion')->andReturn(null);
    Redis::shouldReceive('connection')->with('fleet')->andReturn($conn);

    $rows = collect(app(FleetMetricsRepository::class)->all())->keyBy('hostname');

    expect($rows['eos']['status'])->toBe('online')
        ->and($rows['eos']['cpu']['percent'])->toEqual(10) // JSON round-trip collapses 10.0 → 10
        ->and($rows['eos']['type'])->toBe('worker')
        ->and($rows['iris']['status'])->toBe('stale')
        ->and($rows['hyperion']['status'])->toBe('missing')
        ->and($rows['hyperion']['cpu'])->toBeNull()
        ->and($rows['hyperion']['units'])->toBe([]);
});

it('flags a freshly-rebooted host (low uptime)', function (): void {
    $payload = json_encode([
        'reported_at' => CarbonImmutable::now()->toIso8601String(),
        'uptime_seconds' => 120, // below 2× the 300s interval
    ]);

    $conn = Mockery::mock(Connection::class);
    $conn->shouldReceive('get')->andReturn($payload);
    Redis::shouldReceive('connection')->with('fleet')->andReturn($conn);

    $eos = collect(app(FleetMetricsRepository::class)->all())->firstWhere('hostname', 'eos');

    expect($eos['recently_rebooted'])->toBeTrue();
});

it('silentHosts returns only the non-online rows', function (): void {
    $conn = Mockery::mock(Connection::class);
    $conn->shouldReceive('get')->andReturn(null); // every host missing
    Redis::shouldReceive('connection')->with('fleet')->andReturn($conn);

    $silent = app(FleetMetricsRepository::class)->silentHosts();

    expect($silent)->toHaveCount(3)
        ->and(collect($silent)->pluck('status')->unique()->values()->all())->toBe(['missing']);
});

it('writes a snapshot with a stamped reported_at to the literal fleet key', function (): void {
    $conn = Mockery::mock(Connection::class);
    $conn->shouldReceive('setex')->once()->withArgs(function ($key, $ttl, $json): bool {
        $decoded = json_decode($json, true);

        return $key === 'kraite:fleet:eos'
            && $ttl === 604800
            && $decoded['hostname'] === 'eos'
            && ! empty($decoded['reported_at']);
    });
    Redis::shouldReceive('connection')->with('fleet')->andReturn($conn);

    app(FleetMetricsRepository::class)->write('eos', ['cpu' => ['percent' => 5.0]]);
});
