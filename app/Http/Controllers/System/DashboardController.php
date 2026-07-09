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
        return view('system.dashboard', ['overview' => $this->overview()]);
    }

    /**
     * The full overview payload the dashboard polls. Same shape as the
     * server-side seed so the Alpine state swaps wholesale on every tick.
     */
    public function data(): JsonResponse
    {
        return response()->json($this->overview());
    }

    /**
     * Everything on the overview page in one payload. Ingestion writes its
     * timestamps in its own APP_TIMEZONE while admin runs UTC, so every age /
     * window comparison against ingestion-written rows happens at the DB level
     * with MySQL's NOW() — never PHP's now().
     *
     * @return array<string, mixed>
     */
    private function overview(): array
    {
        $fleet = $this->section(fn () => app(FleetMetricsRepository::class)->all(), []);

        return [
            'kpis' => $this->kpis(),
            'regime' => $this->section(fn () => $this->regime(), [
                'score' => null,
                'band' => null,
                'is_stale' => true,
                'posture' => 'No signal yet',
                'override_reason' => null,
                'override_until' => null,
                'sparkline' => [],
            ]),
            'deploy' => $this->deploy($fleet),
            'revenue' => $this->section(fn () => $this->revenue(), [
                'mrr' => null,
                'topups_today' => null,
                'topups_count' => 0,
                'wallet_float' => null,
            ]),
            'venues' => $this->section(fn () => $this->venues(), []),
            'incidents' => $this->section(fn () => $this->incidents(), []),
            'fleet' => $fleet,
        ];
    }

    /**
     * Overview sections degrade to a placeholder instead of taking the whole
     * page down — the dashboard must stay coherent when half the back-end is
     * missing (dev database without core tables, mid-deploy outages). The
     * failure still reports to the log so a broken section is not silent.
     */
    private function section(callable $callback, mixed $fallback): mixed
    {
        return rescue($callback, $fallback);
    }

    /**
     * @return array<string, mixed>
     */
    private function kpis(): array
    {
        return [
            'traders' => $this->section(fn () => $this->tradersKpi(), [
                'count' => null,
                'signups_24h' => 0,
                'delta_pct' => null,
            ]),
            'tradeable' => $this->section(
                fn () => Cache::remember('system.dashboard.kpi.tradeable', 60, fn () => $this->tradeableKpi()),
                ['total' => null, 'longs' => null, 'shorts' => null, 'exchanges' => []],
            ),
            'capital' => $this->section(
                fn () => Cache::remember('system.dashboard.kpi.capital', 60, fn () => $this->capitalKpi()),
                ['aum' => null, 'delta_pct' => null, 'accounts' => 0],
            ),
            'throughput' => $this->section(fn () => $this->throughputKpi(), ['fleets' => []]),
            'open_positions' => $this->section(
                fn () => (int) DB::table('positions')->where('is_open', 1)->count(),
                null,
            ),
        ];
    }

    /**
     * @return array{count: int, signups_24h: int, delta_pct: float|null}
     */
    private function tradersKpi(): array
    {
        // Users are written by the web apps (UTC frame), so a PHP-side window
        // is safe here — and it keeps this query portable across drivers.
        $count = (int) DB::table('users')->where('is_active', true)->count();
        $signups = (int) DB::table('users')
            ->where('created_at', '>=', now()->subDay())
            ->count();
        $previous = $count - $signups;

        return [
            'count' => $count,
            'signups_24h' => $signups,
            // A flat day renders no badge rather than a noisy "+0.0%".
            'delta_pct' => ($previous > 0 && $signups > 0) ? round(($signups / $previous) * 100, 1) : null,
        ];
    }

    /**
     * Total tradeable tokens per exchange, split by direction. Delegated to
     * the ExchangeSymbol::tradeable() scope so the tile always matches the
     * live trader's exact tradeable definition.
     *
     * @return array<string, mixed>
     */
    private function tradeableKpi(): array
    {
        $rows = ExchangeSymbol::query()
            ->tradeable()
            ->join('api_systems', 'api_systems.id', '=', 'exchange_symbols.api_system_id')
            ->where('api_systems.is_exchange', true)
            ->select(
                'api_systems.name as exchange',
                'exchange_symbols.direction',
                DB::raw('COUNT(*) as total'),
            )
            ->groupBy('api_systems.name', 'exchange_symbols.direction')
            ->get();

        $monograms = ['Binance' => 'B', 'Bybit' => 'BY', 'KuCoin' => 'KU', 'BitGet' => 'BG'];
        $exchanges = [];
        foreach ($rows as $row) {
            $name = (string) $row->exchange;
            $exchanges[$name] ??= ['name' => $name, 'mono' => $monograms[$name] ?? strtoupper(substr($name, 0, 2)), 'longs' => 0, 'shorts' => 0];
            if ($row->direction === 'LONG') {
                $exchanges[$name]['longs'] += (int) $row->total;
            } elseif ($row->direction === 'SHORT') {
                $exchanges[$name]['shorts'] += (int) $row->total;
            }
        }

        $longs = array_sum(array_column($exchanges, 'longs'));
        $shorts = array_sum(array_column($exchanges, 'shorts'));

        return [
            'total' => $longs + $shorts,
            'longs' => $longs,
            'shorts' => $shorts,
            'exchanges' => array_values($exchanges),
        ];
    }

    /**
     * Dispatcher throughput per fleet: steps genuinely processing (Running
     * leaf steps — orchestrator rows excluded) against the pending backlog.
     * Same definitions as the Engine page's tiles.
     *
     * @return array{fleets: array<string, array{processing: int, pending: int}>}
     */
    private function throughputKpi(): array
    {
        return Cache::remember('system.dashboard.kpi.throughput', 10, function (): array {
            $fleets = [];

            foreach (['default' => 'steps', 'trading' => 'trading_steps'] as $fleet => $table) {
                if (! Schema::hasTable($table)) {
                    continue;
                }

                $fleets[$fleet] = [
                    'processing' => (int) DB::table($table)
                        ->where('state', 'StepDispatcher\\States\\Running')
                        ->whereNull('child_block_uuid')
                        ->count(),
                    'pending' => (int) DB::table($table)
                        ->where('state', 'StepDispatcher\\States\\Pending')
                        ->count(),
                ];
            }

            return ['fleets' => $fleets];
        });
    }

    /**
     * AUM = latest exchange-reported wallet balance per active account,
     * summed. Delta compares against the same sum anchored 24h back.
     *
     * @return array{aum: float, delta_pct: float|null}
     */
    private function capitalKpi(): array
    {
        $aum = $this->balanceSumAt(null);
        $dayAgo = $this->balanceSumAt('DATE_SUB(NOW(), INTERVAL 24 HOUR)');

        return [
            'aum' => $aum,
            'delta_pct' => ($dayAgo !== null && $dayAgo > 0 && $aum !== null)
                ? round((($aum - $dayAgo) / $dayAgo) * 100, 1)
                : null,
            'accounts' => (int) DB::table('accounts')
                ->where('is_active', true)
                ->whereNull('deleted_at')
                ->count(),
        ];
    }

    private function balanceSumAt(?string $atSqlExpression): ?float
    {
        $latestIds = DB::table('account_balance_history')
            ->select(DB::raw('MAX(id)'))
            ->when($atSqlExpression !== null, fn ($q) => $q->whereRaw("created_at <= {$atSqlExpression}"))
            ->groupBy('account_id');

        $sum = DB::table('account_balance_history as abh')
            ->join('accounts', 'accounts.id', '=', 'abh.account_id')
            ->where('accounts.is_active', true)
            ->whereNull('accounts.deleted_at')
            ->whereIn('abh.id', $latestIds)
            ->sum('abh.total_wallet_balance');

        return $sum === null ? null : (float) $sum;
    }

    /**
     * Real BSCS state: score 0-100 into the four core regime bands, plus the
     * posture each band drives and the manual-override audit fields.
     *
     * @return array<string, mixed>
     */
    private function regime(): array
    {
        // Accessors instead of toArray(): the lossless dump also computes
        // portfolio risk, which this panel doesn't render — no point paying
        // its queries on every poll.
        $index = BlackSwanIndex::current();
        $band = $index->band()?->value;

        $override = $this->bscsOverride();

        return [
            'score' => $index->score(),
            'band' => $band,
            'is_stale' => $index->isStale(),
            'posture' => match ($band) {
                'calm' => 'No posture change',
                'elevated' => 'Monitoring — no auto action',
                'fragile' => 'Margin slice reduced on new opens',
                'critical' => 'New opens blocked',
                default => 'No signal yet',
            },
            'override_reason' => $override['reason'],
            'override_until' => $override['until'],
            'sparkline' => MarketRegimeSnapshot::query()
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
                ->all(),
        ];
    }

    /**
     * Manual-override audit state for the regime panel. The override columns
     * are owned by kraitebot/core — admin holds no schema. Until that
     * migration lands on a given database the columns are absent, so the read
     * is gated on column existence: missing column yields null rather than
     * throwing and taking the entire overview feed down with it. The
     * existence check is cached so the 15s poll doesn't re-hit
     * information_schema every tick.
     *
     * @return array{reason: string|null, until: string|null}
     */
    private function bscsOverride(): array
    {
        $hasColumn = Cache::remember(
            'system.dashboard.kraite-has-override-reason',
            300,
            static fn (): bool => Schema::hasColumn('kraite', 'bscs_override_reason'),
        );

        if (! $hasColumn) {
            return ['reason' => null, 'until' => null];
        }

        $row = Kraite::query()->first(['bscs_override_reason', 'bscs_override_until']);

        return [
            'reason' => $row?->bscs_override_reason,
            'until' => $row?->bscs_override_until?->toIso8601String(),
        ];
    }

    /**
     * Rollout state derived from the fleet heartbeat's reported core version.
     * Hosts that report no version (hyperion's bash agent, boxes on a core
     * build predating the version field) are excluded from the drift math.
     *
     * @param  array<int, array<string, mixed>>  $fleet
     * @return array<string, mixed>
     */
    private function deploy(array $fleet): array
    {
        $reporting = array_values(array_filter(
            $fleet,
            static fn (array $row): bool => ($row['version'] ?? null) !== null,
        ));

        if ($reporting === []) {
            return [
                'version' => null,
                'reporting' => 0,
                'on_latest' => 0,
                'lagging' => [],
                'in_sync' => null,
            ];
        }

        $versions = array_unique(array_map(static fn (array $r): string => (string) $r['version'], $reporting));
        usort($versions, static fn (string $a, string $b): int => version_compare(ltrim($b, 'v'), ltrim($a, 'v')));
        $latest = $versions[0];

        $lagging = array_values(array_filter(
            $reporting,
            static fn (array $r): bool => $r['version'] !== $latest,
        ));

        return [
            'version' => $latest,
            'reporting' => count($reporting),
            'on_latest' => count($reporting) - count($lagging),
            'lagging' => array_map(static fn (array $r): array => [
                'hostname' => $r['hostname'],
                'version' => $r['version'],
            ], $lagging),
            'in_sync' => count($versions) === 1,
        ];
    }

    /**
     * Billing snapshot: committed monthly run-rate, today's confirmed
     * top-ups, and the float held across every user wallet.
     *
     * @return array<string, mixed>
     */
    private function revenue(): array
    {
        return Cache::remember('system.dashboard.revenue', 30, function (): array {
            $mrr = (float) DB::table('users')
                ->join('subscriptions', 'subscriptions.id', '=', 'users.subscription_id')
                ->where('users.is_active', true)
                ->whereNull('users.subscription_paused_at')
                ->sum('subscriptions.monthly_rate_usdt');

            // Top-ups are written by the web apps (UTC frame) — PHP-side day
            // boundary is safe and keeps the query portable across drivers.
            $topups = DB::table('wallet_transactions')
                ->where('type', 'credit_topup')
                ->where('created_at', '>=', now()->startOfDay())
                ->selectRaw('COALESCE(SUM(amount_usdt), 0) as total, COUNT(*) as payments')
                ->first();

            return [
                'mrr' => round($mrr, 2),
                'topups_today' => round((float) $topups->total, 2),
                'topups_count' => (int) $topups->payments,
                'wallet_float' => round((float) DB::table('users')->sum('wallet_balance_usdt'), 2),
            ];
        });
    }

    /**
     * Per-exchange connectivity over the trailing hour, straight from
     * api_request_logs: average latency, error rate, a 6-bucket latency
     * sparkline, and the active-account count per venue. Rows that never
     * completed (in-flight or timed out before logging a response) are
     * excluded — the error rate reads "of completed calls".
     *
     * @return array<int, array<string, mixed>>
     */
    private function venues(): array
    {
        return Cache::remember('system.dashboard.venues', 30, function (): array {
            $exchanges = DB::table('api_systems')
                ->where('is_exchange', true)
                ->orderBy('id')
                ->get(['id', 'name', 'canonical']);

            $stats = DB::table('api_request_logs')
                ->selectRaw('api_system_id, COUNT(*) as total, SUM(http_response_code >= 400) as errors, ROUND(AVG(duration)) as avg_ms')
                ->whereRaw('created_at >= DATE_SUB(NOW(), INTERVAL 60 MINUTE)')
                ->whereNotNull('completed_at')
                ->groupBy('api_system_id')
                ->get()
                ->keyBy('api_system_id');

            // Latency trend = the last 12 completed calls per venue, in call
            // order. Time-bucketed averages read as crashes-to-zero on venues
            // whose cron cadence leaves most buckets empty.
            $sparkRows = DB::table('api_request_logs')
                ->select('api_system_id', 'duration', DB::raw('ROW_NUMBER() OVER (PARTITION BY api_system_id ORDER BY id DESC) as rn'))
                ->whereRaw('created_at >= DATE_SUB(NOW(), INTERVAL 24 HOUR)')
                ->whereNotNull('completed_at');

            $sparks = DB::query()
                ->fromSub($sparkRows, 'recent')
                ->where('rn', '<=', 12)
                ->orderBy('api_system_id')
                ->orderByDesc('rn')
                ->get()
                ->groupBy('api_system_id');

            $accounts = DB::table('accounts')
                ->where('is_active', true)
                ->whereNull('deleted_at')
                ->select('api_system_id', DB::raw('COUNT(*) as total'))
                ->groupBy('api_system_id')
                ->get()
                ->keyBy('api_system_id');

            $monograms = ['binance' => 'B', 'bybit' => 'BY', 'kucoin' => 'KU', 'bitget' => 'BG'];

            return $exchanges->map(function ($exchange) use ($stats, $sparks, $accounts, $monograms): array {
                $stat = $stats->get($exchange->id);
                $total = (int) ($stat->total ?? 0);
                $errors = (int) ($stat->errors ?? 0);
                $errorPct = $total > 0 ? round(($errors / $total) * 100, 2) : null;

                $spark = ($sparks->get($exchange->id) ?? collect())
                    ->map(fn ($row): int => (int) $row->duration)
                    ->values()
                    ->all();

                return [
                    'name' => $exchange->name,
                    'mono' => $monograms[$exchange->canonical] ?? strtoupper(substr($exchange->name, 0, 2)),
                    'status' => match (true) {
                        $total === 0 => 'idle',
                        $errorPct >= 50 => 'down',
                        $errorPct >= 5 => 'degraded',
                        default => 'operational',
                    },
                    'latency_ms' => $total > 0 ? (int) $stat->avg_ms : null,
                    'error_pct' => $errorPct,
                    'accounts' => (int) ($accounts->get($exchange->id)->total ?? 0),
                    'spark' => $spark,
                ];
            })->values()->all();
        });
    }

    /**
     * Latest occurrence per notification canonical, newest first — the real
     * platform event feed. Ages are computed at the DB level (ingestion
     * writes these rows in its own timezone).
     *
     * @return array<int, array<string, mixed>>
     */
    private function incidents(): array
    {
        return Cache::remember('system.dashboard.incidents', 30, function (): array {
            $latestPerCanonical = DB::table('notification_logs')
                ->select(DB::raw('MAX(id)'))
                ->groupBy('canonical');

            $rows = DB::table('notification_logs')
                ->whereIn('id', $latestPerCanonical)
                ->orderByDesc('id')
                ->limit(8)
                ->get([
                    'canonical',
                    'channel',
                    DB::raw('GREATEST(TIMESTAMPDIFF(SECOND, created_at, NOW()), 0) as age_seconds'),
                ]);

            return $rows->map(fn ($row): array => [
                'title' => ucfirst(str_replace('_', ' ', (string) $row->canonical)),
                'channel' => $row->channel,
                'severity' => $this->incidentSeverity((string) $row->canonical),
                'age' => $this->shortAge((int) $row->age_seconds),
            ])->all();
        });
    }

    private function incidentSeverity(string $canonical): string
    {
        return match (true) {
            str_contains($canonical, 'blacklisted'),
            str_contains($canonical, 'circuit_breaker') => 'bad',
            str_contains($canonical, 'alert'),
            str_contains($canonical, 'failed'),
            str_contains($canonical, 'rate_limit'),
            str_contains($canonical, 'not_whitelisted'),
            str_contains($canonical, 'reconnect'),
            str_contains($canonical, 'delisting') => 'warn',
            str_contains($canonical, 'recovered'),
            str_contains($canonical, 'completed'),
            str_contains($canonical, 'connected') => 'good',
            default => 'mute',
        };
    }

    private function shortAge(int $seconds): string
    {
        return match (true) {
            $seconds < 60 => $seconds.'s',
            $seconds < 3600 => intdiv($seconds, 60).'m',
            $seconds < 86400 => intdiv($seconds, 3600).'h',
            default => intdiv($seconds, 86400).'d',
        };
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
