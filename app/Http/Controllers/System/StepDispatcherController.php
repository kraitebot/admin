<?php

declare(strict_types=1);

namespace App\Http\Controllers\System;

use App\Http\Controllers\Controller;
use DateTimeImmutable;
use Illuminate\Database\Query\Builder;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Kraite\Core\Support\MaintenanceMode;
use StepDispatcher\Support\Steps;

final class StepDispatcherController extends Controller
{
    /**
     * Slug → MaintenanceMode prefix. `default` is the unprefixed
     * `steps_*` fleet (calculation churn — klines, indicators,
     * BSCS, balances). `trading` is the `trading_steps_*` fleet
     * (trade-critical — opens, sync, close, WAP, drift heals).
     */
    private const PREFIX_MAP = [
        'default' => '',
        'trading' => 'trading',
    ];

    public function index(string $prefix)
    {
        $this->assertPrefix($prefix);

        return view('system.step-dispatcher', [
            'prefix' => $prefix,
            'prefixLabel' => $this->prefixLabel($prefix),
        ]);
    }

    public function data(string $prefix): JsonResponse
    {
        $this->assertPrefix($prefix);

        $table = $this->stepsTable($prefix);

        $rows = $this->stepCountRows($prefix, $table);

        $pivot = $this->stepStatePivot($rows);

        $healthRows = $this->stepHealthRows($table);

        $parentClasses = array_flip($this->parentClasses($prefix));

        $totals = $this->stepStateTotals($rows);

        return response()->json([
            'rows' => $this->stepDataRows($pivot, $healthRows, $parentClasses),
            'totals' => $totals,
            'leaf_totals' => $this->buildLeafTotals($prefix),
            'throughput' => $this->buildLeafThroughput($prefix),
            'api_gauges' => $this->buildApiGauges($prefix),
        ]);
    }

    /**
     * @param  array<string, array<string, int>>  $pivot
     * @param  Collection<int|string, \stdClass>  $healthRows
     * @param  array<string, int>  $parentClasses
     * @return array<int, array<string, mixed>>
     */
    private function stepDataRows(array $pivot, Collection $healthRows, array $parentClasses): array
    {
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

        return $result;
    }

    /**
     * @param  array<int, array<string, mixed>>  $rows
     * @return array<string, array<string, int>>
     */
    private function stepStatePivot(array $rows): array
    {
        $pivot = [];
        foreach ($rows as $row) {
            $class = $row['class'] ?? '(no class)';
            $state = $this->displayState(class_basename($row['state']), $row['is_throttled']);
            if (! isset($pivot[$class])) {
                $pivot[$class] = [];
            }
            $pivot[$class][$state] = ($pivot[$class][$state] ?? 0) + (int) $row['total'];
        }
        ksort($pivot);

        return $pivot;
    }

    /**
     * @param  array<int, array<string, mixed>>  $rows
     * @return array<string, int>
     */
    private function stepStateTotals(array $rows): array
    {
        $totals = [];
        foreach ($rows as $row) {
            $state = $this->displayState(class_basename($row['state']), $row['is_throttled']);
            $totals[$state] = ($totals[$state] ?? 0) + (int) $row['total'];
        }

        return $totals;
    }

    /** @return array<int, array<string, mixed>> */
    private function stepCountRows(string $prefix, string $table): array
    {
        return Cache::remember("system.steps.{$prefix}.counts", 3, static function () use ($table) {
            return DB::table($table)
                ->select('class', 'state', 'is_throttled', DB::raw('COUNT(*) as total'))
                ->groupBy('class', 'state', 'is_throttled')
                ->get()
                ->map(static fn (object $row): array => (array) $row)
                ->all();
        });
    }

