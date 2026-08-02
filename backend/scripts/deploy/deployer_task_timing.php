#!/usr/bin/env php
<?php

declare(strict_types=1);

const DEPLOY_TIMING_SCHEMA = 'fermatmind.deployer-task-timing.v1';
const DEPLOY_PROGRESS_SCHEMA = 'fermatmind.deployer-progress.v1';

function failUsage(string $message): never
{
    fwrite(STDERR, "deployer timing: {$message}\n");
    exit(64);
}

/**
 * @return array{options: array<string, string>, command: list<string>}
 */
function parseArguments(array $arguments): array
{
    $options = [];
    $command = [];
    $afterSeparator = false;

    foreach (array_slice($arguments, 2) as $argument) {
        if ($argument === '--' && ! $afterSeparator) {
            $afterSeparator = true;

            continue;
        }

        if ($afterSeparator) {
            $command[] = $argument;

            continue;
        }

        if (! str_starts_with($argument, '--') || ! str_contains($argument, '=')) {
            failUsage("expected --name=value option, received {$argument}");
        }

        [$name, $value] = explode('=', substr($argument, 2), 2);
        $options[$name] = $value;
    }

    return ['options' => $options, 'command' => $command];
}

function requireOption(array $options, string $name): string
{
    $value = trim((string) ($options[$name] ?? ''));
    if ($value === '') {
        failUsage("missing --{$name}");
    }

    return $value;
}

function validateContext(array $options): array
{
    $environment = requireOption($options, 'environment');
    $sha = requireOption($options, 'sha');
    $runId = requireOption($options, 'run-id');
    $runAttempt = requireOption($options, 'run-attempt');
    $receipt = requireOption($options, 'receipt');

    if (! in_array($environment, ['staging', 'production'], true)) {
        failUsage('environment must be staging or production');
    }
    if (! preg_match('/\A[0-9a-f]{40}\z/', $sha)) {
        failUsage('sha must be an exact lowercase 40-character commit');
    }
    if (! preg_match('/\A[1-9][0-9]*\z/', $runId) || ! preg_match('/\A[1-9][0-9]*\z/', $runAttempt)) {
        failUsage('run-id and run-attempt must be positive integers');
    }

    return [
        'environment' => $environment,
        'sha' => $sha,
        'workflow_run_id' => $runId,
        'workflow_run_attempt' => $runAttempt,
        'receipt' => $receipt,
    ];
}

function isoTime(float $timestamp): string
{
    $seconds = (int) floor($timestamp);
    $milliseconds = (int) floor(($timestamp - $seconds) * 1000);

    return gmdate('Y-m-d\TH:i:s', $seconds).sprintf('.%03dZ', $milliseconds);
}

function taskRecord(array $context, string $task, string $result, ?float $started, ?float $finished, ?int $exitCode): array
{
    return [
        'task' => $task,
        'environment' => $context['environment'],
        'sha' => $context['sha'],
        'workflow_run_id' => $context['workflow_run_id'],
        'workflow_run_attempt' => $context['workflow_run_attempt'],
        'started_at' => $started === null ? null : isoTime($started),
        'finished_at' => $finished === null ? null : isoTime($finished),
        'duration_ms' => $started === null || $finished === null
            ? null
            : max(0, (int) round(($finished - $started) * 1000)),
        'result' => $result,
        'exit_code' => $exitCode,
    ];
}

function writeReceipt(array $context, array $tasks, string $planStatus): void
{
    $directory = dirname($context['receipt']);
    if (! is_dir($directory) && ! mkdir($directory, 0775, true) && ! is_dir($directory)) {
        throw new RuntimeException('unable to create receipt directory');
    }

    $payload = [
        'schema_version' => DEPLOY_TIMING_SCHEMA,
        'environment' => $context['environment'],
        'sha' => $context['sha'],
        'workflow_run_id' => $context['workflow_run_id'],
        'workflow_run_attempt' => $context['workflow_run_attempt'],
        'generated_at' => isoTime(microtime(true)),
        'history_policy' => [
            'source' => 'GitHub Actions artifacts with the same environment-specific artifact name',
            'window' => 20,
            'minimum_samples' => 3,
        ],
        'plan_status' => $planStatus,
        'task_count' => count($tasks),
        'tasks' => array_values($tasks),
    ];

    $temporary = $context['receipt'].'.tmp';
    file_put_contents(
        $temporary,
        json_encode($payload, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR)."\n",
        LOCK_EX,
    );
    if (! rename($temporary, $context['receipt'])) {
        throw new RuntimeException('unable to publish timing receipt');
    }
}

