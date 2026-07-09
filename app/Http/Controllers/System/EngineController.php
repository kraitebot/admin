<?php

declare(strict_types=1);

namespace App\Http\Controllers\System;

use App\Http\Controllers\Controller;
use BrunoCFalcao\AiBridge\Chat\ChatManager;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Schema;
use Illuminate\View\View;
use Throwable;

/**
 * Engine — "how performant is my step dispatcher?" in five seconds.
 *
 * Health strip (both fleets): backlog trend (growing = unhealthy regardless
 * of size), chew-per-minute, and NON-RECOVERED failures (failed steps whose
 * workflow never completed a resolve-exception rescue — workflow-recovered
 * cases are excluded everywhere on this page).
 *
 * Failures triage: last two weeks across live + archive tables of both
 * fleets, one row per step class, resolve-all-at-once per class, and a
 * persisted AI verdict per newest occurrence.
 */
class EngineController extends Controller
{
    private const FAILED = 'StepDispatcher\\States\\Failed';

    private const RESOLVER_COMPLETED = 'StepDispatcher\\States\\Completed';

    /** Live steps table per fleet. Archives extend each with `_archive`. */
    private const FLEETS = [
        'default' => 'steps',
        'trading' => 'trading_steps',
    ];

    public function index(): View
    {
        return view('system.engine', [
            'engine' => [
                'gauges' => $this->rescued(fn () => $this->gauges(), $this->emptyGauges()),
                'failures' => $this->rescued(fn () => $this->failureGroups(), []),
            ],
        ]);
    }

    public function data(): JsonResponse
    {
        return response()->json([
            'gauges' => $this->rescued(fn () => $this->gauges(), $this->emptyGauges()),
        ]);
    }

    public function failures(): JsonResponse
    {
        return response()->json([
            'failures' => $this->rescued(fn () => $this->failureGroups(), []),
        ]);
    }

    /**
     * Generate + persist an AI verdict for the NEWEST occurrence of a failed
     * step class. The verdict sticks to that step row for later re-reading;
     * it does NOT mark the failure analysed — that's the operator's call.
     */
    public function troubleshoot(Request $request, ChatManager $chat): JsonResponse
    {
        $validated = $request->validate([
            'class' => ['required', 'string', 'max:255'],
        ]);

        $occurrence = $this->newestOccurrence($validated['class']);

        if ($occurrence === null) {
            return response()->json(['ok' => false, 'error' => 'No unresolved failure found for that step class.'], 404);
        }

        try {
            $verdict = $chat->send(
                messages: $this->buildTriageMessages($occurrence->row),
                connection: 'step-triage',
            );

            DB::table($occurrence->table)
                ->where('id', $occurrence->row->id)
                ->update(['exception_verdict' => $verdict]);

            return response()->json([
                'ok' => true,
                'verdict' => $verdict,
                'model' => config('ai-bridge.resolver.connections.step-triage'),
            ]);
        } catch (Throwable $e) {
            Log::error('[Engine triage] '.$e->getMessage(), ['exception' => $e::class]);

            return response()->json(['ok' => false, 'error' => $e->getMessage()], 500);
        }
    }

    /**
     * Resolve EVERY unresolved failure of a step class in one action — same
     * class means same root cause, so the operator analyses the newest and
     * clears the group. Sweeps live + archive tables of both fleets.
     */
    public function resolve(Request $request): JsonResponse
    {
        $validated = $request->validate([
            'class' => ['required', 'string', 'max:255'],
        ]);

        $resolved = 0;
        foreach ($this->stepTables() as $table) {
            $resolved += DB::table($table)
                ->where('class', $validated['class'])
                ->where('state', self::FAILED)
                ->where('exception_analysed', 0)
                ->update(['exception_analysed' => 1]);
        }

        return response()->json(['ok' => true, 'resolved' => $resolved]);
    }

    // ------------------------------------------------------------------
    // Gauges
    // ------------------------------------------------------------------

