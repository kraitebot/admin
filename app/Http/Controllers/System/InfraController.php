<?php

declare(strict_types=1);

namespace App\Http\Controllers\System;

use App\Http\Controllers\Controller;
use Illuminate\View\View;
use Kraite\Core\Models\Server;

class InfraController extends Controller
{
    /**
     * Infrastructure surface — the SERVER layer beneath the fleet. Exchange
     * connectivity lives on its own `system.exchanges` page, so this page is
     * scoped to hosts: the egress-IP allowlist (server-rendered from the real
     * roster) plus live node reachability + control-plane vitals (both polled
     * client-side from the existing dashboard JSON feeds).
     */
    public function index(): View
    {
        return view('system.infra', [
            'egressIps' => $this->egressIps(),
        ]);
    }

    /**
     * Real Kraite egress IPs traders allowlist on the exchange side: every
     * `servers` row flagged `is_apiable` (the boxes that make outbound exchange
     * API calls). Non-apiable hosts (DB, web) never touch an exchange, so they
     * are not part of the allowlist. The `servers` table is the runtime fleet
     * roster — environment-scoped by the seeder — so a local box shows just
     * itself while production shows the full apiable fleet.
     *
     * @return array<int, array{id: string, ip: string, type: string|null}>
     */
    private function egressIps(): array
    {
        return Server::query()
            ->where('is_apiable', true)
            ->orderBy('id')
            ->get(['hostname', 'ip_address', 'type'])
            ->map(fn (Server $server): array => [
                'id' => $server->hostname,
                'ip' => (string) $server->ip_address,
                'type' => $server->type,
            ])
            ->filter(fn (array $row): bool => $row['ip'] !== '')
            ->values()
            ->all();
    }
}
