<?php

declare(strict_types=1);

namespace App\Http\Controllers\System;

use App\Http\Controllers\Controller;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Process;

class CommandsController extends Controller
{
    private const INGESTION_PATH = '/home/waygou/ingestion.kraite.com';

    public function index()
    {
        $commands = $this->getCommandList();

        return view('system.commands', compact('commands'));
    }

    public function details(Request $request): JsonResponse
    {
        $request->validate([
            'command' => ['required', 'string'],
        ]);

        $commandName = $request->input('command');

        if (! str_starts_with($commandName, 'kraite:')) {
            return response()->json(['error' => 'Only kraite commands are available.'], 422);
        }

        $result = Process::path(self::INGESTION_PATH)
            ->run(['php', 'artisan', $commandName, '--help', '--format=json', '--no-ansi']);

        if (! $result->successful()) {
            return response()->json(['error' => 'Command not found.'], 404);
        }

        $data = json_decode($result->output(), true);

        if (! $data) {
            return response()->json(['error' => 'Failed to parse command details.'], 500);
        }

        $arguments = [];
        foreach ($data['definition']['arguments'] ?? [] as $name => $arg) {
            if ($name === 'command') {
                continue;
            }
            $arguments[] = [
                'name' => $name,
                'description' => $arg['description'] ?? '',
                'required' => $arg['is_required'] ?? false,
                'is_array' => $arg['is_array'] ?? false,
                'default' => $arg['default'] ?? null,
            ];
        }

        $options = [];
        foreach ($data['definition']['options'] ?? [] as $name => $opt) {
            if (in_array($name, ['help', 'quiet', 'verbose', 'version', 'ansi', 'no-ansi', 'no-interaction', 'env'])) {
                continue;
            }

            $options[] = [
                'name' => $name,
                'shortcut' => $opt['shortcut'] ?? null,
                'description' => $opt['description'] ?? '',
                'default' => $opt['default'] ?? null,
                'accept_value' => $opt['accept_value'] ?? false,
                'value_required' => $opt['is_value_required'] ?? false,
            ];
        }

        return response()->json([
            'name' => $commandName,
            'description' => $data['description'] ?? '',
            'help' => $data['help'] ?? '',
            'arguments' => $arguments,
            'options' => $options,
        ]);
    }

    public function execute(Request $request): JsonResponse
    {
        $request->validate([
            'command' => ['required', 'string'],
            'arguments' => ['sometimes', 'array'],
            'options' => ['sometimes', 'array'],
        ]);

        $commandName = $request->input('command');
        $arguments = $request->input('arguments', []);
        $options = $request->input('options', []);

        if (! str_starts_with($commandName, 'kraite:')) {
            return response()->json(['error' => 'Only kraite commands can be executed.'], 422);
        }

        $cmd = ['php', 'artisan', $commandName, '--no-interaction', '--no-ansi'];

        foreach ($arguments as $value) {
            if ($value !== null && $value !== '') {
                $cmd[] = (string) $value;
            }
        }

        foreach ($options as $key => $value) {
            if ($value === true) {
                $cmd[] = '--'.$key;
            } elseif ($value !== null && $value !== '' && $value !== false) {
                $cmd[] = '--'.$key.'='.$value;
            }
        }

        try {
            $startTime = microtime(true);
            $result = Process::path(self::INGESTION_PATH)->timeout(300)->run($cmd);
            $duration = round((microtime(true) - $startTime) * 1000, 2);

            return response()->json([
                'exit_code' => $result->exitCode(),
                'output' => $result->output() ?: $result->errorOutput() ?: '(no output)',
                'duration' => $duration,
            ]);
        } catch (\Throwable $e) {
            return response()->json(['error' => $e->getMessage()], 422);
        }
    }

    private function getCommandList(): array
    {
        $result = Process::path(self::INGESTION_PATH)
            ->run(['php', 'artisan', 'list', '--format=json', '--no-ansi']);

        if (! $result->successful()) {
            return [];
        }

        $data = json_decode($result->output(), true);
        $list = [];

        foreach ($data['commands'] ?? [] as $command) {
            $name = $command['name'] ?? '';
            if (! str_starts_with($name, 'kraite:')) {
                continue;
            }

            $list[] = [
                'name' => $name,
                'description' => $command['description'] ?? '',
            ];
        }

        usort($list, fn (array $a, array $b): int => strcmp($a['name'], $b['name']));

        return $list;
    }
}
