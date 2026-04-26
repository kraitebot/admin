<?php

declare(strict_types=1);

namespace App\Http\Controllers\System;

use App\Http\Controllers\Controller;
use DateTimeImmutable;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Kraite\Core\Models\Kraite;

final class StepDispatcherController extends Controller
{
    public function index()
    {
        return view('system.step-dispatcher');
    }

    public function data(): JsonResponse
    {
        // Short-TTL cache on the aggregate query. The dashboard polls every
        // 10 seconds; caching the counts for 3 seconds still feels live to
        // the operator (refresh lands within one cache window ~70% of the
        // time) while absorbing concurrent tab/client polls onto a single
        // DB hit. Even with the covering index on (class, state, is_throttled)
        // the aggregate over 300K+ rows costs a few hundred ms — no reason
        // to pay that for every client on every tick.
        $rows = Cache::remember('system.step-dispatcher.counts', 3, static function () {
            return DB::table('steps')
                ->select('class', 'state', 'is_throttled', DB::raw('COUNT(*) as total'))
                ->groupBy('class', 'state', 'is_throttled')
                ->get();
        });

        $pivot = [];
        foreach ($rows as $row) {
            $class = $row->class ?? '(no class)';
            $state = class_basename($row->state);

            if ($state === 'Pending' && $row->is_throttled) {
                $state = 'Throttled';
            }

            if (! isset($pivot[$class])) {
                $pivot[$class] = [];
            }
            $pivot[$class][$state] = ($pivot[$class][$state] ?? 0) + (int) $row->total;
        }

        ksort($pivot);

        // Per-class health signals: max retries (spots ping-pong), oldest Running age (spots zombies).
        // Scoped to active states only — Completed/Cancelled retries are historical noise and
        // would require a full scan of ~640K rows. Active set is ~10K, uses idx_state_*.
        $activeStates = [
            'StepDispatcher\\States\\Pending',
            'StepDispatcher\\States\\Dispatched',
            'StepDispatcher\\States\\Running',
            'StepDispatcher\\States\\Failed',
        ];

        $healthRows = DB::table('steps')
            ->select(
                'class',
                DB::raw('MAX(retries) as max_retries'),
                DB::raw("MAX(CASE WHEN state = 'StepDispatcher\\\\States\\\\Running' THEN TIMESTAMPDIFF(SECOND, started_at, NOW()) END) as oldest_running_sec")
            )
            ->whereIn('state', $activeStates)
            ->groupBy('class')
            ->get()
            ->keyBy('class');

        $parentClasses = array_flip($this->parentClasses());

        $result = [];
        foreach ($pivot as $class => $states) {
            $health = $healthRows[$class] ?? null;
            $result[] = [
                'class' => $class,
                'short_name' => class_basename($class),
                'is_parent' => isset($parentClasses[$class]),
                'states' => $states,
                'max_retries' => $health ? (int) $health->max_retries : 0,
                'oldest_running_sec' => $health && $health->oldest_running_sec !== null
                    ? (int) $health->oldest_running_sec
                    : null,
            ];
        }

        $totals = [];
        foreach ($rows as $row) {
            $state = class_basename($row->state);

            if ($state === 'Pending' && $row->is_throttled) {
                $state = 'Throttled';
            }

            $totals[$state] = ($totals[$state] ?? 0) + (int) $row->total;
        }

        return response()->json([
            'rows' => $result,
            'totals' => $totals,
            'leaf_totals' => $this->buildLeafTotals(),
            'throughput' => $this->buildLeafThroughput(),
            'api_gauges' => $this->buildApiGauges(),
        ]);
    }

