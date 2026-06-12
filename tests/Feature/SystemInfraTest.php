<?php

declare(strict_types=1);

use App\Models\User;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * The Infra console page is scoped to the SERVER layer: it server-renders the
 * egress-IP allowlist straight from the real `servers` table (apiable hosts
 * only) and client-polls the existing dashboard feeds for live node
 * reachability + the console control plane. The `servers` table is core-owned
 * (excluded from the SQLite suite), so stub the minimum shape the egress query
 * selects. These tests pin the access gate, the real-data egress wiring, and
 * the live-feed bootstrap.
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
        ['hostname' => 'athena', 'ip_address' => '37.27.243.164', 'is_apiable' => true, 'type' => 'ingestion'],
        ['hostname' => 'eos', 'ip_address' => '204.168.137.153', 'is_apiable' => true, 'type' => 'worker'],
        ['hostname' => 'hyperion', 'ip_address' => '135.181.93.226', 'is_apiable' => false, 'type' => 'database'],
        ['hostname' => 'pheme', 'ip_address' => '62.238.38.113', 'is_apiable' => false, 'type' => 'web'],
    ]);
});

afterEach(function (): void {
    Schema::dropIfExists('servers');
});

it('redirects guests on the infra console page to login', function (): void {
    $this->get('https://admin.kraite.test/system/infra')
        ->assertRedirect();
});

it('forbids non-admin users from the infra console page', function (): void {
    $user = User::factory()->create(['is_admin' => false]);

    $this->actingAs($user)
        ->get('https://admin.kraite.test/system/infra')
        ->assertForbidden();
});

it('renders the infra workspace for admins with the live feeds wired', function (): void {
    $admin = User::factory()->create(['is_admin' => true]);

    $response = $this->actingAs($admin)
        ->get('https://admin.kraite.test/system/infra');

    $response->assertSuccessful();
    $response->assertSee('Infrastructure', false);
    $response->assertSee('infraPage(', false);
    // Both live endpoints the page polls are bootstrapped in (@js escapes the
    // URL slashes, so the rendered path reads `dashboard\/data`).
    $response->assertSee('dashboard\/data', false);
    $response->assertSee('dashboard\/health', false);
});

it('server-renders only the real apiable-host egress IPs from the fleet roster', function (): void {
    $admin = User::factory()->create(['is_admin' => true]);

    $response = $this->actingAs($admin)
        ->get('https://admin.kraite.test/system/infra');

    // Apiable hosts appear in the allowlist…
    $response->assertSee('37.27.243.164', false);
    $response->assertSee('204.168.137.153', false);
    // …non-apiable hosts (DB, web) never make exchange calls, so they don't.
    $response->assertDontSee('135.181.93.226', false);
    $response->assertDontSee('62.238.38.113', false);
});