    /** @return Collection<int|string, \stdClass> */
    private function stepHealthRows(string $table): Collection
    {
        $activeStates = [
            'StepDispatcher\\States\\Pending',
            'StepDispatcher\\States\\Dispatched',
            'StepDispatcher\\States\\Running',
            'StepDispatcher\\States\\Failed',
        ];

        return DB::table($table)
            ->select(
                'class',
                DB::raw('MAX(retries) as max_retries'),
                DB::raw("MAX(CASE WHEN state = 'StepDispatcher\\\\States\\\\Running' THEN TIMESTAMPDIFF(SECOND, started_at, NOW()) END) as oldest_running_sec")
            )
            ->whereIn('state', $activeStates)
            ->groupBy('class')
            ->get()
            ->keyBy('class');
    }

    public function blocks(string $prefix, Request $request): JsonResponse
    {
        $this->assertPrefix($prefix);

        $request->validate($this->blockRules());

        $class = (string) $request->input('class');
        $stateInput = (string) $request->input('state');
        $blocks = $this->blockQuery($prefix, $class, $stateInput)
            ->get()
            ->map(fn (\stdClass $row): array => $this->serialiseBlock($row));

        return response()->json(['blocks' => $blocks]);
    }

    public function blockSteps(string $prefix, Request $request): JsonResponse
    {
        $this->assertPrefix($prefix);

        $request->validate($this->blockStepRules());

        $steps = $this->blockStepsQuery($prefix, (string) $request->input('block_uuid'))
            ->get()
            ->map(fn (\stdClass $step): array => $this->serialiseStep($step));

        return response()->json(['steps' => $steps]);
    }

    /** @return array<string, array<int, string>> */
    private function blockRules(): array
    {
        return [
            'class' => ['required', 'string'],
            'state' => ['required', 'string'],
        ];
    }

    /** @return array<string, array<int, string>> */
    private function blockStepRules(): array
    {
        return ['block_uuid' => ['required', 'string']];
    }

    private function blockQuery(string $prefix, string $class, string $stateInput): Builder
    {
        $query = DB::table($this->stepsTable($prefix))
            ->select('block_uuid', DB::raw('MAX(created_at) as latest'), DB::raw('COUNT(*) as step_count'))
            ->where('class', $class);

        if ($stateInput === 'Throttled') {
            return $query->where('state', 'StepDispatcher\\States\\Pending')
                ->where('is_throttled', true)
                ->groupBy('block_uuid')
                ->orderByDesc('latest')
                ->limit(10);
        }

        $query->where('state', 'StepDispatcher\\States\\'.$stateInput);
        if ($stateInput === 'Pending') {
            $query->where(fn ($q) => $q->where('is_throttled', false)->orWhereNull('is_throttled'));
        }

        return $query->groupBy('block_uuid')->orderByDesc('latest')->limit(10);
    }

    /** @return array<string, mixed> */
    private function serialiseBlock(\stdClass $row): array
    {
        return [
            'block_uuid' => $row->block_uuid,
            'step_count' => (int) $row->step_count,
            'latest' => $row->latest,
        ];
    }

    private function blockStepsQuery(string $prefix, string $blockUuid): Builder
    {
        return DB::table($this->stepsTable($prefix))
            ->where('block_uuid', $blockUuid)
            ->orderBy('index')
            ->orderBy('id');
    }

    /** @return array<string, mixed> */
    private function serialiseStep(\stdClass $step): array
    {
        return [
            'id' => $step->id,
            'index' => $step->index,
            'class' => $step->class,
            'short_name' => class_basename($step->class ?? ''),
            'state' => $this->displayState(class_basename($step->state ?? ''), $step->is_throttled),
            'label' => $step->label,
            'child_block_uuid' => $step->child_block_uuid,
            'error_message' => $step->error_message,
            'retries' => $step->retries,
            'duration' => $step->duration,
            'started_at' => $step->started_at,
            'completed_at' => $step->completed_at,
        ];
    }

