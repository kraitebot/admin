<?php

declare(strict_types=1);

const QUALITY_PATHS = [
    'app',
    'bootstrap/app.php',
    'bootstrap/providers.php',
    'config',
    'database',
    'phpinsights.php',
    'public/index.php',
    'routes',
    'scripts',
    'tests',
];

/**
 * @param  list<string>  $arguments
 * @param  array<string, string>|null  $environment
 * @return array{int, string, string}
 */
function runQualityTool(array $arguments, ?array $environment = null): array
{
    $process = proc_open($arguments, [1 => ['pipe', 'w'], 2 => ['pipe', 'w']], $pipes, dirname(__DIR__), $environment);

    if (! is_resource($process)) {
        throw new RuntimeException('Could not start '.implode(' ', $arguments));
    }

    $output = stream_get_contents($pipes[1]);
    $errors = stream_get_contents($pipes[2]);
    fclose($pipes[1]);
    fclose($pipes[2]);

    if ($output === false || $errors === false) {
        proc_close($process);

        throw new RuntimeException('Could not read quality tool output.');
    }

    return [proc_close($process), $output, $errors];
}

function removeQualityCache(string $directory): void
{
    if (! is_dir($directory)) {
        return;
    }

    $entries = new RecursiveIteratorIterator(
        new RecursiveDirectoryIterator($directory, FilesystemIterator::SKIP_DOTS),
        RecursiveIteratorIterator::CHILD_FIRST,
    );
    foreach ($entries as $entry) {
        $entry->isDir() ? rmdir($entry->getPathname()) : unlink($entry->getPathname());
    }
    rmdir($directory);
}

function readQualityJson(string $path): array
{
    $contents = file_get_contents(dirname(__DIR__).'/'.$path);

    if ($contents === false) {
        throw new RuntimeException("Could not read quality baseline: {$path}");
    }

    $decoded = json_decode($contents, true, 512, JSON_THROW_ON_ERROR);

    if (! is_array($decoded)) {
        throw new RuntimeException("Quality baseline is not an array: {$path}");
    }

    return $decoded;
}

function insightsIssueKey(string $category, string $file, string $insightClass, string $message): string
{
    $normalizedMessage = preg_replace(
        ['/@@ -\d+(?:,\d+)? \+\d+(?:,\d+)? @@/', '/on line \d+/'],
        ['@@', 'on line #'],
        $message,
    );

    return json_encode([
        $category,
        $file,
        $insightClass,
        $normalizedMessage ?? $message,
    ], JSON_THROW_ON_ERROR);
}

function phpmdViolations(array $report): array
{
    $violations = [];

    foreach ($report['files'] ?? [] as $file) {
        foreach ($file['violations'] as $violation) {
            $violations[] = [$file['relativePath'], $violation];
        }
    }

    return $violations;
}

$failed = false;
$root = dirname(__DIR__);

[$insightsExit, $insightsOutput, $insightsErrors] = runQualityTool([
    PHP_BINARY, '-d', 'memory_limit=2G', $root.'/vendor/bin/phpinsights', 'analyse',
    ...QUALITY_PATHS, '--composer=composer.json', '--config-path=phpinsights.php', '--format=json', '--no-interaction',
    '--min-quality=77.3', '--min-complexity=100', '--min-architecture=100', '--min-style=88.8',
]);

$insights = json_decode($insightsOutput, true);
if (! is_array($insights) || ! isset($insights['summary'])) {
    fwrite(STDERR, "PHP Insights could not produce a report: $insightsErrors\n");
    exit(1);
}

$scores = $insights['summary'];
echo sprintf(
    "PHP Insights: quality %s, complexity %s, architecture %s, style %s\n",
    $scores['code'],
    $scores['complexity'],
    $scores['architecture'],
    $scores['style'],
);

$baselineCounts = [];
foreach (readQualityJson('phpinsights-baseline.json') as $issue) {
    $key = insightsIssueKey($issue[0], $issue[1], $issue[3], $issue[4]);
    $baselineCounts[$key] = ($baselineCounts[$key] ?? 0) + 1;
}

$newInsights = [];
foreach (['Code', 'Complexity', 'Architecture', 'Style', 'Security'] as $category) {
    foreach ($insights[$category] ?? [] as $issue) {
        $key = insightsIssueKey(
            $category,
            $issue['file'] ?? '',
            $issue['insightClass'] ?? '',
            $issue['message'] ?? '',
        );
        if (($baselineCounts[$key] ?? 0) > 0) {
            $baselineCounts[$key]--;
        } else {
            $newInsights[] = [$category, $issue];
        }
    }
}

