<?php

declare(strict_types=1);

namespace App\Http\Controllers\System;

use App\Http\Controllers\Controller;
use Illuminate\Http\JsonResponse;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Illuminate\View\View;
use Kraite\Core\Models\ExchangeSymbol;
use Kraite\Core\Models\Kraite;
use Kraite\Core\Models\MarketRegimeSnapshot;
use Kraite\Core\Support\Fleet\FleetMetricsRepository;
use Kraite\Core\Support\MarketRegime\BlackSwanIndex;

class DashboardController extends Controller
{
    public function index(): View
    {
        return view('system.dashboard');
    }

    /**
     * Aggregate exchange/symbol/tradeability counts for the system overview.
     * Sysadmin-only feed — every consumer of this endpoint sits inside the
     * `admin` middleware group.
     */
    public function data(): JsonResponse
    {
        $exchanges = DB::table('api_systems')
            ->where('is_exchange', true)
            ->select('id', 'name', 'canonical')
            ->get();

        $exchangeIds = $exchanges->pluck('id');

        $symbolStats = DB::table('exchange_symbols')
            ->whereIn('api_system_id', $exchangeIds)
            ->select(
                'api_system_id',
                DB::raw('COUNT(*) as total'),
                DB::raw("SUM(CASE WHEN direction = 'LONG' THEN 1 ELSE 0 END) as longs"),
                DB::raw("SUM(CASE WHEN direction = 'SHORT' THEN 1 ELSE 0 END) as shorts"),
            )
            ->groupBy('api_system_id')
            ->get()
            ->keyBy('api_system_id');

        // Tradeable counts — delegated to the ExchangeSymbol::tradeable() scope
        // so admin matches the live trader's exact "tradeable" definition. The
        // scope handles the Binance cross-check, was_backtesting_approved,
        // correlation-column lookup, cooldowns, and behavioral flags. Keeping
        // this delegated avoids drift the next time the scope evolves.
        $tradeableCounts = ExchangeSymbol::query()
            ->tradeable()
            ->whereIn('exchange_symbols.api_system_id', $exchangeIds)
            ->select(
                'exchange_symbols.api_system_id',
                DB::raw('COUNT(*) as tradeable'),
                DB::raw("SUM(CASE WHEN exchange_symbols.direction = 'LONG' THEN 1 ELSE 0 END) as tradeable_longs"),
                DB::raw("SUM(CASE WHEN exchange_symbols.direction = 'SHORT' THEN 1 ELSE 0 END) as tradeable_shorts"),
            )
            ->groupBy('exchange_symbols.api_system_id')
            ->get()
            ->keyBy('api_system_id');

        $totalSymbols = DB::table('symbols')->count();

        $result = $exchanges->map(function ($exchange) use ($symbolStats, $tradeableCounts) {
            $stats = $symbolStats->get($exchange->id);
            $tradeable = $tradeableCounts->get($exchange->id);

            return [
                'name' => $exchange->name,
                'canonical' => $exchange->canonical,
                'total' => $stats->total ?? 0,
                'longs' => $stats->longs ?? 0,
                'shorts' => $stats->shorts ?? 0,
                'tradeable' => $tradeable->tradeable ?? 0,
                'tradeable_longs' => $tradeable->tradeable_longs ?? 0,
                'tradeable_shorts' => $tradeable->tradeable_shorts ?? 0,
                'non_tradeable' => ($stats->total ?? 0) - ($tradeable->tradeable ?? 0),
            ];
        });

        return response()->json([
            'exchanges' => $result,
            'total_exchanges' => $exchanges->count(),
            'total_symbols' => $totalSymbols,
            'total_exchange_symbols' => $result->sum('total'),
            'total_tradeable' => $result->sum('tradeable'),
            'total_non_tradeable' => $result->sum('non_tradeable'),
            'total_longs' => $result->sum('tradeable_longs'),
            'total_shorts' => $result->sum('tradeable_shorts'),
            'bscs' => $this->bscsPayload(),
            // Live fleet roster — every kraite.fleet.servers host joined
            // against its heartbeat key, classified online / stale / missing.
            'fleet' => app(FleetMetricsRepository::class)->all(),
        ]);
    }