    /**
     * Both fleets' MaintenanceMode pause state in one payload, keyed
     * by slug (`default` / `trading`). Dashboard renders one chip
     * per slug; toggles are independent (no mutex). The all-scope
     * blanket flag (set by OPTIMIZE TABLE callers) shows up on both
     * chips automatically because `isStepsDispatchPaused` ORs it in.
     */
    public function coolingDown(): JsonResponse
    {
        $payload = [];

        foreach (self::PREFIX_MAP as $slug => $packagePrefix) {
            $info = MaintenanceMode::stepsDispatchPauseInfo($packagePrefix);

            $payload[$slug] = [
                'is_paused' => MaintenanceMode::isStepsDispatchPaused($packagePrefix),
                'reason' => $info['reason'] ?? null,
                'paused_at' => $info['paused_at'] ?? null,
                'expires_in_seconds' => $info['expires_in_seconds'] ?? null,
            ];
        }

        return response()->json($payload);
    }

    public function toggleCoolingDown(string $prefix): JsonResponse
    {
        $this->assertPrefix($prefix);

        $packagePrefix = self::PREFIX_MAP[$prefix];
        $isPaused = $this->togglePause($prefix, $packagePrefix);
        $info = MaintenanceMode::stepsDispatchPauseInfo($packagePrefix);

        return response()->json([
            'prefix' => $prefix,
            'is_paused' => $isPaused,
            'reason' => $info['reason'] ?? null,
            'paused_at' => $info['paused_at'] ?? null,
            'expires_in_seconds' => $info['expires_in_seconds'] ?? null,
        ]);
    }

    private function togglePause(string $prefix, string $packagePrefix): bool
    {
        if (MaintenanceMode::isStepsDispatchPaused($packagePrefix)) {
            MaintenanceMode::resumeStepsDispatch($packagePrefix);

            return false;
        }

        MaintenanceMode::pauseStepsDispatch(
            reason: 'admin toggle ('.$prefix.')',
            ttlSeconds: null,
            prefix: $packagePrefix,
        );

        return true;
    }

    private function assertPrefix(string $prefix): void
    {
        if (! array_key_exists($prefix, self::PREFIX_MAP)) {
            abort(404);
        }
    }

    private function stepsTable(string $prefix): string
    {
        return Steps::normalise(self::PREFIX_MAP[$prefix]).'steps';
    }

    private function prefixLabel(string $prefix): string
    {
        return $prefix === 'trading' ? 'Trading' : 'Default';
    }

    private function displayState(string $state, mixed $isThrottled): string
    {
        if ($state === 'Pending' && $isThrottled) {
            return 'Throttled';
        }

        return $state;
    }

    /**
     * Classes that have ever spawned children, detected from the
     * prefix-scoped steps table itself. A class is "parent" if ANY
     * of its rows has `child_block_uuid` set — catches runtime
     * parents that only declare their child block at execution time.
     *
     * @return array<int, string>
     */
    private function parentClasses(string $prefix): array
    {
        $table = $this->stepsTable($prefix);

        return Cache::remember("system.steps.{$prefix}.parent-classes", 15, static function () use ($table): array {
            return DB::table($table)
                ->whereNotNull('child_block_uuid')
                ->distinct()
                ->pluck('class')
                ->all();
        });
    }

    /**
     * Per-state counts restricted to leaf steps. NotRunnable dropped
     * (structural marker, not activity).
     *
     * @return array<string, int>
     */
    private function buildLeafTotals(string $prefix): array
    {
        $table = $this->stepsTable($prefix);
        $parentClasses = array_flip($this->parentClasses($prefix));
        $rows = $this->leafTotalRows($prefix, $table);

        $totals = [];
        foreach ($rows as $row) {
            $this->addLeafTotal($totals, $row, $parentClasses);
        }

        return $totals;
    }

    /** @return array<int, array<string, mixed>> */
    private function leafTotalRows(string $prefix, string $table): array
    {
        return Cache::remember("system.steps.{$prefix}.leaf-totals", 30, static function () use ($table) {
            return DB::table($table)
                ->select('class', 'state', 'is_throttled', DB::raw('COUNT(*) as total'))
                ->groupBy('class', 'state', 'is_throttled')
                ->get()
                ->map(static fn (object $row): array => (array) $row)
                ->all();
        });
    }

