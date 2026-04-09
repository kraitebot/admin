<?php

declare(strict_types=1);

namespace App\Http\Controllers\System;

use App\Http\Controllers\Controller;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;

class StepDispatcherController extends Controller
{
    public function index()
    {
        return view('system.step-dispatcher');
    }

    public function data(): JsonResponse
    {
        $rows = DB::table('steps')
            ->select('class', 'state', DB::raw('COUNT(*) as total'))
            ->groupBy('class', 'state')
            ->get();

        $pivot = [];
        foreach ($rows as $row) {
            $class = $row->class ?? '(no class)';
            $state = class_basename($row->state);
            if (! isset($pivot[$class])) {
                $pivot[$class] = [];
            }
            $pivot[$class][$state] = (int) $row->total;
        }

        ksort($pivot);

        $result = [];
        foreach ($pivot as $class => $states) {
            $result[] = [
                'class' => $class,
                'short_name' => class_basename($class),
                'states' => $states,
            ];
        }

        $totals = [];
        foreach ($rows as $row) {
            $state = class_basename($row->state);
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
        $stateClass = 'StepDispatcher\\States\\'.$request->input('state');

        $blockUuids = DB::table('steps')
            ->select('block_uuid', DB::raw('MAX(created_at) as latest'), DB::raw('COUNT(*) as step_count'))
            ->where('class', $class)
            ->where('state', $stateClass)
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
            ->map(fn ($step) => [
                'id' => $step->id,
                'index' => $step->index,
                'class' => $step->class,
                'short_name' => class_basename($step->class ?? ''),
                'state' => class_basename($step->state ?? ''),
                'label' => $step->label,
                'child_block_uuid' => $step->child_block_uuid,
                'error_message' => $step->error_message,
                'retries' => $step->retries,
                'duration' => $step->duration,
                'started_at' => $step->started_at,
                'completed_at' => $step->completed_at,
            ]);

        return response()->json(['steps' => $steps]);
    }
}