    public function blocks(Request $request): JsonResponse
    {
        $request->validate([
            'class' => ['required', 'string'],
            'state' => ['required', 'string'],
        ]);

        $class = $request->input('class');
        $stateInput = $request->input('state');

        $query = DB::table('steps')
            ->select('block_uuid', DB::raw('MAX(created_at) as latest'), DB::raw('COUNT(*) as step_count'))
            ->where('class', $class);

        if ($stateInput === 'Throttled') {
            $query->where('state', 'StepDispatcher\\States\\Pending')
                ->where('is_throttled', true);
        } else {
            $query->where('state', 'StepDispatcher\\States\\'.$stateInput);
            if ($stateInput === 'Pending') {
                $query->where(fn ($q) => $q->where('is_throttled', false)->orWhereNull('is_throttled'));
            }
        }

        $blockUuids = $query
            ->groupBy('block_uuid')
            ->orderByDesc('latest')
            ->limit(10)
            ->get();

        $blocks = $blockUuids->map(fn ($row) => [
            'block_uuid' => $row->block_uuid,
            'step_count' => (int) $row->step_count,
            'latest' => $row->latest,
        ]);

        return response()->json(['blocks' => $blocks]);
    }

    public function blockSteps(Request $request): JsonResponse
    {
        $request->validate([
            'block_uuid' => ['required', 'string'],
        ]);

        $steps = DB::table('steps')
            ->where('block_uuid', $request->input('block_uuid'))
            ->orderBy('index')
            ->orderBy('id')
            ->get()
            ->map(function ($step) {
                $state = class_basename($step->state ?? '');

                if ($state === 'Pending' && $step->is_throttled) {
                    $state = 'Throttled';
                }

                return [
                    'id' => $step->id,
                    'index' => $step->index,
                    'class' => $step->class,
                    'short_name' => class_basename($step->class ?? ''),
                    'state' => $state,
                    'label' => $step->label,
                    'child_block_uuid' => $step->child_block_uuid,
                    'error_message' => $step->error_message,
                    'retries' => $step->retries,
                    'duration' => $step->duration,
                    'started_at' => $step->started_at,
                    'completed_at' => $step->completed_at,
                ];
            });

        return response()->json(['steps' => $steps]);
    }

    public function coolingDown(): JsonResponse
    {
        $kraite = Kraite::first();

        return response()->json([
            'is_cooling_down' => $kraite?->is_cooling_down ?? false,
        ]);
    }

    public function toggleCoolingDown(): JsonResponse
    {
        $kraite = Kraite::first();

        if (! $kraite) {
            return response()->json(['error' => 'Kraite record not found.'], 404);
        }

        $kraite->is_cooling_down = ! $kraite->is_cooling_down;
        $kraite->save();

        return response()->json([
            'is_cooling_down' => $kraite->is_cooling_down,
        ]);
    }

    /**
     * Classes that have ever spawned children, detected from the steps table
     * itself. A class is "parent" if ANY of its rows has `child_block_uuid`
     * set — this catches runtime-parents (CheckKLines, ConcludeSymbol…) that
     * only declare their child_block_uuid when they execute and would
     * otherwise look like leaves while Pending.
     *
     * @return array<int, string>
     */
    private function parentClasses(): array
    {
        return Cache::remember('system.step-dispatcher.parent-classes', 15, static function (): array {
            return DB::table('steps')
                ->whereNotNull('child_block_uuid')
                ->distinct()
                ->pluck('class')
                ->all();
        });
    }

    /**
     * Per-state counts restricted to leaf steps — classes that never spawn
     * children. Detecting parents via observed data (see parentClasses)
     * rather than via the current row's child_block_uuid means a runtime-
     * parent still counts as parent while Pending, before it has declared
     * its children. NotRunnable is dropped because it's a structural
     * marker, not activity.
     *
     * @return array<string, int>
     */
    private function buildLeafTotals(): array
    {
        $parentClasses = $this->parentClasses();

        // Cache TTL is 30s rather than 3s: the underlying aggregation is a
        // GROUP BY on a 700K-row table that takes ~1-2.5s under load. The
        // dashboard polls every 5s, so a 3s cache only gave ~50% hit-rate
        // and tripped the slow-query alarm repeatedly. 30s is well within
        // the operational tolerance for "leaf step counts" — visibility
        // doesn't suffer from a sub-minute lag.
        $rows = Cache::remember('system.step-dispatcher.leaf-totals', 30, function () use ($parentClasses) {
            return DB::table('steps')
                ->select('state', 'is_throttled', DB::raw('COUNT(*) as total'))
                ->when(! empty($parentClasses), static function ($query) use ($parentClasses) {
                    $query->whereNotIn('class', $parentClasses);
                })
                ->groupBy('state', 'is_throttled')
                ->get();
        });

        $totals = [];
        foreach ($rows as $row) {
            $state = class_basename($row->state);

            if ($state === 'NotRunnable') {
                continue;
            }

            if ($state === 'Pending' && $row->is_throttled) {
                $state = 'Throttled';
            }

            $totals[$state] = ($totals[$state] ?? 0) + (int) $row->total;
        }

        return $totals;
    }

