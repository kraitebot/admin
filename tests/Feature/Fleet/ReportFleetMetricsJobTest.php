<?php

declare(strict_types=1);

use Illuminate\Redis\Connections\Connection;
use Illuminate\Support\Facades\Bus;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Redis;
use Kraite\Core\Jobs\Fleet\ReportFleetMetricsJob;
use Kraite\Core\Support\Fleet\FleetMetricsCollector;
use Kraite\Core\Support\Fleet\FleetMetricsRepository;

/**
 * The scheduler owns heartbeat cadence. The queued job writes exactly one
 * snapshot and never creates a hidden second cadence by re-dispatching itself.
 */
beforeEach(function (): void {
    config([
        'queue.default' => 'redis',
        'kraite.fleet_metrics.report_interval_seconds' => 300,
        'kraite.fleet_metrics.key_prefix' => 'kraite:fleet:',
        'kraite.fleet_metrics.ttl_seconds' => 604800,
    ]);
});

it('writes one snapshot without rescheduling itself', function (): void {
    Bus::fake();

    $conn = Mockery::mock(Connection::class);
    $conn->shouldReceive('setex')->once()->with('kraite:fleet:eos', 604800, Mockery::type('string'));
    Redis::shouldReceive('connection')->with('fleet')->andReturn($conn);

    (new ReportFleetMetricsJob('eos'))->handle(
        app(FleetMetricsCollector::class),
        app(FleetMetricsRepository::class),
    );

    Bus::assertNotDispatched(ReportFleetMetricsJob::class);
});

it('logs a failed write without creating an unscheduled retry loop', function (): void {
    Bus::fake();
    Log::shouldReceive('channel')->andReturnSelf();
    Log::shouldReceive('error')->andReturnNull();

    $conn = Mockery::mock(Connection::class);
    $conn->shouldReceive('setex')->andThrow(new RuntimeException('redis down'));
    Redis::shouldReceive('connection')->with('fleet')->andReturn($conn);

    (new ReportFleetMetricsJob('iris'))->handle(
        app(FleetMetricsCollector::class),
        app(FleetMetricsRepository::class),
    );

    Bus::assertNotDispatched(ReportFleetMetricsJob::class);
});

it('routes an explicit warmup seed onto the configured heartbeat queue', function (): void {
    Bus::fake();
    config([
        'kraite.fleet_metrics.connection' => 'redis',
        'kraite.fleet_metrics.queue' => 'kraite-heartbeats',
    ]);

    ReportFleetMetricsJob::seed('eos');

    Bus::assertDispatched(
        ReportFleetMetricsJob::class,
        fn (ReportFleetMetricsJob $job): bool => $job->hostname === 'eos'
            && $job->connection === 'redis'
            && $job->queue === 'kraite-heartbeats',
    );
});
