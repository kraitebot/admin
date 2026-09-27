<?php

declare(strict_types=1);

namespace App\Http\Controllers\System;

use App\Http\Controllers\Controller;
use Illuminate\Contracts\Process\ProcessResult;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Process;

/**
 * @phpstan-type CommandValue scalar|null
 * @phpstan-type CommandField array<string, CommandValue>
 * @phpstan-type CommandDefinitions array<string, CommandField>
 * @phpstan-type CommandDetails array{
 *     description?: CommandValue,
 *     help?: CommandValue,
 *     definition?: array{
 *         arguments?: CommandDefinitions,
 *         options?: CommandDefinitions
 *     }
 * }
 */
final class CommandsController extends Controller
{
    private static function ingestionPath(): string
    {
        return config('kraite.ingestion_path', '/home/waygou/ingestion.kraite.com');
    }

    private static function isRemote(): bool
    {
        return ! is_dir(self::ingestionPath());
    }

    /** @param array<int, string> $command */
    private function runOnIngestion(array $command, int $timeout = 60): ProcessResult
    {
        if (! self::isRemote()) {
            return $this->runLocally($command, $timeout);
        }

        return $this->runRemotely($command, $timeout);
    }

    /** @param array<int, string> $command */
    private function runLocally(array $command, int $timeout): ProcessResult
    {
        $binary = config('kraite.php_binary', 'php');
        $command = array_map(fn (string $argument): string => $argument === 'php' ? $binary : $argument, $command);

        return Process::path(self::ingestionPath())->timeout($timeout)->run($command);
    }

