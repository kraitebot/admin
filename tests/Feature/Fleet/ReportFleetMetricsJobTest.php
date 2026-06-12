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
 * The heartbeat's defining behaviour: every run writes a snapshot and
 * re-queues the NEXT run onto this box's own `<hostname>` queue — and that
 * re-dispatch must survive a failed write so the loop can't silently die.
 *
 * The collector + repository are `final`, so the test drives them for real and
 * mocks only the `fleet` Redis connection underneath the repository.
 */
beforeEach(function (): void {
    config([
        // A real queue driver — the heartbeat deliberately refuses to
        // self-reschedule on `sync` (it would recurse inline forever).
        'queue.default' => 'redis',
        'kraite.fleet_metrics.report_interval_seconds' => 300,
        'kraite.fleet_metrics.key_prefix' => 'kraite:fleet:',
        'kraite.fleet_metrics.ttl_seconds' => 604800,
    ]);
});

it('writes a snapshot and reschedules itself onto its own hostname queue', function (): void {
    Bus::fake();

    $conn = Mockery::mock(Connection::class);
    $conn->shouldReceive('setex')->once()->with('kraite:fleet:eos', 604800, Mockery::type('string'));
    Redis::shouldReceive('connection')->with('fleet')->andReturn($conn);

    (new ReportFleetMetricsJob('eos'))->handle(
        app(FleetMetricsCollector::class),
        app(FleetMetricsRepository::class),
    );

    Bus::assertDispatched(
        ReportFleetMetricsJob::class,
        fn (ReportFleetMetricsJob $job): bool => $job->hostname === 'eos' && $job->queue === 'eos',
    );
});

it('keeps the heartbeat alive by rescheduling even when the write throws', function (): void {
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

    Bus::assertDispatched(
        ReportFleetMetricsJob::class,
        fn (ReportFleetMetricsJob $job): bool => $job->hostname === 'iris' && $job->queue === 'iris',
    );
});

it('refuses to self-reschedule on the sync queue (no inline recursion)', function (): void {
    Bus::fake();
    config(['queue.default' => 'sync']);

    $conn = Mockery::mock(Connection::class);
    $conn->shouldReceive('setex')->once();
    Redis::shouldReceive('connection')->with('fleet')->andReturn($conn);

    (new ReportFleetMetricsJob('eos'))->handle(
        app(FleetMetricsCollector::class),
        app(FleetMetricsRepository::class),
    );

    Bus::assertNotDispatched(ReportFleetMetricsJob::class);
});