    /**
     * @return array<string, mixed>
     */
    private function gauges(): array
    {
        return Cache::remember('system.engine.gauges', 10, function (): array {
            $fleets = [];
            $combined = ['pending' => 0, 'processing' => 0, 'created_10m' => 0, 'resolved_10m' => 0, 'chew_per_min' => 0.0, 'failures' => 0];
            $minuteBuckets = [];

            foreach (self::FLEETS as $fleet => $table) {
                if (! Schema::hasTable($table)) {
                    // No table = unknown, never "0 pending" — see the guard
                    // after the loop.
                    continue;
                }

                // All timestamp windows compare at the DB level — ingestion
                // writes these rows in its own timezone, admin runs UTC.
                $pending = (int) DB::table($table)->where('state', 'StepDispatcher\\States\\Pending')->count();

                // Really-processing = LEAF steps (own child_block_uuid is null,
                // so this row does work rather than orchestrating a child block)
                // in the Running state. Parent/orchestrator steps and anything
                // still waiting are deliberately excluded.
                $processing = (int) DB::table($table)
                    ->where('state', 'StepDispatcher\\States\\Running')
                    ->whereNull('child_block_uuid')
                    ->count();
                $created10 = (int) DB::table($table)->whereRaw('created_at >= DATE_SUB(NOW(), INTERVAL 10 MINUTE)')->count();
                $resolved10 = (int) DB::table($table)->whereRaw('completed_at >= DATE_SUB(NOW(), INTERVAL 10 MINUTE)')->count();
                $resolved5 = (int) DB::table($table)->whereRaw('completed_at >= DATE_SUB(NOW(), INTERVAL 5 MINUTE)')->count();
                $failures = $this->nonRecoveredFailedCount($table);

                // Per-minute completion counts over the trailing hour — the
                // fleet's demonstrated capacity even when the CURRENT minute
                // is idle ("low chew because no work" vs "low chew because
                // sick"). Merged per minute across fleets for the combined
                // peak so simultaneous bursts add up.
                $buckets = DB::table($table)
                    ->selectRaw("DATE_FORMAT(completed_at, '%Y%m%d%H%i') as minute, COUNT(*) as total")
                    ->whereRaw('completed_at >= DATE_SUB(NOW(), INTERVAL 60 MINUTE)')
                    ->groupBy('minute')
                    ->pluck('total', 'minute');

                $fleets[$fleet] = [
                    'pending' => $pending,
                    'processing' => $processing,
                    'created_10m' => $created10,
                    'resolved_10m' => $resolved10,
                    'chew_per_min' => round($resolved5 / 5, 1),
                    'peak_per_min_1h' => (int) ($buckets->max() ?? 0),
                    'failures' => $failures,
                ];

                foreach ($buckets as $minute => $total) {
                    $minuteBuckets[$minute] = ($minuteBuckets[$minute] ?? 0) + (int) $total;
                }

                $combined['pending'] += $pending;
                $combined['processing'] += $processing;
                $combined['created_10m'] += $created10;
                $combined['resolved_10m'] += $resolved10;
                $combined['chew_per_min'] += $fleets[$fleet]['chew_per_min'];
                $combined['failures'] += $failures;
            }

            // Not a single fleet table exists (dev database without the
            // dispatcher) — report unknown, not a healthy-looking zero.
            if ($fleets === []) {
                return $this->emptyGauges();
            }

            $combined['peak_per_min_1h'] = $minuteBuckets === [] ? 0 : max($minuteBuckets);

            // Backlog trend: inflow vs outflow over the same 10-minute window.
            // Positive = accumulating (bad); negative/zero = draining (healthy,
            // whatever the absolute backlog size).
            $combined['flow_delta_10m'] = $combined['created_10m'] - $combined['resolved_10m'];

            return ['combined' => $combined, 'fleets' => $fleets];
        });
    }

    /**
     * Failed steps whose workflow never completed a resolve-exception rescue,
     * still unresolved by the operator. Live table only — the gauge reads
     * current health; history lives in the failures tab.
     */
    private function nonRecoveredFailedCount(string $table): int
    {
        return (int) DB::table("{$table} as s")
            ->where('s.state', self::FAILED)
            ->where('s.exception_analysed', 0)
            ->whereNotExists(function ($query) use ($table): void {
                $query->select(DB::raw(1))
                    ->from("{$table} as r")
                    ->whereColumn('r.workflow_id', 's.workflow_id')
                    ->where('r.type', 'resolve-exception')
                    ->where('r.state', self::RESOLVER_COMPLETED);
            })
            ->count();
    }

    // ------------------------------------------------------------------
    // Failures triage
    // ------------------------------------------------------------------