    /**
     * Leaf-step throughput gauge: current 10-second completion rate vs the
     * peak 10-second bucket seen over the last 5 minutes. Saturation is
     * current / peak so the operator can spot when the system drops below
     * its own recent high-water mark.
     *
     * Only leaves are counted — parent-close transitions happen at a
     * different cadence and don't reflect useful throughput.
     *
     * @return array<string, int|float|null>
     */
    private function buildLeafThroughput(): array
    {
        $parentClasses = $this->parentClasses();

        return Cache::remember('system.step-dispatcher.leaf-throughput', 3, static function () use ($parentClasses): array {
            // Use MySQL's own NOW() rather than PHP-side now(). Admin runs in
            // UTC while ingestion writes timestamps in the app timezone, so
            // a PHP-generated cutoff would drift by the timezone delta and
            // match every row.
            $current = (int) DB::table('steps')
                ->when(! empty($parentClasses), static function ($query) use ($parentClasses) {
                    $query->whereNotIn('class', $parentClasses);
                })
                ->where('state', 'StepDispatcher\\States\\Completed')
                ->whereRaw('completed_at >= DATE_SUB(NOW(), INTERVAL 10 SECOND)')
                ->count();

            $peakRow = DB::table('steps')
                ->selectRaw('FLOOR(UNIX_TIMESTAMP(completed_at) / 10) AS bucket, COUNT(*) AS bucket_count')
                ->when(! empty($parentClasses), static function ($query) use ($parentClasses) {
                    $query->whereNotIn('class', $parentClasses);
                })
                ->where('state', 'StepDispatcher\\States\\Completed')
                ->whereRaw('completed_at >= DATE_SUB(NOW(), INTERVAL 5 MINUTE)')
                ->groupBy('bucket')
                ->orderByDesc('bucket_count')
                ->first();

            $peak = $peakRow ? (int) $peakRow->bucket_count : 0;
            $saturation = ($peak > 0) ? min(100.0, ($current / $peak) * 100) : 0;

            return [
                'current_per_10s' => $current,
                'peak_per_10s' => $peak,
                'saturation' => round($saturation, 1),
            ];
        });
    }

    /**
     * Compute saturation gauges per API system.
     *
     * For each tracked API we look at the most recent non-429 sample and
     * count how many non-429 responses landed in the trailing
     * `window_seconds` ending at that sample. This anchors the rate to the
     * latest burst, so long idle gaps don't dilute the metric.
     *
     * We deliberately count every dispatch that actually hit the remote API
     * (HTTP 200 + business errors like 400 "unknown symbol") because those
     * all consume the throttler's budget; only 429 responses are excluded
     * since those are external rate-limit rejections, not real dispatches.
     *
     * Fully-idle APIs keep their last observed burst rate — the lookback
     * always ends at the newest sample, not at now().
     *
     * @return array<int, array<string, mixed>>
     */
    private function buildApiGauges(): array
    {
        $apis = [
            'taapi' => 'TAAPI',
            'coinmarketcap' => 'CoinMarketCap',
            'binance' => 'Binance',
            'bybit' => 'Bybit',
            'kucoin' => 'KuCoin',
            'bitget' => 'Bitget',
        ];

        return Cache::remember('system.step-dispatcher.api-gauges', 3, function () use ($apis) {
            $gauges = [];
            foreach ($apis as $canonical => $label) {
                $gauges[] = $this->gaugeFor($canonical, $label);
            }

            return $gauges;
        });
    }

