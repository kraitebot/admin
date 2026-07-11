<?php

declare(strict_types=1);

use App\Http\Controllers\DashboardController;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * The trader dashboard's Server connectivity card lists the apiable fleet in
 * its idle state and fans the on-demand check out through the engine's
 * existing connectivity workflow (core API endpoints). These tests pin the
 * roster shaping: only API-calling, whitelist-needing hosts appear.
 */
beforeEach(function (): void {
    Schema::create('servers', function (Blueprint $table): void {
        $table->id();
        $table->string('hostname');
        $table->string('ip_address')->nullable();
        $table->boolean('is_apiable')->default(false);
        $table->boolean('needs_whitelisting')->default(false);
        $table->string('type')->nullable();
        $table->string('own_queue_name')->nullable();
    });
});

afterEach(function (): void {
    Schema::dropIfExists('servers');
});

function buildConnectivityServers(): array
{
    $method = new ReflectionMethod(DashboardController::class, 'connectivityServers');

    return $method->invoke(new DashboardController);
}

it('lists only apiable whitelist-needing servers for the connectivity card', function (): void {
    DB::table('servers')->insert([
        ['hostname' => 'eos', 'ip_address' => '204.168.137.153', 'is_apiable' => true, 'needs_whitelisting' => true, 'type' => 'worker'],
        ['hostname' => 'athena', 'ip_address' => '37.27.243.164', 'is_apiable' => true, 'needs_whitelisting' => true, 'type' => 'ingestion'],
        // Never tested: non-apiable web host, and an apiable host that
        // doesn't need exchange-side whitelisting.
        ['hostname' => 'pheme', 'ip_address' => '62.238.38.113', 'is_apiable' => false, 'needs_whitelisting' => false, 'type' => 'web'],
        ['hostname' => 'tyche', 'ip_address' => '204.168.135.246', 'is_apiable' => true, 'needs_whitelisting' => false, 'type' => 'worker'],
    ]);

    $rows = buildConnectivityServers();

    expect(array_column($rows, 'hostname'))->toBe(['athena', 'eos'])
        ->and($rows[0]['ip_address'])->toBe('37.27.243.164');
});

it('degrades to an empty roster when the servers table is unavailable', function (): void {
    Schema::dropIfExists('servers');

    expect(buildConnectivityServers())->toBe([]);
});