    /**
     * Black Swan Composite Score payload — full lossless dump for the system
     * dashboard widget (score, band, sub-signals, cooldown state, freshness)
     * plus a 30-snapshot sparkline for trajectory.
     *
     * @return array<string, mixed>
     */
    private function bscsPayload(): array
    {
        $payload = BlackSwanIndex::current()->toArray();
        $payload['override_reason'] = $this->bscsOverrideReason();

        $payload['sparkline'] = MarketRegimeSnapshot::query()
            ->orderByDesc('computed_at')
            ->limit(30)
            ->get(['computed_at', 'bscs_score', 'bscs_band'])
            ->map(fn ($s) => [
                't' => $s->computed_at?->toIso8601String(),
                'score' => (int) $s->bscs_score,
                'band' => $s->bscs_band,
            ])
            ->reverse()
            ->values()
            ->all();

        return $payload;
    }

    /**
     * Manual-override audit reason for the BSCS panel. The override columns
     * (`bscs_override_reason` / `bscs_override_until`) are owned by
     * kraitebot/core — admin holds no schema. Until that migration lands on a
     * given database the column is absent, so this read is gated on column
     * existence: a missing column yields null rather than throwing and taking
     * the ENTIRE dashboard data feed (exchanges, symbols, live fleet) down
     * with it. The existence check is cached so the 15s poll doesn't re-hit
     * information_schema every tick.
     */
    private function bscsOverrideReason(): ?string
    {
        $hasColumn = Cache::remember(
            'system.dashboard.kraite-has-override-reason',
            300,
            static fn (): bool => Schema::hasColumn('kraite', 'bscs_override_reason'),
        );

        return $hasColumn ? Kraite::query()->value('bscs_override_reason') : null;
    }

    /**
     * Vitals JSON for the dashboard's top ribbon — server load, dispatcher
     * throughput, slow-query counts. Polled every 5 s by the ribbon.
     * Was previously /system/heartbeat/data; the standalone Heartbeat
     * surface was retired once its only consumer became this dashboard.
     */
    public function health(): JsonResponse
    {
        return response()->json([
            'server' => $this->serverMetrics(),
            'step_dispatcher' => $this->stepDispatcherSummary(),
            'slow_queries' => $this->slowQueries(),
        ]);
    }

    /**
     * Vitals of the host actually serving the console (the web box). Every
     * probe is defensive: a dev box without `/proc` (macOS) yields null for
     * that field instead of throwing, so the Infra control-plane panel renders
     * "—" rather than 500-ing. Real core count drives the load→percent math —
     * a hardcoded count silently skews every reading the day the box resizes.
     *
     * @return array{hostname: string|null, cpu_percent: float|null, ram_used_mb: int|null, ram_total_mb: int|null, hdd_used_gb: float|null, hdd_total_gb: float|null}
     */
    private function serverMetrics(): array
    {
        // CPU: 1-minute load average over the real logical-core count.
        $load = function_exists('sys_getloadavg') ? (sys_getloadavg()[0] ?? null) : null;
        $cores = $this->cpuCores();
        $cpuPercent = ($load !== null && $cores !== null && $cores > 0)
            ? min(round(((float) $load / $cores) * 100, 1), 100)
            : null;

        // RAM: parse /proc/meminfo (Linux only — used = Total − Available).
        $ramTotalMb = null;
        $ramUsedMb = null;
        $meminfo = @file_get_contents('/proc/meminfo');
        if (is_string($meminfo)
            && preg_match('/MemTotal:\s+(\d+)/', $meminfo, $totalMatch) === 1
        ) {
            preg_match('/MemAvailable:\s+(\d+)/', $meminfo, $availMatch);
            $totalKb = (int) $totalMatch[1];
            $availKb = (int) ($availMatch[1] ?? 0);
            $ramTotalMb = (int) round($totalKb / 1024);
            $ramUsedMb = (int) round(max($totalKb - $availKb, 0) / 1024);
        }

        // HDD: disk space (works cross-platform).
        $total = @disk_total_space('/');
        $free = @disk_free_space('/');
        $hddTotalGb = is_float($total) && $total > 0 ? round($total / 1073741824, 1) : null;
        $hddUsedGb = ($hddTotalGb !== null && is_float($free))
            ? round($hddTotalGb - ($free / 1073741824), 1)
            : null;

        return [
            'hostname' => gethostname() ?: null,
            'cpu_percent' => $cpuPercent,
            'ram_used_mb' => $ramUsedMb,
            'ram_total_mb' => $ramTotalMb,
            'hdd_used_gb' => $hddUsedGb,
            'hdd_total_gb' => $hddTotalGb,
        ];
    }