    /**
     * @param  array<string, int>  $totals
     * @param  array<string, int>  $parentClasses
     * @param  array<string, mixed>  $row
     */
    private function addLeafTotal(array &$totals, array $row, array $parentClasses): void
    {
        if (isset($parentClasses[$row['class']])) {
            return;
        }

        $state = class_basename($row['state']);
        if ($state === 'NotRunnable') {
            return;
        }

        $state = $this->displayState($state, $row['is_throttled']);
        $totals[$state] = ($totals[$state] ?? 0) + (int) $row['total'];
    }

    /**
     * Leaf-step throughput gauge: current 10s completion rate vs the
     * peak 10s bucket seen over the last 5 minutes.
     *
     * @return array<string, int|float|null>
     */
    private function buildLeafThroughput(string $prefix): array
    {
        $table = $this->stepsTable($prefix);
        $parentClasses = $this->parentClasses($prefix);

        return Cache::remember("system.steps.{$prefix}.leaf-throughput", 3, fn (): array => $this->calculateLeafThroughput($table, $parentClasses));
    }

    /**
     * @param  array<int, string>  $parentClasses
     * @return array<string, int|float>
     */
    private function calculateLeafThroughput(string $table, array $parentClasses): array
    {
        $current = $this->leafCurrentCount($table, $parentClasses);
        $peak = $this->leafPeakCount($table, $parentClasses);
        $saturation = $peak > 0 ? min(100.0, ($current / $peak) * 100) : 0;

        return [
            'current_per_10s' => $current,
            'peak_per_10s' => $peak,
            'saturation' => round($saturation, 1),
        ];
    }

    /** @param array<int, string> $parentClasses */
    private function leafCurrentCount(string $table, array $parentClasses): int
    {
        return (int) $this->leafCompletedQuery($table, $parentClasses)
            ->whereRaw('completed_at >= DATE_SUB(NOW(), INTERVAL 10 SECOND)')
            ->count();
    }

    /** @param array<int, string> $parentClasses */
    private function leafPeakCount(string $table, array $parentClasses): int
    {
        $peakRow = $this->leafCompletedQuery($table, $parentClasses)
            ->selectRaw('FLOOR(UNIX_TIMESTAMP(completed_at) / 10) AS bucket, COUNT(*) AS bucket_count')
            ->whereRaw('completed_at >= DATE_SUB(NOW(), INTERVAL 5 MINUTE)')
            ->groupBy('bucket')
            ->orderByDesc('bucket_count')
            ->first();

        return $peakRow ? (int) $peakRow->bucket_count : 0;
    }

    /** @param array<int, string> $parentClasses */
    private function leafCompletedQuery(string $table, array $parentClasses): Builder
    {
        return DB::table($table)
            ->when(! empty($parentClasses), static function ($query) use ($parentClasses) {
                $query->whereNotIn('class', $parentClasses);
            })
            ->where('state', 'StepDispatcher\\States\\Completed');
    }

    /**
     * Per-API saturation gauges. Identical across both prefixes
     * (api_request_logs is shared infrastructure, not prefix-scoped),
     * but cache keys are still suffixed so a refactor that scopes
     * the gauges per fleet later doesn't need a cache-key migration.
     *
     * @return array<int, array<string, mixed>>
     */
    private function buildApiGauges(string $prefix): array
    {
        $apis = $this->apiGaugeDefinitions();

        return Cache::remember("system.steps.{$prefix}.api-gauges", 3, fn (): array => $this->buildGaugeRows($apis));
    }

    /** @return array<string, string> */
    private function apiGaugeDefinitions(): array
    {
        return [
            'taapi' => 'TAAPI',
            'coinmarketcap' => 'CoinMarketCap',
            'binance' => 'Binance',
            'bybit' => 'Bybit',
            'kucoin' => 'KuCoin',
            'bitget' => 'Bitget',
        ];
    }

    /**
     * @param  array<string, string>  $apis
     * @return array<int, array<string, mixed>>
     */
    private function buildGaugeRows(array $apis): array
    {
        $gauges = [];
        foreach ($apis as $canonical => $label) {
            $gauges[] = $this->gaugeFor($canonical, $label);
        }

        return $gauges;
    }

