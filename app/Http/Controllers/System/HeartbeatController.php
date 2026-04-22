<?php

declare(strict_types=1);

namespace App\Http\Controllers\System;

use App\Http\Controllers\Controller;
use Illuminate\Http\JsonResponse;
use Illuminate\Support\Facades\DB;

class HeartbeatController extends Controller
{
    public function index()
    {
        return view('system.heartbeat');
    }

    public function data(): JsonResponse
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

        // Total = all steps (parents + leaves).
        $total = (int) DB::table('steps')->count();

        // Per-state breakdown restricted to leaf steps — a step class is a
        // parent if ANY of its rows has child_block_uuid set (runtime-parents
        // only declare children when they execute, so the detection has to
        // happen across the whole table). Matches the same logic used on
        // /system/step-dispatcher's `buildLeafTotals()`.
        $parentClasses = DB::table('steps')
            ->whereNotNull('child_block_uuid')
            ->distinct()
            ->pluck('class')
            ->all();

        $byState = DB::table('steps')
            ->select(DB::raw('SUBSTRING_INDEX(state, "\\\\", -1) as state_name'), DB::raw('COUNT(*) as total'))
            ->when(! empty($parentClasses), static function ($q) use ($parentClasses) {
                $q->whereNotIn('class', $parentClasses);
            })
            ->groupBy('state_name')
            ->pluck('total', 'state_name')
            ->toArray();

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
        // Same timezone gotcha as stepDispatcherSummary — compare via DB NOW()
        // so admin's UTC vs ingestion's tz don't drift the 1h window.
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