    /**
     * One row per failed step class: occurrence count over the last two
     * weeks (live + archive, both fleets), newest first, workflow-recovered
     * and operator-resolved occurrences excluded. Each row carries its
     * newest occurrence's error snippet + persisted verdict (if any).
     *
     * @return array<int, array<string, mixed>>
     */
    private function failureGroups(): array
    {
        return Cache::remember('system.engine.failures', 15, function (): array {
            $union = null;

            foreach ($this->stepTables() as $table) {
                $query = DB::table("{$table} as s")
                    ->selectRaw('s.class, s.created_at, s.id, ? as source_table', [$table])
                    ->where('s.state', self::FAILED)
                    ->where('s.exception_analysed', 0)
                    ->whereNotNull('s.class')
                    ->whereRaw('s.created_at >= DATE_SUB(NOW(), INTERVAL 14 DAY)')
                    ->whereNotExists(function ($q) use ($table): void {
                        $q->select(DB::raw(1))
                            ->from("{$table} as r")
                            ->whereColumn('r.workflow_id', 's.workflow_id')
                            ->where('r.type', 'resolve-exception')
                            ->where('r.state', self::RESOLVER_COMPLETED);
                    });

                $union = $union === null ? $query : $union->unionAll($query);
            }

            if ($union === null) {
                return [];
            }

            // Age computed at the DB level — ingestion writes created_at in
            // its own timezone; a PHP-side diff drifts by the offset.
            $groups = DB::query()->fromSub($union, 'failures')
                ->selectRaw('class, COUNT(*) as occurrences, MAX(created_at) as latest_at, GREATEST(TIMESTAMPDIFF(SECOND, MAX(created_at), NOW()), 0) as age_seconds')
                ->groupBy('class')
                ->orderByDesc('latest_at')
                ->limit(100)
                ->get();

            return $groups->map(function ($group): array {
                $newest = $this->newestOccurrence((string) $group->class);

                return [
                    'class' => $group->class,
                    'short_name' => class_basename((string) $group->class),
                    'occurrences' => (int) $group->occurrences,
                    'latest_at' => $group->latest_at,
                    'age' => $this->shortAge((int) $group->age_seconds),
                    'error_snippet' => $newest ? mb_substr((string) ($newest->row->error_message ?? ''), 0, 220) : null,
                    'verdict' => $newest?->row->exception_verdict,
                ];
            })->values()->all();
        });
    }

    /**
     * The newest unresolved failed occurrence of a class across every step
     * table — the row triage analyses, and the row verdicts stick to.
     *
     * @return object{table: string, row: object}|null
     */
    private function newestOccurrence(string $class): ?object
    {
        $newest = null;

        foreach ($this->stepTables() as $table) {
            $row = DB::table($table)
                ->where('class', $class)
                ->where('state', self::FAILED)
                ->where('exception_analysed', 0)
                ->orderByDesc('created_at')
                ->orderByDesc('id')
                ->first();

            if ($row !== null && ($newest === null || $row->created_at > $newest->row->created_at)) {
                $newest = (object) ['table' => $table, 'row' => $row];
            }
        }

        return $newest;
    }

    /**
     * @return array<int, array{role: string, content: string}>
     */
    private function buildTriageMessages(object $step): array
    {
        $system = <<<'PROMPT'
You are the on-call engineer for Kraite, an automated crypto trading
platform built on a step-dispatcher engine: workflows are trees of steps,
each step is a queued job with a state machine (Pending → Dispatched →
Running → Completed/Failed). Failed is terminal for the step; some
workflows carry a resolve-exception fallback step that can rescue the
workflow. You are reading ONE failed step.

Return a SHORT verdict (under 150 words, plain text, no markdown headers):
1. Root cause — what actually broke, in one or two sentences.
2. Blast radius — what this failure means for the platform (trading
   impact? data gap? cosmetic?).
3. Action — the single most useful next step for the operator.

Be concrete and honest. If the evidence is insufficient, say what is
missing instead of guessing.
PROMPT;

        $context = [
            'step_class' => $step->class,
            'error_message' => mb_substr((string) ($step->error_message ?? ''), 0, 1500),
            'stack_trace_head' => mb_substr((string) ($step->error_stack_trace ?? ''), 0, 2500),
            'arguments' => mb_substr((string) ($step->arguments ?? ''), 0, 800),
            'canonical' => $step->canonical,
            'queue' => $step->queue,
            'hostname' => $step->hostname,
            'retries' => $step->retries,
            'failed_at' => $step->created_at,
        ];

        return [
            ['role' => 'system', 'content' => $system],
            ['role' => 'user', 'content' => "Diagnose this failed step:\n\n".json_encode($context, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES)],
        ];
    }

    // ------------------------------------------------------------------
    // Plumbing
    // ------------------------------------------------------------------

    /**
     * Every existing step table: live + archive, both fleets. Absent tables
     * (dev database without the trading fleet) are silently skipped.
     *
     * @return list<string>
     */
    private function stepTables(): array
    {
        return Cache::remember('system.engine.tables', 300, function (): array {
            $tables = [];
            foreach (self::FLEETS as $liveTable) {
                foreach ([$liveTable, "{$liveTable}_archive"] as $table) {
                    if (Schema::hasTable($table)) {
                        $tables[] = $table;
                    }
                }
            }

            return $tables;
        });
    }

    private function rescued(callable $callback, mixed $fallback): mixed
    {
        return rescue($callback, $fallback);
    }

    /**
     * @return array<string, mixed>
     */
    private function emptyGauges(): array
    {
        return [
            'combined' => [
                'pending' => null, 'processing' => null, 'created_10m' => null,
                'resolved_10m' => null, 'chew_per_min' => null, 'peak_per_min_1h' => null,
                'failures' => null, 'flow_delta_10m' => null,
            ],
            'fleets' => [],
        ];
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
}