function writeProgressReceipt(array $context, string $path, string $status, ?string $activeTask, float $started): void
{
    $directory = dirname($path);
    if (! is_dir($directory) && ! mkdir($directory, 0775, true) && ! is_dir($directory)) {
        throw new RuntimeException('unable to create progress receipt directory');
    }

    $payload = [
        'schema_version' => DEPLOY_PROGRESS_SCHEMA,
        'environment' => $context['environment'],
        'sha' => $context['sha'],
        'workflow_run_id' => $context['workflow_run_id'],
        'workflow_run_attempt' => $context['workflow_run_attempt'],
        'status' => $status,
        'active_task' => $activeTask,
        'started_at' => isoTime($started),
        'updated_at' => isoTime(microtime(true)),
        'elapsed_seconds' => max(0, (int) floor(microtime(true) - $started)),
    ];

    $temporary = $path.'.tmp';
    file_put_contents(
        $temporary,
        json_encode($payload, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR)."\n",
        LOCK_EX,
    );
    if (! rename($temporary, $path)) {
        throw new RuntimeException('unable to publish progress receipt');
    }
}

/**
 * @return list<string>
 */
function parseTaskTree(string $output): array
{
    $nodes = [];
    foreach (preg_split('/\R/', $output) ?: [] as $line) {
        if (! preg_match('/\A((?:(?:│   )|(?:    ))*)(?:├── |└── )([A-Za-z0-9_.:-]+)/u', $line, $match)) {
            continue;
        }

        preg_match_all('/(?:│   |    )/u', $match[1], $indent);
        $nodes[] = ['depth' => count($indent[0]), 'task' => $match[2]];
    }

    $tasks = [];
    foreach ($nodes as $index => $node) {
        $next = $nodes[$index + 1] ?? null;
        if ($next !== null && $next['depth'] > $node['depth']) {
            continue;
        }
        if (! in_array($node['task'], $tasks, true)) {
            $tasks[] = $node['task'];
        }
    }

    return $tasks;
}

/**
 * @return array{tasks: list<string>, status: string}
 */
function plannedTasks(array $options): array
{
    $deployer = requireOption($options, 'deployer-bin');
    $recipe = requireOption($options, 'recipe');
    $task = requireOption($options, 'task');
    $command = [PHP_BINARY, $deployer, 'tree', $task, '-f', $recipe, '--no-ansi', '--no-interaction'];
    $pipes = [];
    $process = proc_open($command, [0 => ['file', '/dev/null', 'r'], 1 => ['pipe', 'w'], 2 => ['pipe', 'w']], $pipes);
    if (! is_resource($process)) {
        return ['tasks' => [], 'status' => 'unavailable'];
    }

    $stdout = stream_get_contents($pipes[1]);
    stream_get_contents($pipes[2]);
    fclose($pipes[1]);
    fclose($pipes[2]);
    $exitCode = proc_close($process);
    $tasks = $exitCode === 0 ? parseTaskTree((string) $stdout) : [];

    if ($exitCode !== 0 || $tasks === []) {
        fwrite(STDERR, "deployer timing: task plan unavailable; observed tasks will still be recorded\n");

        return ['tasks' => [], 'status' => 'unavailable'];
    }

    return ['tasks' => $tasks, 'status' => 'complete'];
}

final class TimingObserver
{
    private array $records = [];

    private ?array $active = null;

    private ?array $pending = null;

    private ?int $availableExitCode = null;

    public function __construct(private readonly array $context) {}

