<?php

declare(strict_types=1);

use Kraite\Core\Support\Fleet\FleetMetricsCollector;

/**
 * The collector's host-format parsers are the only non-trivial logic in the
 * writer (everything else is a raw /proc / df read). They run on Linux boxes
 * but must be verifiable from any OS, so they're pure + static and tested off
 * fixture strings.
 */
it('parses /proc/uptime to whole seconds', function (): void {
    expect(FleetMetricsCollector::parseUptime("3283200.12 9999.0\n"))->toBe(3283200);
    expect(FleetMetricsCollector::parseUptime('40.5 10.0'))->toBe(40);
    expect(FleetMetricsCollector::parseUptime('garbage'))->toBeNull();
});

it('converts 1-min load to a clamped cpu percent', function (): void {
    expect(FleetMetricsCollector::cpuPercent(1.0, 2))->toBe(50.0);
    expect(FleetMetricsCollector::cpuPercent(4.0, 2))->toBe(100.0); // clamps at 100
    expect(FleetMetricsCollector::cpuPercent(null, 2))->toBeNull();
    expect(FleetMetricsCollector::cpuPercent(1.0, 0))->toBeNull();
});

it('parses meminfo into used/total MiB and a used percent', function (): void {
    $raw = "MemTotal:        4096000 kB\nMemFree:          100000 kB\nMemAvailable:    1024000 kB\n";
    $r = FleetMetricsCollector::parseMeminfo($raw);

    expect($r['total_mb'])->toBe(4000);
    expect($r['used_mb'])->toBe(3000);   // total - available
    expect($r['percent'])->toBe(75.0);
});

it('returns null mem fields when a key is absent', function (): void {
    $r = FleetMetricsCollector::parseMeminfo('Bogus: 1 kB');

    expect($r['total_mb'])->toBeNull();
    expect($r['percent'])->toBeNull();
});

it('parses supervisorctl status into program => state', function (): void {
    $out = <<<'TXT'
    kraite-horizon                   RUNNING   pid 123, uptime 1:02:03
    kraite-dispatch-daemon           FATAL     Exited too quickly (process log may have details)
    kraite-stream-binance-prices     STOPPED   Not started
    TXT;

    expect(FleetMetricsCollector::parseSupervisorStatus($out))->toBe([
        'kraite-horizon' => 'RUNNING',
        'kraite-dispatch-daemon' => 'FATAL',
        'kraite-stream-binance-prices' => 'STOPPED',
    ]);
});

it('parses launchctl list into this-site services, RUNNING when a PID is present', function (): void {
    $out = "PID\tStatus\tLabel\n"
        ."24943\t0\tadmin.kraite.test.horizon\n"
        ."24946\t0\tadmin.kraite.test.scheduler\n"
        ."-\t0\tadmin.kraite.test.dispatch-daemon\n"
        ."1130\t0\tadmin.quanamo.test.horizon\n"      // other site — excluded
        ."72847\t255\tingestion.kraite.test.horizon\n"; // other site — excluded

    expect(FleetMetricsCollector::parseLaunchctlList($out, 'admin.kraite.test'))->toBe([
        'horizon' => 'RUNNING',
        'scheduler' => 'RUNNING',
        'dispatch-daemon' => 'STOPPED', // loaded but no PID
    ]);
});

it('parses vm_stat into used/total MiB and a clamped percent', function (): void {
    $raw = "Mach Virtual Memory Statistics: (page size of 16384 bytes)\n"
        ."Pages free:                      4610.\n"
        ."Pages active:                  300000.\n"
        ."Pages inactive:                380664.\n"
        ."Pages wired down:              100000.\n"
        ."Pages occupied by compressor:   24576.\n";

    // total = 24 GiB; used = (300000 + 100000 + 24576) pages * 16384 bytes.
    $r = FleetMetricsCollector::parseVmStat($raw, 24 * 1024 ** 3);

    expect($r['total_mb'])->toBe(24576);
    expect($r['used_mb'])->toBe((int) round((300000 + 100000 + 24576) * 16384 / 1048576));
    expect($r['percent'])->toBeGreaterThan(0.0)->toBeLessThanOrEqual(100.0);
});

it('returns null vm_stat fields when total is unknown', function (): void {
    $r = FleetMetricsCollector::parseVmStat('Pages active: 1.', 0);

    expect($r['total_mb'])->toBeNull();
    expect($r['percent'])->toBeNull();
});

it('parses systemctl units into name => RUNNING/state (web box)', function (): void {
    $out = "kraite-horizon-admin.service    loaded active   running Kraite Admin Horizon\n"
        ."kraite-horizon-console.service  loaded active   running Kraite Console Horizon\n"
        ."kraite-horizon-kraite.service   loaded failed   failed  Kraite.com Horizon\n";

    expect(FleetMetricsCollector::parseSystemdUnits($out))->toBe([
        'kraite-horizon-admin' => 'RUNNING',
        'kraite-horizon-console' => 'RUNNING',
        'kraite-horizon-kraite' => 'FAILED',
    ]);
});
