<?php

declare(strict_types=1);

/**
 * The fleet-metrics writer/reader use a dedicated, UNPREFIXED `fleet` Redis
 * connection registered by CoreServiceProvider. It MUST be registered in
 * register() — registering it in boot() left it invisible to long-running
 * Horizon workers whose `redis` manager singleton had already snapshotted
 * `config('database.redis')` (for the queue) before the boot-time injection,
 * so `Redis::connection('fleet')` threw "not configured" and silently killed
 * every box's heartbeat (prod incident 2026-06-12). This pins the connection's
 * presence + shape so the registration can't quietly regress.
 */
it('registers an unprefixed fleet redis connection on the kraite database', function (): void {
    $cfg = config('database.redis.fleet');

    expect($cfg)->toBeArray()
        ->and($cfg['database'] ?? null)->toBe((int) config('kraite.fleet_metrics.redis_database', 2))
        ->and($cfg['options']['prefix'] ?? null)->toBe('');
});

it('resolves the fleet connection even after the redis manager is already resolved', function (): void {
    // Mirror a worker that touched Redis (the queue) before the fleet key:
    // resolve the manager first, then assert the fleet connection is present.
    app('redis')->connection();

    expect(fn () => app('redis')->connection('fleet'))->not->toThrow(Throwable::class);
});