    public function observe(string $line): void
    {
        $plain = preg_replace('/\e\[[0-9;]*[A-Za-z]/', '', $line) ?? $line;

        if (preg_match('/::group::task ([A-Za-z0-9_.:-]+)/', $plain, $match)) {
            $this->finalizePendingSuccess();
            $this->active = ['task' => $match[1], 'started' => microtime(true)];
            $this->availableExitCode = null;

            return;
        }

        if (preg_match('/exit code\s+([0-9]+)/i', $plain, $match)) {
            $this->availableExitCode = (int) $match[1];
        }

        if (str_contains($plain, '::endgroup::') && $this->active !== null) {
            $this->pending = [
                'task' => $this->active['task'],
                'started' => $this->active['started'],
                'finished' => microtime(true),
            ];
            $this->active = null;

            return;
        }

        if (preg_match('/ERROR: Task ([A-Za-z0-9_.:-]+) failed!/', $plain, $match)) {
            $now = microtime(true);
            $candidate = $this->pending;
            if ($candidate === null || $candidate['task'] !== $match[1]) {
                $candidate = $this->active ?? ['task' => $match[1], 'started' => $now];
            }
            $this->records[$match[1]] = taskRecord(
                $this->context,
                $match[1],
                'failure',
                $candidate['started'],
                $candidate['finished'] ?? $now,
                $this->availableExitCode,
            );
            $this->pending = null;
            $this->active = null;
        }
    }

    public function finish(int $processExitCode, array $planned): array
    {
        if ($processExitCode !== 0) {
            $candidate = $this->active ?? $this->pending;
            if ($candidate !== null && ! isset($this->records[$candidate['task']])) {
                $this->records[$candidate['task']] = taskRecord(
                    $this->context,
                    $candidate['task'],
                    'failure',
                    $candidate['started'],
                    $candidate['finished'] ?? microtime(true),
                    $this->availableExitCode ?? $processExitCode,
                );
                $this->active = null;
                $this->pending = null;
            }
        }

        $this->finalizePendingSuccess();
        if ($this->active !== null) {
            $this->records[$this->active['task']] = taskRecord(
                $this->context,
                $this->active['task'],
                $processExitCode === 0 ? 'success' : 'failure',
                $this->active['started'],
                microtime(true),
                $processExitCode,
            );
            $this->active = null;
        }

        if ($processExitCode !== 0) {
            foreach ($this->records as $task => $record) {
                if ($record['result'] === 'failure' && $record['exit_code'] === null) {
                    $this->records[$task]['exit_code'] = $processExitCode;
                }
            }
        }

        $ordered = [];
        foreach ($planned as $task) {
            $ordered[] = $this->records[$task]
                ?? taskRecord($this->context, $task, 'skipped', null, null, null);
        }
        foreach ($this->records as $task => $record) {
            if (! in_array($task, $planned, true)) {
                $ordered[] = $record;
            }
        }

        return $ordered;
    }

    public function activeTask(): ?string
    {
        return $this->active['task'] ?? $this->pending['task'] ?? null;
    }

    private function finalizePendingSuccess(): void
    {
        if ($this->pending === null) {
            return;
        }

        $this->records[$this->pending['task']] = taskRecord(
            $this->context,
            $this->pending['task'],
            'success',
            $this->pending['started'],
            $this->pending['finished'],
            0,
        );
        $this->pending = null;
    }
}