    /**
     * Build a single gauge payload.
     *
     * @return array<string, mixed>
     */
    private function gaugeFor(string $canonical, string $label): array
    {
        $capRps = $this->capRequestsPerSecond($canonical);
        $windowSeconds = $this->lookbackWindowSeconds($canonical);
        $apiSystemId = DB::table('api_systems')->where('canonical', $canonical)->value('id');

        $empty = [
            'api' => $canonical,
            'label' => $label,
            'has_data' => false,
            'is_stale' => false,
            'saturation' => 0,
            'observed_rps' => null,
            'cap_rps' => $capRps !== null ? round($capRps, 2) : null,
            'sample_count' => 0,
            'window_seconds' => $windowSeconds,
        ];

        if ($apiSystemId === null || $capRps === null || $capRps <= 0) {
            return $empty;
        }

        // Resolve newest + age in a single DB round-trip. Using MySQL's own
        // NOW() avoids a timezone mismatch — admin runs in UTC while
        // ingestion writes timestamps in the app timezone, so PHP-side age
        // math would be off by the timezone delta.
        $meta = DB::table('api_request_logs')
            ->where('api_system_id', $apiSystemId)
            ->whereNotNull('http_response_code')
            ->where('http_response_code', '!=', 429)
            ->selectRaw('MAX(created_at) AS newest, TIMESTAMPDIFF(SECOND, MAX(created_at), NOW()) AS age_seconds')
            ->first();

        if ($meta === null || $meta->newest === null) {
            return $empty;
        }

        $newest = $meta->newest;
        $windowStart = (new DateTimeImmutable($newest))->modify("-{$windowSeconds} seconds")->format('Y-m-d H:i:s');

        $count = (int) DB::table('api_request_logs')
            ->where('api_system_id', $apiSystemId)
            ->whereNotNull('http_response_code')
            ->where('http_response_code', '!=', 429)
            ->whereBetween('created_at', [$windowStart, $newest])
            ->count();

        if ($count === 0) {
            return $empty;
        }

        $observedRps = $count / $windowSeconds;
        $saturation = min(100.0, ($observedRps / $capRps) * 100);

        // Staleness flag: once the newest real dispatch is older than the
        // inactivity threshold we keep the last reading on screen so the
        // operator still sees where the API peaked, and let the frontend
        // repaint it in a muted color to signal "no longer measuring".
        $isStale = (int) $meta->age_seconds > 60;

        return [
            'api' => $canonical,
            'label' => $label,
            'has_data' => true,
            'is_stale' => $isStale,
            'saturation' => round($saturation, 1),
            'observed_rps' => round($observedRps, 2),
            'cap_rps' => round($capRps, 2),
            'sample_count' => $count,
            'window_seconds' => $windowSeconds,
        ];
    }

    /**
     * Resolve the lookback window for an API. Uses the throttler's declared
     * window_seconds when available; falls back to 60 for APIs with only a
     * min_delay-based config (currently just binance).
     */
    private function lookbackWindowSeconds(string $canonical): int
    {
        $config = config("kraite.throttlers.{$canonical}");

        if (is_array($config) && isset($config['window_seconds'])) {
            return (int) $config['window_seconds'];
        }

        return 60;
    }

    /**
     * Resolve the effective requests-per-second cap for an API canonical.
     *
     * For APIs with `requests_per_window`/`window_seconds` we multiply by the
     * safety threshold (bybit's threshold is a remaining-percentage, not a
     * cap reducer, so we skip it there). For binance we fall back to
     * `min_delay_ms` since the config tracks weight-based limits instead of
     * request counts.
     */
    private function capRequestsPerSecond(string $canonical): ?float
    {
        $config = config("kraite.throttlers.{$canonical}");

        if (! is_array($config)) {
            return null;
        }

        $requestsPerWindow = $config['requests_per_window'] ?? null;
        $windowSeconds = $config['window_seconds'] ?? null;

        if ($requestsPerWindow && $windowSeconds) {
            $safety = $canonical === 'bybit'
                ? 1.0
                : (float) ($config['safety_threshold'] ?? 1.0);

            return ($requestsPerWindow * $safety) / $windowSeconds;
        }

        $minDelayMs = $config['min_delay_ms'] ?? ($config['min_delay_between_requests_ms'] ?? null);

        if ($minDelayMs && $minDelayMs > 0) {
            return 1000.0 / $minDelayMs;
        }

        return null;
    }
}