    /** @param array<int, string> $command */
    private function runRemotely(array $command, int $timeout): ProcessResult
    {
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
     * Page-render helper: run a command on ingestion, returning null instead
     * of throwing/failing so the console degrades to an empty state rather
     * than a 500 when the bridge is missing or broken.
     *
     * @param  array<int, string>  $command
     */
    private function tryRunOnIngestion(array $command): ?ProcessResult
    {
        try {
            $result = $this->runOnIngestion($command);
        } catch (\Throwable) {
            return null;
        }

        return $result->successful() ? $result : null;
    }

    /**
     * Read the ingestion app's `schedule:list` and parse it into a list of
     * scheduled tasks for the read-only Scheduler tab on /system/commands.
     *
     * @return array<int, array<string, string>>
     */
    private function getSchedule(): array
    {
        $result = $this->tryRunOnIngestion(['php', 'artisan', 'schedule:list', '--no-ansi']);

        return $result === null ? [] : $this->parseScheduleOutput($result->output());
    }

    /** @return array<int, array<string, string>> */
    private function parseScheduleOutput(string $output): array
    {
        $schedule = [];
        foreach (explode("\n", trim($output)) as $line) {
            $entry = $this->parseScheduleLine($line);
            if ($entry !== null) {
                $schedule[] = $entry;
            }
        }

        return $schedule;
    }

    /** @return array<string, string>|null */
    private function parseScheduleLine(string $line): ?array
    {
        $line = trim($line);
        if ($line === '' || ! preg_match('/^(.+?)\s+php artisan\s+(\S+)(?:\s+(.+?))?\s+Next Due:\s+(.+)$/i', $line, $matches)) {
            return null;
        }

        $cron = trim($matches[1]);
        $arguments = isset($matches[3]) ? trim($matches[3]) : '';

        return [
            'cron' => $cron,
            'command' => trim($matches[2]),
            'arguments' => rtrim($arguments, ' .'),
            'next_due' => trim($matches[4]),
            'frequency' => $this->cronToHuman($cron),
        ];
    }

    private function cronToHuman(string $cron): string
    {
        $cron = $this->normalizeCronExpression($cron);
        $parts = preg_split('/\s+/', trim($cron));

        if (count($parts) < 5) {
            return $cron;
        }

        return $this->cronFrequency($cron, $parts);
    }

    private function normalizeCronExpression(string $cron): string
    {
        return preg_replace('/\s+\d+[smh]$/', '', $cron) ?? $cron;
    }

    /** @param array<int, string> $parts */
    private function cronFrequency(string $cron, array $parts): string
    {
        [$min, $hour, $day, $month, $dow] = $parts;

        $frequency = $this->everyMinuteFrequency($min, $hour, $day, $month, $dow);
        if ($frequency !== null) {
            return $frequency;
        }

        $frequency = $this->everyMinutesFrequency($min, $hour);
        if ($frequency !== null) {
            return $frequency;
        }

        $frequency = $this->hourlyFrequency($min, $hour, $day);
        if ($frequency !== null) {
            return $frequency;
        }

        return $this->calendarFrequency($cron, $min, $hour, $day, $month, $dow);
    }

    private function everyMinuteFrequency(string $min, string $hour, string $day, string $month, string $dow): ?string
    {
        return $this->isEveryMinuteCron($min, $hour, $day, $month, $dow) ? 'Every minute' : null;
    }

    private function isEveryMinuteCron(string $min, string $hour, string $day, string $month, string $dow): bool
    {
        return $min === '*' && $hour === '*' && $day === '*' && $month === '*' && $dow === '*';
    }

    private function everyMinutesFrequency(string $min, string $hour): ?string
    {
        if (preg_match('/^\*\/(\d+)$/', $min, $matches) && $hour === '*') {
            return "Every {$matches[1]} minutes";
        }

        return null;
    }

    private function hourlyFrequency(string $min, string $hour, string $day): ?string
    {
        if (is_numeric($min) && $hour === '*' && $day === '*') {
            return "Hourly at :{$min}";
        }

        return null;
    }

    private function calendarFrequency(string $cron, string $min, string $hour, string $day, string $month, string $dow): string
    {
        $frequency = $this->everyHoursFrequency($min, $hour);
        if ($frequency !== null) {
            return $frequency;
        }

        return $this->dailyFrequency($min, $hour, $day, $month, $dow) ?? $cron;
    }

    private function everyHoursFrequency(string $min, string $hour): ?string
    {
        if (is_numeric($min) && preg_match('/^\*\/(\d+)$/', $hour, $matches)) {
            return "Every {$matches[1]} hours at :{$min}";
        }

        return null;
    }

    private function dailyFrequency(string $min, string $hour, string $day, string $month, string $dow): ?string
    {
        if (! $this->isDailyCron($min, $hour, $day, $month, $dow)) {
            return null;
        }

        return sprintf('Daily at %02d:%02d', (int) $hour, (int) $min);
    }

    private function isDailyCron(string $min, string $hour, string $day, string $month, string $dow): bool
    {
        return is_numeric($min) && is_numeric($hour) && $day === '*' && $month === '*' && $dow === '*';
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

        return $this->commandDetailsResponse($commandName);
    }

    private function commandDetailsResponse(string $commandName): JsonResponse
    {
        try {
            $result = $this->runOnIngestion(['php', 'artisan', $commandName, '--help', '--format=json', '--no-ansi']);
        } catch (\Throwable $e) {
            return response()->json(['error' => $e->getMessage()], 422);
        }

        if (! $result->successful()) {
            return response()->json(['error' => 'Command not found.'], 404);
        }

        $decoded = json_decode($result->output(), true);

        if (! is_array($decoded) || $decoded === []) {
            return response()->json(['error' => 'Failed to parse command details.'], 500);
        }

        /** @var CommandDetails $data */
        $data = $decoded;

        return response()->json($this->commandDetailsPayload($commandName, $data));
    }

    /**
     * @param  CommandDetails  $data
     * @return array<string, array<int, array<string, array<int, bool|float|int|string|null>|bool|float|int|string|null>>|bool|float|int|string|null>
     */
    private function commandDetailsPayload(string $commandName, array $data): array
    {
        $definition = is_array($data['definition'] ?? null) ? $data['definition'] : [];

        return [
            'name' => $commandName,
            'description' => $data['description'] ?? '',
            'help' => $data['help'] ?? '',
            'arguments' => $this->commandArguments($definition['arguments'] ?? []),
            'options' => $this->commandOptions($definition['options'] ?? []),
        ];
    }

    /**
     * @param  CommandDefinitions  $definitions
     * @return array<int, array<string, array<int, bool|float|int|string|null>|bool|float|int|string|null>>
     */
    private function commandArguments(array $definitions): array
    {
        $arguments = [];
        foreach ($definitions as $name => $arg) {
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

        return $arguments;
    }

    /**
     * @param  CommandDefinitions  $definitions
     * @return array<int, array<string, array<int, bool|float|int|string|null>|bool|float|int|string|null>>
     */
    private function commandOptions(array $definitions): array
    {
        $options = [];
        foreach ($definitions as $name => $opt) {
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

        return $options;
    }

    public function execute(Request $request): JsonResponse
    {
        $request->validate([
            'command' => ['required', 'string'],
            'arguments' => ['sometimes', 'array'],
            'options' => ['sometimes', 'array'],
        ]);

        $commandName = $request->input('command');
        $arguments = (array) $request->input('arguments', []);
        $options = (array) $request->input('options', []);

        if (! str_starts_with($commandName, 'kraite:')) {
            return response()->json(['error' => 'Only kraite commands can be executed.'], 422);
        }

        return $this->runCommand($this->buildExecutionCommand($commandName, $arguments, $options));
    }

    /**
     * @param  array<int, string|int|float|bool|null>  $arguments
     * @param  array<string, string|int|float|bool|null>  $options
     * @return array<int, string>
     */
    private function buildExecutionCommand(string $commandName, array $arguments, array $options): array
    {
        $command = ['php', 'artisan', $commandName, '--no-interaction', '--no-ansi'];
        foreach ($arguments as $value) {
            if ($value !== null && $value !== '') {
                $command[] = (string) $value;
            }
        }

        return array_merge($command, $this->buildOptionArguments($options));
    }

    /**
     * @param  array<string, string|int|float|bool|null>  $options
     * @return array<int, string>
     */
    private function buildOptionArguments(array $options): array
    {
        $arguments = [];
        foreach ($options as $key => $value) {
            $argument = $this->formatOptionArgument((string) $key, $value);
            if ($argument !== null) {
                $arguments[] = $argument;
            }
        }

        return $arguments;
    }

    private function formatOptionArgument(string $key, string|int|float|bool|null $value): ?string
    {
        if ($value === true) {
            return '--'.$key;
        }
        if ($value === null || $value === '' || $value === false) {
            return null;
        }

        return '--'.$key.'='.$value;
    }

    /** @param array<int, string> $command */
    private function runCommand(array $command): JsonResponse
    {
        try {
            $startTime = microtime(true);
            $result = $this->runOnIngestion($command, 300);
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
        $result = $this->tryRunOnIngestion(['php', 'artisan', 'list', '--format=json', '--no-ansi']);

        if ($result === null) {
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