function runTimedCommand(array $context, array $options, array $command): never
{
    if ($command === []) {
        failUsage('run requires a command after --');
    }

    $heartbeatInterval = (int) ($options['heartbeat-interval-seconds'] ?? 0);
    if ($heartbeatInterval < 0 || $heartbeatInterval > 300) {
        failUsage('heartbeat-interval-seconds must be between 0 and 300');
    }
    $progressReceipt = trim((string) ($options['progress-receipt'] ?? ''));
    $started = microtime(true);
    $lastHeartbeat = $started;
    $plan = plannedTasks($options);
    $observer = new TimingObserver($context);
    $writeProgress = static function (string $status) use ($context, $progressReceipt, $observer, $started): void {
        if ($progressReceipt === '') {
            return;
        }
        try {
            writeProgressReceipt($context, $progressReceipt, $status, $observer->activeTask(), $started);
        } catch (Throwable) {
            fwrite(STDERR, "deployer timing: progress receipt write failed\n");
        }
    };
    $writeProgress('running');
    $pipes = [];
    $process = proc_open($command, [0 => STDIN, 1 => ['pipe', 'w'], 2 => ['pipe', 'w']], $pipes);
    if (! is_resource($process)) {
        $task = requireOption($options, 'task');
        writeReceipt($context, [taskRecord($context, $task, 'failure', null, null, 127)], $plan['status']);
        exit(127);
    }

    foreach ([1, 2] as $descriptor) {
        stream_set_blocking($pipes[$descriptor], false);
    }
    $buffers = [1 => '', 2 => ''];

    while (true) {
        $status = proc_get_status($process);
        $read = [];
        foreach ([1, 2] as $descriptor) {
            if (! feof($pipes[$descriptor])) {
                $read[] = $pipes[$descriptor];
            }
        }
        if ($read !== []) {
            $write = null;
            $except = null;
            @stream_select($read, $write, $except, 0, 200000);
            foreach ($read as $stream) {
                $descriptor = $stream === $pipes[1] ? 1 : 2;
                $chunk = stream_get_contents($stream);
                if ($chunk === false || $chunk === '') {
                    continue;
                }
                fwrite($descriptor === 1 ? STDOUT : STDERR, $chunk);
                $buffers[$descriptor] .= $chunk;
                while (($position = strpos($buffers[$descriptor], "\n")) !== false) {
                    $line = substr($buffers[$descriptor], 0, $position + 1);
                    $buffers[$descriptor] = substr($buffers[$descriptor], $position + 1);
                    $observer->observe($line);
                }
            }
        }
        if ($heartbeatInterval > 0 && microtime(true) - $lastHeartbeat >= $heartbeatInterval) {
            $activeTask = $observer->activeTask() ?? 'unknown';
            $elapsedSeconds = max(0, (int) floor(microtime(true) - $started));
            fwrite(STDOUT, "::notice title=Deployer heartbeat::elapsed_seconds={$elapsedSeconds} active_task={$activeTask}\n");
            $writeProgress('running');
            $lastHeartbeat = microtime(true);
        }
        if (! $status['running'] && feof($pipes[1]) && feof($pipes[2])) {
            $processExitCode = $status['exitcode'];
            break;
        }
    }

    foreach ([1, 2] as $descriptor) {
        if ($buffers[$descriptor] !== '') {
            $observer->observe($buffers[$descriptor]);
        }
        fclose($pipes[$descriptor]);
    }
    $closedExitCode = proc_close($process);
    if ($processExitCode < 0) {
        $processExitCode = $closedExitCode;
    }
    if ($processExitCode < 0) {
        $processExitCode = 1;
    }

    $tasks = $observer->finish($processExitCode, $plan['tasks']);
    $writeProgress($processExitCode === 0 ? 'completed' : 'failed');
    try {
        writeReceipt($context, $tasks, $plan['status']);
    } catch (Throwable $exception) {
        fwrite(STDERR, "deployer timing: receipt write failed without changing Deployer exit {$processExitCode}\n");
    }
    exit($processExitCode);
}

function percentile(array $values, float $percentile): int
{
    sort($values, SORT_NUMERIC);
    $index = max(0, (int) ceil($percentile * count($values)) - 1);

    return (int) $values[$index];
}

