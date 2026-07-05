<?php

declare(strict_types=1);

use App\Models\User;

/**
 * The commands console proxies every read through the ingestion app —
 * in-process when the ingestion checkout exists locally, over SSH when it
 * doesn't (admin lives on pheme, ingestion on athena since 2026-06-01).
 * A missing/broken bridge must degrade to an empty console, never a 500.
 */
function brokenIngestionBridge(): void
{
    config([
        'kraite.ingestion_path' => '/nonexistent/ingestion.kraite.com',
        'kraite.ingestion_ssh_host' => null,
        'kraite.ingestion_ssh_user' => null,
        'kraite.ingestion_ssh_key' => null,
        'kraite.ingestion_remote_path' => null,
    ]);
}

it('renders the commands page even when the ingestion bridge is unreachable', function (): void {
    brokenIngestionBridge();

    $admin = User::factory()->create(['is_admin' => true]);

    $this->actingAs($admin)
        ->get(route('system.commands'))
        ->assertSuccessful();
});

it('returns a friendly error from command details when the ingestion bridge is unreachable', function (): void {
    brokenIngestionBridge();

    $admin = User::factory()->create(['is_admin' => true]);

    $this->actingAs($admin)
        ->getJson(route('system.commands.details', ['command' => 'kraite:cron-sync-orders']))
        ->assertUnprocessable();
});
