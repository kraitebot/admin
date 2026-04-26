<?php

declare(strict_types=1);

namespace App\Http\Controllers\System;

use App\Http\Controllers\Controller;
use Illuminate\Http\JsonResponse;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Illuminate\View\View;

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

        // Tradeable counts — mirrors scopeTradeable conditions including Binance cross-check
        $correlationType = config('kraite.token_discovery.correlation_type', 'rolling');
        $correlationColumn = 'btc_correlation_'.$correlationType;

        $tradeableConditions = function ($query, string $table) use ($correlationColumn) {
            $query->where("{$table}.api_statuses->has_taapi_data", true)
                ->where("{$table}.has_no_indicator_data", false)
                ->where("{$table}.is_marked_for_delisting", false)
                ->where("{$table}.has_price_trend_misalignment", false)
                ->where("{$table}.has_early_direction_change", false)
                ->where("{$table}.has_invalid_indicator_direction", false)
                ->whereNotNull("{$table}.symbol_id")
                ->whereNotNull("{$table}.leverage_brackets")
                ->where(fn ($q) => $q->whereNull("{$table}.is_manually_enabled")->orWhere("{$table}.is_manually_enabled", true))
                ->whereNotNull("{$table}.direction")
                ->where(fn ($q) => $q->whereNull("{$table}.tradeable_at")->orWhere("{$table}.tradeable_at", '<=', now()))
                ->whereNotNull("{$table}.indicators_timeframe")
                ->whereRaw("JSON_EXTRACT({$table}.{$correlationColumn}, CONCAT('$.\"', {$table}.indicators_timeframe, '\"')) IS NOT NULL");
        };

        $tradeableCounts = DB::table('exchange_symbols')
            ->whereIn('exchange_symbols.api_system_id', $exchangeIds)
            ->where(fn ($q) => $tradeableConditions($q, 'exchange_symbols'))
            ->whereExists(function ($sub) use ($tradeableConditions) {
                $sub->from('exchange_symbols as binance_es')
                    ->join('api_systems', 'api_systems.id', '=', 'binance_es.api_system_id')
                    ->where('api_systems.canonical', 'binance')
                    ->whereColumn('binance_es.token', 'exchange_symbols.token')
                    ->whereColumn('binance_es.quote', 'exchange_symbols.quote');

                $tradeableConditions($sub, 'binance_es');
            })
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
        ]);
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

    private function serverMetrics(): array
    {
        // CPU: load average / number of CPUs
        $load = sys_getloadavg()[0];
        $cpuCount = 32;
        $cpuPercent = min(round(($load / $cpuCount) * 100, 1), 100);

        // RAM: parse /proc/meminfo
        $meminfo = file_get_contents('/proc/meminfo');
        preg_match('/MemTotal:\s+(\d+)/', $meminfo, $totalMatch);
        preg_match('/MemAvailable:\s+(\d+)/', $meminfo, $availMatch);
        $ramTotalMb = (int) ($totalMatch[1] ?? 0) / 1024;
        $ramUsedMb = $ramTotalMb - ((int) ($availMatch[1] ?? 0) / 1024);

        // HDD: disk space
        $hddTotalGb = round(disk_total_space('/') / 1073741824, 1);
        $hddFreeGb = round(disk_free_space('/') / 1073741824, 1);
        $hddUsedGb = round($hddTotalGb - $hddFreeGb, 1);

        return [
            'cpu_percent' => $cpuPercent,
            'ram_used_mb' => round($ramUsedMb),
            'ram_total_mb' => round($ramTotalMb),
            'hdd_used_gb' => $hddUsedGb,
            'hdd_total_gb' => $hddTotalGb,
        ];
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
