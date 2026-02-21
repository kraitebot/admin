<?php

declare(strict_types=1);

use Illuminate\Http\Request;
use Illuminate\Support\Facades\Process;
use Illuminate\Support\Facades\Route;
use Kraite\CommandRunner\CommandRegistry;

Route::get('/relatable-models', function (Request $request) {
    $request->validate([
        'type' => 'required|string',
        'table' => 'required|string|in:api_request_logs,model_logs',
    ]);

    $type = $request->input('type');
    $table = $request->input('table');

    if (! class_exists($type)) {
        return response()->json([]);
    }

    $ids = \Illuminate\Support\Facades\DB::table($table)
        ->where('relatable_type', $type)
        ->whereNotNull('relatable_id')
        ->distinct()
        ->pluck('relatable_id');

    if ($ids->isEmpty()) {
        return response()->json([]);
    }

    $model = new $type;
    $instances = $model::query()->whereIn($model->getKeyName(), $ids)->get();

    $options = $instances->map(function ($instance) {
        $label = $instance->name ?? $instance->token ?? $instance->canonical ?? "#{$instance->getKey()}";

        return [
            'label' => $label.' (#'.$instance->getKey().')',
            'value' => (string) $instance->getKey(),
        ];
    })->sortBy('label')->values();

    return response()->json($options);
});

Route::get('/', function (Request $request) {
    return response()->json(CommandRegistry::list());
});

Route::post('/run', function (Request $request) {
    $request->validate([
        'command' => 'required|string',
        'options' => 'array',
    ]);

    $command = $request->input('command');

    if (! CommandRegistry::isAllowed($command)) {
        return response()->json(['error' => 'Command not allowed.'], 403);
    }

    $registry = CommandRegistry::find($command);
    $options = [];

    foreach ($request->input('options', []) as $key => $value) {
        if (! array_key_exists($key, $registry['options'])) {
            continue;
        }

        $optionMeta = $registry['options'][$key];

        if ($optionMeta['type'] === 'boolean' && $value) {
            $options[$key] = true;
        } elseif ($optionMeta['type'] === 'select' && $value !== '' && $value !== null) {
            if (isset($optionMeta['choices']) && in_array($value, $optionMeta['choices'], true)) {
                $options[$key] = $value;
            }
        } elseif ($optionMeta['type'] === 'text' && $value !== '' && $value !== null) {
            $options[$key] = (string) $value;
        }
    }

    try {
        $args = [];
        foreach ($options as $key => $value) {
            if ($value === true) {
                $args[] = $key;
            } else {
                $args[] = $key.'='.escapeshellarg((string) $value);
            }
        }

        $artisan = base_path('artisan');
        $cmd = 'php '.escapeshellarg($artisan).' '.escapeshellarg($command).' --no-interaction '.implode(' ', $args).' 2>&1';

        $result = Process::timeout(300)->run($cmd);

        return response()->json([
            'exit_code' => $result->exitCode(),
            'output' => $result->output(),
        ]);
    } catch (\Throwable $e) {
        return response()->json([
            'exit_code' => 1,
            'output' => $e->getMessage(),
        ], 500);
    }
});
