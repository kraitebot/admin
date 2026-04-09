<?php

declare(strict_types=1);

namespace App\Http\Controllers\System;

use App\Http\Controllers\Controller;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Artisan;

class CommandsController extends Controller
{
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
        $all = Artisan::all();

        if (! isset($all[$commandName])) {
            return response()->json(['error' => 'Command not found.'], 404);
        }

        $cmd = $all[$commandName];
        $definition = $cmd->getDefinition();

        $arguments = [];
        foreach ($definition->getArguments() as $arg) {
            $arguments[] = [
                'name' => $arg->getName(),
                'description' => $arg->getDescription(),
                'required' => $arg->isRequired(),
                'is_array' => $arg->isArray(),
                'default' => $arg->getDefault(),
            ];
        }

        $options = [];
        foreach ($definition->getOptions() as $opt) {
            if (in_array($opt->getName(), ['help', 'quiet', 'verbose', 'version', 'ansi', 'no-ansi', 'no-interaction', 'env'])) {
                continue;
            }

            $options[] = [
                'name' => $opt->getName(),
                'shortcut' => $opt->getShortcut(),
                'description' => $opt->getDescription(),
                'default' => $opt->getDefault(),
                'accept_value' => $opt->acceptValue(),
                'value_required' => $opt->isValueRequired(),
            ];
        }

        return response()->json([
            'name' => $commandName,
            'description' => $cmd->getDescription(),
            'help' => $cmd->getHelp(),
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

        $all = Artisan::all();

        if (! isset($all[$commandName])) {
            return response()->json(['error' => 'Command not found.'], 404);
        }

        $params = $arguments;
        foreach ($options as $key => $value) {
            $params['--'.$key] = $value;
        }
        $params['--no-interaction'] = true;

        try {
            $startTime = microtime(true);
            $exitCode = Artisan::call($commandName, $params);
            $output = Artisan::output();
            $duration = round((microtime(true) - $startTime) * 1000, 2);

            return response()->json([
                'exit_code' => $exitCode,
                'output' => $output,
                'duration' => $duration,
            ]);
        } catch (\Throwable $e) {
            return response()->json(['error' => $e->getMessage()], 422);
        }
    }

    private function getCommandList(): array
    {
        $commands = Artisan::all();
        $list = [];

        foreach ($commands as $name => $command) {
            if (! str_starts_with($name, 'kraite:')) {
                continue;
            }

            $list[] = [
                'name' => $name,
                'description' => $command->getDescription(),
            ];
        }

        usort($list, fn (array $a, array $b): int => strcmp($a['name'], $b['name']));

        return $list;
    }
}