foreach ($newInsights as [$category, $issue]) {
    fwrite(STDERR, sprintf("PHP Insights %s:%s [%s] %s\n", $issue['file'] ?? '?', $issue['line'] ?? '?', $category, $issue['message'] ?? $issue['title']));
}

if ($insightsExit !== 0 || $newInsights !== []) {
    $failed = true;
}

$phpmdCache = sys_get_temp_dir().'/kraite-phpmd-'.bin2hex(random_bytes(8));
if (! mkdir($phpmdCache)) {
    throw new RuntimeException('Could not create an isolated PHPMD metrics cache.');
}
register_shutdown_function(static fn () => removeQualityCache($phpmdCache));
$phpmdEnvironment = array_merge(getenv(), ['XDG_CACHE_HOME' => $phpmdCache]);

[$phpmdExit, $phpmdOutput, $phpmdErrors] = runQualityTool([
    $root.'/vendor/bin/phpmd', 'analyze', ...QUALITY_PATHS,
    '--ruleset=phpmd.xml', '--format=json', '--no-progress', '--threads=4',
    '--baseline-file=phpmd.baseline.xml',
], $phpmdEnvironment);

$phpmd = json_decode($phpmdOutput, true);
if (! is_array($phpmd)) {
    fwrite(STDERR, "PHPMD could not produce a report: $phpmdErrors\n");
    exit(1);
}

foreach (phpmdViolations($phpmd) as [$file, $violation]) {
    fwrite(STDERR, sprintf("PHPMD %s:%d %s: %s\n", $file, $violation['beginLine'], $violation['method'] ?? $violation['function'] ?? '?', $violation['description']));
}

if ($phpmdExit !== 0) {
    $failed = true;
}

$temporaryBaseline = tempnam(sys_get_temp_dir(), 'kraite-phpmd-');
if ($temporaryBaseline === false) {
    throw new RuntimeException('Could not create a temporary PHPMD baseline.');
}

file_put_contents($temporaryBaseline, '<?xml version="1.0"?><phpmd-baseline><violation rule="__none__" file="__none__" method="__none__"/></phpmd-baseline>');

[$rawPhpmdExit, $rawPhpmdOutput, $rawPhpmdErrors] = runQualityTool([
    $root.'/vendor/bin/phpmd', 'analyze', ...QUALITY_PATHS,
    '--ruleset=phpmd.xml', '--format=json', '--no-progress', '--threads=4',
    '--baseline-file='.$temporaryBaseline,
], $phpmdEnvironment);
unlink($temporaryBaseline);

$rawPhpmd = json_decode($rawPhpmdOutput, true);
if (! is_array($rawPhpmd) || ! in_array($rawPhpmdExit, [0, 2], true)) {
    fwrite(STDERR, "PHPMD complexity measurement failed: $rawPhpmdErrors\n");
    exit(1);
}

$complexityBaseline = readQualityJson('phpmd-complexity-baseline.json');
foreach (phpmdViolations($rawPhpmd) as [$file, $violation]) {
    if (! preg_match('/Cyclomatic Complexity of (\d+)/', $violation['description'], $match)) {
        fwrite(STDERR, "PHPMD returned an unreadable complexity for $file\n");
        $failed = true;

        continue;
    }

    $identity = $file.'::'.($violation['class'] ?? '').'::'.($violation['method'] ?? $violation['function'] ?? '');
    if (isset($complexityBaseline[$identity]) && (int) $match[1] > $complexityBaseline[$identity]) {
        fwrite(STDERR, sprintf("PHPMD %s:%d %s complexity rose from %d to %d\n", $file, $violation['beginLine'], $violation['method'] ?? $violation['function'], $complexityBaseline[$identity], $match[1]));
        $failed = true;
    }
}

echo 'PHPMD: '.count(phpmdViolations($rawPhpmd))." existing methods above 10 checked\n";

[$phpstanExit, $phpstanOutput, $phpstanErrors] = runQualityTool([
    $root.'/vendor/bin/phpstan', 'analyse', '--configuration=phpstan.neon', '--no-progress', '--memory-limit=2G',
]);

echo 'PHPStan: '.($phpstanExit === 0 ? 'passed' : 'failed')."\n";
if ($phpstanExit !== 0) {
    fwrite(STDERR, $phpstanOutput.$phpstanErrors);
    $failed = true;
}

exit($failed ? 1 : 0);
