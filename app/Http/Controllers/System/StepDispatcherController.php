<?php

declare(strict_types=1);

namespace App\Http\Controllers\System;

use App\Http\Controllers\Controller;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Kraite\Core\Models\Kraite;

class StepDispatcherController extends Controller
{
    public function index()
    {
        return view('system.step-dispatcher');
    }

    public function data(): JsonResponse
    {
        $rows = DB::table('steps')
            ->select('class', 'state', 'is_throttled', DB::raw('COUNT(*) as total'))
            ->groupBy('class', 'state', 'is_throttled')
            ->get();

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

        $result = [];
        foreach ($pivot as $class => $states) {
            $health = $healthRows[$class] ?? null;
            $result[] = [
                'class' => $class,
                'short_name' => class_basename($class),
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
}