    /**
     * Logical CPU count from /proc/cpuinfo; null off Linux (no fallback — a
     * wrong count would silently skew the percent rather than honestly read "—").
     */
    private function cpuCores(): ?int
    {
        $raw = @file_get_contents('/proc/cpuinfo');

        if (! is_string($raw)) {
            return null;
        }

        $count = preg_match_all('/^processor\s*:/m', $raw);

        return $count > 0 ? $count : null;
    }

    private function stepDispatcherSummary(): array
    {
        $dispatchers = DB::table('steps_dispatcher')->get();

        // Admin runs in UTC while ingestion writes last_tick_completed in
        // its app timezone, so a PHP-side diff drifts by the timezone delta.
        // Compare at the DB level using MySQL's NOW() to stay in the same
        // frame as the writer.
        $running = DB::table('steps_dispatcher')
            ->where('can_dispatch', true)
            ->whereNotNull('last_tick_completed')
            ->whereRaw('last_tick_completed >= DATE_SUB(NOW(), INTERVAL 2 MINUTE)')
            ->exists();

        $total = (int) DB::table('steps')->count();

        // Cached per-(class,state) aggregate. `GROUP BY class, state` matches
        // the existing index prefix exactly so it loose-index-scans instead
        // of falling into a temp-table aggregation. Heartbeat is observability,
        // not real-time — 30s of staleness is fine and the cache absorbs the
        // 5s poll cadence into ~2 DB hits per minute.
        $byState = Cache::remember('system.dashboard.health.by-state', 30, static function () {
            $parentClasses = array_flip(DB::table('steps')
                ->whereNotNull('child_block_uuid')
                ->distinct()
                ->pluck('class')
                ->all());

            $rows = DB::table('steps')
                ->select('class', 'state', DB::raw('COUNT(*) as total'))
                ->groupBy('class', 'state')
                ->get();

            $totals = [];
            foreach ($rows as $row) {
                if (isset($parentClasses[$row->class])) {
                    continue;
                }
                $stateName = class_basename($row->state);
                $totals[$stateName] = ($totals[$stateName] ?? 0) + (int) $row->total;
            }

            return $totals;
        });

        $lastTick = $dispatchers->max('last_tick_completed');

        return [
            'running' => $running,
            'total' => $total,
            'by_state' => $byState,
            'last_tick' => $lastTick,
        ];
    }

    private function slowQueries(): array
    {
        $lastHourCount = DB::table('slow_queries')
            ->whereRaw('created_at >= DATE_SUB(NOW(), INTERVAL 1 HOUR)')
            ->count();

        $recent = DB::table('slow_queries')
            ->select('id', 'time_ms', 'sql', 'connection', 'created_at')
            ->orderByDesc('created_at')
            ->limit(10)
            ->get()
            ->toArray();

        return [
            'last_hour_count' => $lastHourCount,
            'recent' => $recent,
        ];
    }
}
