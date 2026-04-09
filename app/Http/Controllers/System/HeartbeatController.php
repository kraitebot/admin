<?php

declare(strict_types=1);

namespace App\Http\Controllers\System;

use App\Http\Controllers\Controller;
use Illuminate\Http\JsonResponse;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Process;

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
            'supervisor' => $this->supervisorStatus(),
            'schedule' => $this->scheduledTasks(),
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

    private function supervisorStatus(): array
    {
        try {
            $result = Process::run('sudo supervisorctl status');

            if (! $result->successful()) {
                return ['available' => false, 'error' => 'Permission denied or supervisorctl not available', 'processes' => []];
            }

            $processes = [];
            foreach (explode("\n", trim($result->output())) as $line) {
                if (empty(trim($line))) {
                    continue;
                }

                // Format: name STATE pid XXXX, uptime X:XX:XX
                if (preg_match('/^(\S+)\s+(RUNNING|STOPPED|FATAL|STARTING|BACKOFF|EXITED|UNKNOWN)\s+(.*)$/', trim($line), $m)) {
                    $details = $m[3];
                    $pid = null;
                    $uptime = null;

                    if (preg_match('/pid\s+(\d+)/', $details, $pidMatch)) {
                        $pid = (int) $pidMatch[1];
                    }
                    if (preg_match('/uptime\s+(.+)/', $details, $uptimeMatch)) {
                        $uptime = trim($uptimeMatch[1]);
                    }

                    $processes[] = [
                        'name' => $m[1],
                        'state' => $m[2],
                        'pid' => $pid,
                        'uptime' => $uptime,
                    ];
                }
            }

            return ['available' => true, 'processes' => $processes];
        } catch (\Throwable $e) {
            return ['available' => false, 'error' => $e->getMessage(), 'processes' => []];
        }
    }

    private function scheduledTasks(): array
    {
        try {
            $result = Process::path('/home/waygou/ingestion.kraite.com')
                ->run('php artisan schedule:list --no-ansi');

            if (! $result->successful()) {
                return ['available' => false, 'tasks' => []];
            }

            $tasks = [];
            foreach (explode("\n", trim($result->output())) as $line) {
                $line = trim($line);
                if (empty($line) || str_starts_with($line, '---') || str_contains($line, 'Showing schedule')) {
                    continue;
                }

                // Format: "* * * * * 1s  php artisan steps:dispatch ...... Next Due: 0 seconds from now"
                if (preg_match('/^([\*\/\d\,\-]+(?:\s+[\*\/\d\,\-]+){4}(?:\s+\d+\w)?)\s+(.+?)\s*\.{2,}\s*Next Due:\s+(.+)$/i', $line, $m)) {
                    $nextRunText = trim($m[3]);
                    $nextRunIso = null;

                    if (preg_match('/(\d+)\s+(second|minute|hour|day)s?\s+from\s+now/i', $nextRunText, $timeMatch)) {
                        $amount = (int) $timeMatch[1];
                        $nextRunIso = match ($timeMatch[2]) {
                            'second' => now()->addSeconds($amount),
                            'minute' => now()->addMinutes($amount),
                            'hour' => now()->addHours($amount),
                            'day' => now()->addDays($amount),
                            default => now(),
                        };
                        $nextRunIso = $nextRunIso->toIso8601String();
                    }

                    $tasks[] = [
                        'expression' => trim($m[1]),
                        'command' => trim($m[2]),
                        'next_run' => $nextRunText,
                        'next_run_iso' => $nextRunIso,
                    ];
                }
            }

            return ['available' => true, 'tasks' => $tasks];
        } catch (\Throwable) {
            return ['available' => false, 'tasks' => []];
        }
    }

    private function stepDispatcherSummary(): array
    {
        $dispatchers = DB::table('steps_dispatcher')->get();

        $running = $dispatchers->contains(function ($d) {
            return $d->can_dispatch
                && $d->last_tick_completed
                && now()->diffInMinutes($d->last_tick_completed) < 2;
        });

        $byState = DB::table('steps')
            ->select(DB::raw('SUBSTRING_INDEX(state, "\\\\", -1) as state_name'), DB::raw('COUNT(*) as total'))
            ->groupBy('state_name')
            ->pluck('total', 'state_name')
            ->toArray();

        $total = array_sum($byState);

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
            ->where('created_at', '>=', now()->subHour())
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