function renderSummary(array $options): void
{
    $currentPath = requireOption($options, 'current');
    $historyDirectory = requireOption($options, 'history-dir');
    $outputPath = requireOption($options, 'output');
    $window = (int) ($options['window'] ?? 20);
    $minimumSamples = (int) ($options['minimum-samples'] ?? 3);
    if ($window < 1 || $window > 100 || $minimumSamples < 1 || $minimumSamples > $window) {
        failUsage('invalid summary window or minimum-samples');
    }

    $current = json_decode((string) file_get_contents($currentPath), true, 512, JSON_THROW_ON_ERROR);
    if (($current['schema_version'] ?? '') !== DEPLOY_TIMING_SCHEMA) {
        throw new RuntimeException('current receipt schema mismatch');
    }

    $history = [];
    if (is_dir($historyDirectory)) {
        $iterator = new RecursiveIteratorIterator(new RecursiveDirectoryIterator($historyDirectory));
        foreach ($iterator as $file) {
            if (! $file->isFile() || strtolower($file->getExtension()) !== 'json') {
                continue;
            }
            try {
                $candidate = json_decode((string) file_get_contents($file->getPathname()), true, 512, JSON_THROW_ON_ERROR);
            } catch (Throwable) {
                continue;
            }
            if (
                ($candidate['schema_version'] ?? '') !== DEPLOY_TIMING_SCHEMA
                || ($candidate['environment'] ?? '') !== ($current['environment'] ?? '')
                || (
                    (string) ($candidate['workflow_run_id'] ?? '') === (string) ($current['workflow_run_id'] ?? '')
                    && (string) ($candidate['workflow_run_attempt'] ?? '') === (string) ($current['workflow_run_attempt'] ?? '')
                )
            ) {
                continue;
            }
            $history[] = $candidate;
        }
    }
    usort($history, fn (array $left, array $right): int => strcmp((string) ($right['generated_at'] ?? ''), (string) ($left['generated_at'] ?? '')));
    $history = array_slice($history, 0, $window);

    $lines = [
        '## Deployer task timing',
        '',
        sprintf(
            'History source: prior `%s` GitHub Actions artifacts for `%s`; fixed window: latest %d receipts; percentile minimum: %d completed samples.',
            'deployer-task-timing-'.$current['environment'],
            $current['environment'],
            $window,
            $minimumSamples,
        ),
        '',
        '| Task | Current duration | Result | Historical samples | P50 | P95 |',
        '|---|---:|---|---:|---:|---:|',
    ];

    foreach ($current['tasks'] as $task) {
        $values = [];
        foreach ($history as $receipt) {
            foreach ($receipt['tasks'] ?? [] as $historicalTask) {
                if (
                    ($historicalTask['task'] ?? '') === ($task['task'] ?? '')
                    && in_array(($historicalTask['result'] ?? ''), ['success', 'failure'], true)
                    && is_int($historicalTask['duration_ms'] ?? null)
                ) {
                    $values[] = $historicalTask['duration_ms'];
                    break;
                }
            }
        }
        $sampleCount = count($values);
        $p50 = $sampleCount >= $minimumSamples ? percentile($values, 0.50).' ms' : 'N/A';
        $p95 = $sampleCount >= $minimumSamples ? percentile($values, 0.95).' ms' : 'N/A';
        $duration = is_int($task['duration_ms'] ?? null) ? $task['duration_ms'].' ms' : 'N/A';
        $taskName = str_replace('|', '\\|', (string) ($task['task'] ?? ''));
        $lines[] = sprintf(
            '| `%s` | %s | %s | %d | %s | %s |',
            $taskName,
            $duration,
            (string) ($task['result'] ?? ''),
            $sampleCount,
            $p50,
            $p95,
        );
    }

    file_put_contents($outputPath, implode("\n", $lines)."\n", LOCK_EX);
}

$commandName = $argv[1] ?? '';
$parsed = parseArguments($argv);

try {
    if ($commandName === 'run') {
        runTimedCommand(validateContext($parsed['options']), $parsed['options'], $parsed['command']);
    }
    if ($commandName === 'skip') {
        $context = validateContext($parsed['options']);
        $task = requireOption($parsed['options'], 'task');
        writeReceipt($context, [taskRecord($context, $task, 'skipped', null, null, null)], 'not_requested');
        exit(0);
    }
    if ($commandName === 'summary') {
        renderSummary($parsed['options']);
        exit(0);
    }
    failUsage('expected command: run, skip, or summary');
} catch (Throwable $exception) {
    fwrite(STDERR, "deployer timing: {$exception->getMessage()}\n");
    exit(70);
}
