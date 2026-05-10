<?php

declare(strict_types=1);

namespace App\Http\Controllers\System;

use App\Http\Controllers\Controller;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Process;

class CommandsController extends Controller
{
    private static function ingestionPath(): string
    {
        return config('kraite.ingestion_path', '/home/waygou/ingestion.kraite.com');
    }

    private static function isRemote(): bool
    {
        return ! is_dir(self::ingestionPath());
    }

    private function runOnIngestion(array $command, int $timeout = 60): \Illuminate\Contracts\Process\ProcessResult
    {
        if (! self::isRemote()) {
            return Process::path(self::ingestionPath())->timeout($timeout)->run($command);
        }

        $host = config('kraite.ingestion_ssh_host');
        $user = config('kraite.ingestion_ssh_user');
        $key = config('kraite.ingestion_ssh_key');
        $remotePath = config('kraite.ingestion_remote_path');

        if (! $host || ! $user || ! $key || ! $remotePath) {
            throw new \RuntimeException('Ingestion SSH config missing. Set KRAITE_INGESTION_SSH_HOST, _USER, _KEY, _REMOTE_PATH in .env.');
        }

        $escaped = implode(' ', array_map('escapeshellarg', $command));

        return Process::timeout($timeout)->run([
            'ssh', '-i', $key,
            '-o', 'StrictHostKeyChecking=no',
            '-o', 'BatchMode=yes',
            "{$user}@{$host}",
            "cd {$remotePath} && {$escaped}",
        ]);
    }

    public function index()
    {
        $commands = $this->getCommandList();
        $schedule = $this->getSchedule();

        return view('system.commands', compact('commands', 'schedule'));
    }

    /**
     * Read the ingestion app's `schedule:list` and parse it into a list of
     * scheduled tasks for the read-only Scheduler tab on /system/commands.
     *
     * @return array<int, array<string, string>>
     */
    private function getSchedule(): array
    {
        $result = $this->runOnIngestion(['php', 'artisan', 'schedule:list', '--no-ansi']);

        if (! $result->successful()) {
            return [];
        }

        $lines = explode("\n", trim($result->output()));
        $schedule = [];

        foreach ($lines as $line) {
            $line = trim($line);
            if (empty($line)) {
                continue;
            }

            // Parse: "cron_expression  command  Next Due: time_string"
            if (preg_match('/^(.+?)\s+php artisan\s+(\S+)(?:\s+(.+?))?\s+Next Due:\s+(.+)$/i', $line, $matches)) {
                $cron = trim($matches[1]);
                $command = trim($matches[2]);
                $arguments = isset($matches[3]) ? trim($matches[3]) : '';
                $nextDue = trim($matches[4]);

                // Strip trailing dot Symfony adds on optional argument output.
                $arguments = rtrim($arguments, ' .');

                $schedule[] = [
                    'cron' => $cron,
                    'command' => $command,
                    'arguments' => $arguments,
                    'next_due' => $nextDue,
                    'frequency' => $this->cronToHuman($cron),
                ];
            }
        }

        return $schedule;
    }

    private function cronToHuman(string $cron): string
    {
        // Strip trailing duration modifiers (e.g. "1s") that schedule:list adds.
        $cron = preg_replace('/\s+\d+[smh]$/', '', $cron);
        $parts = preg_split('/\s+/', trim($cron));

        if (count($parts) < 5) {
            return $cron;
        }

        [$min, $hour, $day, $month, $dow] = $parts;

        if ($min === '*' && $hour === '*' && $day === '*' && $month === '*' && $dow === '*') {
            return 'Every minute';
        }

        if (preg_match('/^\*\/(\d+)$/', $min, $m) && $hour === '*') {
            return "Every {$m[1]} minutes";
        }

        if (is_numeric($min) && $hour === '*' && $day === '*') {
            return "Hourly at :{$min}";
        }

        if (is_numeric($min) && preg_match('/^\*\/(\d+)$/', $hour, $m)) {
            return "Every {$m[1]} hours at :{$min}";
        }

        if (is_numeric($min) && is_numeric($hour) && $day === '*' && $month === '*' && $dow === '*') {
            return sprintf('Daily at %02d:%02d', (int) $hour, (int) $min);
        }

        return $cron;
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

        $result = $this->runOnIngestion(['php', 'artisan', $commandName, '--help', '--format=json', '--no-ansi']);

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
            $result = $this->runOnIngestion($cmd, 300);
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
        $result = Process::path(self::ingestionPath())
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