    /**
     * @return array<string, mixed>
     */
    private function gaugeFor(string $canonical, string $label): array
    {
        $capRps = $this->capRequestsPerSecond($canonical);
        $windowSeconds = $this->lookbackWindowSeconds($canonical);
        $apiSystemId = DB::table('api_systems')->where('canonical', $canonical)->value('id');

        $empty = $this->emptyGauge($canonical, $label, $capRps, $windowSeconds);

        if ($apiSystemId === null || $capRps === null || $capRps <= 0) {
            return $empty;
        }

        $meta = $this->recentGaugeMeta($apiSystemId);

        if ($meta === null) {
            return $empty;
        }

        return $this->gaugeWithRequests($canonical, $label, $apiSystemId, $capRps, $windowSeconds, $meta, $empty);
    }

    private function recentGaugeMeta(mixed $apiSystemId): ?\stdClass
    {
        $meta = DB::table('api_request_logs')
            ->where('api_system_id', $apiSystemId)
            ->whereNotNull('http_response_code')
            ->where('http_response_code', '!=', 429)
            ->selectRaw('MAX(created_at) AS newest, TIMESTAMPDIFF(SECOND, MAX(created_at), NOW()) AS age_seconds')
            ->first();

        return $meta !== null && $meta->newest !== null ? $meta : null;
    }

    /**
     * @param  array<string, bool|float|int|string|null>  $empty
     * @return array<string, bool|float|int|string|null>
     */
    private function gaugeWithRequests(string $canonical, string $label, mixed $apiSystemId, float $capRps, int $windowSeconds, \stdClass $meta, array $empty): array
    {
        $newest = $meta->newest;
        $windowStart = (new DateTimeImmutable($newest))->modify("-{$windowSeconds} seconds")->format('Y-m-d H:i:s');

        $count = $this->gaugeRequestCount($apiSystemId, $windowStart, $newest);

        if ($count === 0) {
            return $empty;
        }

        $observedRps = $count / $windowSeconds;
        $saturation = min(100.0, ($observedRps / $capRps) * 100);

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

    private function gaugeRequestCount(mixed $apiSystemId, string $windowStart, mixed $newest): int
    {
        return (int) DB::table('api_request_logs')
            ->where('api_system_id', $apiSystemId)
            ->whereNotNull('http_response_code')
            ->where('http_response_code', '!=', 429)
            ->whereBetween('created_at', [$windowStart, $newest])
            ->count();
    }

    /** @return array<string, bool|float|int|string|null> */
    private function emptyGauge(string $canonical, string $label, ?float $capRps, int $windowSeconds): array
    {
        return [
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
    }

    private function lookbackWindowSeconds(string $canonical): int
    {
        $config = config("kraite.throttlers.{$canonical}");

        if (is_array($config) && isset($config['window_seconds'])) {
            return (int) $config['window_seconds'];
        }

        return 60;
    }

    private function capRequestsPerSecond(string $canonical): ?float
    {
        $config = config("kraite.throttlers.{$canonical}");

        if (! is_array($config)) {
            return null;
        }

        $requestsPerWindow = $config['requests_per_window'] ?? null;
        $windowSeconds = $config['window_seconds'] ?? null;
        $windowRate = $this->windowRate($canonical, $config, $requestsPerWindow, $windowSeconds);
        if ($windowRate !== null) {
            return $windowRate;
        }

        $minDelayMs = $config['min_delay_ms'] ?? ($config['min_delay_between_requests_ms'] ?? null);

        if ($minDelayMs && $minDelayMs > 0) {
            return 1000.0 / $minDelayMs;
        }

        return null;
    }

    /** @param array<string, mixed> $config */
    private function windowRate(string $canonical, array $config, mixed $requestsPerWindow, mixed $windowSeconds): ?float
    {
        if (! $requestsPerWindow || ! $windowSeconds) {
            return null;
        }

        $safety = $canonical === 'bybit' ? 1.0 : (float) ($config['safety_threshold'] ?? 1.0);

        return ($requestsPerWindow * $safety) / $windowSeconds;
    }
}
