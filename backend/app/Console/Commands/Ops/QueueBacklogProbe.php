<?php

declare(strict_types=1);

namespace App\Console\Commands\Ops;

use App\Services\Ops\QueueBacklogProbeService;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\Log;

final class QueueBacklogProbe extends Command
{
    private const THRESHOLD_KEYS = [
        'max_pending',
        'max_failed',
        'max_oldest_seconds',
        'max_timeout_failures',
    ];

    protected $signature = 'ops:queue-backlog-probe
        {--queues= : Comma-separated queue names; defaults to ops.queue_backlog_probe.queues}
        {--window-minutes= : Failure telemetry lookback window in minutes}
        {--max-pending= : Override pending threshold for all queues}
        {--max-failed= : Override failed jobs threshold for all queues}
        {--max-oldest-seconds= : Override oldest pending job age threshold}
        {--max-timeout-failures= : Override timeout-like failed jobs threshold in window}
        {--strict= : Exit non-zero when any queue exceeds threshold}
        {--json=1 : Output JSON payload}';

    protected $description = 'Queue backlog probe for attempts/reports/commerce capacity governance.';

    public function __construct(private readonly QueueBacklogProbeService $probeService)
    {
        parent::__construct();
    }

    public function handle(): int
    {
        $policy = $this->queuePolicy();

        $queues = $this->parseQueues((string) ($this->option('queues') ?? ''));
        if ($queues === []) {
            $queues = $this->normalizeQueueList($policy['queues'] ?? []);
        }
        if ($queues === []) {
            $queues = ['attempts', 'reports', 'commerce'];
        }

        $windowMinutes = $this->resolveWindowMinutes($policy);
        $strict = $this->resolveStrict($policy);
        $thresholdsByQueue = $this->resolveThresholdsByQueue($queues, $policy);

        $payload = $this->probeService->probe($queues, $windowMinutes);
        $assessment = $this->assessQueues(
            is_array($payload['queues'] ?? null) ? $payload['queues'] : [],
            $thresholdsByQueue
        );

        $payload['thresholds'] = [
            'by_queue' => $thresholdsByQueue,
        ];
        $payload['slo'] = [
            'strict' => $strict,
            'alert_policy' => is_array($policy['alert_policy'] ?? null) ? $policy['alert_policy'] : [],
            'breach_total' => count($assessment['violations']),
            'breached_queues' => array_values(array_unique(array_map(
                static fn (array $violation): string => (string) ($violation['queue'] ?? ''),
                $assessment['violations']
            ))),
        ];
        $payload['queues'] = $assessment['queues'];
        $payload['violations'] = $assessment['violations'];
        $payload['pass'] = $assessment['pass'];
        $this->emitBreachAlertOutlet($payload);

        if ($this->isTruthy($this->option('json'))) {
            $this->line((string) json_encode($payload, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES));
        } else {
            $this->info('queue_backlog_probe');
            $this->line(sprintf(
                'driver=%s connection=%s window_minutes=%d strict=%s',
                (string) ($payload['queue_driver'] ?? ''),
                (string) ($payload['queue_connection'] ?? ''),
                (int) ($payload['window_minutes'] ?? 0),
                $strict ? '1' : '0'
            ));
            foreach ($assessment['queues'] as $queue) {
                $backlog = is_array($queue['backlog'] ?? null) ? $queue['backlog'] : [];
                $failures = is_array($queue['failures'] ?? null) ? $queue['failures'] : [];
                $slo = is_array($queue['slo'] ?? null) ? $queue['slo'] : [];
                $this->line(sprintf(
                    '%s status=%s pending=%d reserved=%d delayed=%d oldest_pending_s=%d failed=%d timeout_failed_window=%d max_utilization=%.2f',
                    (string) ($queue['queue'] ?? ''),
                    (string) ($queue['status'] ?? 'ok'),
                    (int) ($backlog['pending'] ?? 0),
                    (int) ($backlog['reserved'] ?? 0),
                    (int) ($backlog['delayed'] ?? 0),
                    (int) ($backlog['oldest_pending_seconds'] ?? 0),
                    (int) ($failures['total'] ?? 0),
                    (int) ($failures['window_timeout_total'] ?? 0),
                    (float) ($slo['max_utilization'] ?? 0.0)
                ));
            }
            if ($assessment['violations'] !== []) {
                $this->warn('violations='.json_encode($assessment['violations'], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES));
            }
        }

        if ($strict && ! ($assessment['pass'] ?? true)) {
            return self::FAILURE;
        }

        return self::SUCCESS;
    }

    /**
     * @param  array<string,mixed>  $payload
     */
    private function emitBreachAlertOutlet(array $payload): void
    {
        $violations = is_array($payload['violations'] ?? null) ? $payload['violations'] : [];
        if ($violations === []) {
            return;
        }

        $slo = is_array($payload['slo'] ?? null) ? $payload['slo'] : [];
        Log::warning('QUEUE_BACKLOG_SLO_BREACH', [
            'strict' => (bool) ($slo['strict'] ?? false),
            'pass' => (bool) ($payload['pass'] ?? false),
            'violations' => $violations,
            'queues' => is_array($payload['queues'] ?? null) ? $payload['queues'] : [],
            'thresholds' => is_array($payload['thresholds'] ?? null) ? $payload['thresholds'] : [],
            'window_minutes' => (int) ($payload['window_minutes'] ?? 0),
            'queue_driver' => (string) ($payload['queue_driver'] ?? ''),
            'queue_connection' => (string) ($payload['queue_connection'] ?? ''),
        ]);
    }

    /**
     * @param  list<array<string,mixed>>  $queues
     * @param  array<string,array{max_pending:int,max_failed:int,max_oldest_seconds:int,max_timeout_failures:int}>  $thresholdsByQueue
     * @return array{
     *     pass:bool,
     *     queues:list<array<string,mixed>>,
     *     violations:list<array{queue:string,metric:string,value:int,threshold:int}>
     * }
     */
    private function assessQueues(array $queues, array $thresholdsByQueue): array
    {
        $violations = [];
        $assessed = [];

        foreach ($queues as $queue) {
            $backlog = is_array($queue['backlog'] ?? null) ? $queue['backlog'] : [];
            $failures = is_array($queue['failures'] ?? null) ? $queue['failures'] : [];
            $queueName = (string) ($queue['queue'] ?? '');
            $thresholds = $this->thresholdForQueue($queueName, $thresholdsByQueue);
            $queueViolations = [];

            $pending = (int) ($backlog['pending'] ?? 0);
            if ($pending > $thresholds['max_pending']) {
                $queueViolations[] = [
                    'queue' => $queueName,
                    'metric' => 'pending',
                    'value' => $pending,
                    'threshold' => $thresholds['max_pending'],
                ];
            }

            $failed = (int) ($failures['total'] ?? 0);
            if ($failed > $thresholds['max_failed']) {
                $queueViolations[] = [
                    'queue' => $queueName,
                    'metric' => 'failed_total',
                    'value' => $failed,
                    'threshold' => $thresholds['max_failed'],
                ];
            }

            $oldestPending = (int) ($backlog['oldest_pending_seconds'] ?? 0);
            if ($oldestPending > $thresholds['max_oldest_seconds']) {
                $queueViolations[] = [
                    'queue' => $queueName,
                    'metric' => 'oldest_pending_seconds',
                    'value' => $oldestPending,
                    'threshold' => $thresholds['max_oldest_seconds'],
                ];
            }

            $timeoutFailures = (int) ($failures['window_timeout_total'] ?? 0);
            if ($timeoutFailures > $thresholds['max_timeout_failures']) {
                $queueViolations[] = [
                    'queue' => $queueName,
                    'metric' => 'window_timeout_failures',
                    'value' => $timeoutFailures,
                    'threshold' => $thresholds['max_timeout_failures'],
                ];
            }

            $existingStatus = strtolower(trim((string) ($queue['status'] ?? 'ok')));
            if ($existingStatus === '') {
                $existingStatus = 'ok';
            }
            $queue['status'] = $queueViolations !== [] ? 'warn' : $existingStatus;
            $queue['violations'] = $queueViolations;
            $queue['thresholds'] = $thresholds;
            $queue['slo'] = [
                'status' => $queueViolations !== [] ? 'breach' : 'ok',
                'pending_utilization' => $this->ratio($pending, $thresholds['max_pending']),
                'failed_utilization' => $this->ratio($failed, $thresholds['max_failed']),
                'oldest_pending_utilization' => $this->ratio($oldestPending, $thresholds['max_oldest_seconds']),
                'timeout_failure_utilization' => $this->ratio($timeoutFailures, $thresholds['max_timeout_failures']),
                'max_utilization' => max(
                    $this->ratio($pending, $thresholds['max_pending']),
                    $this->ratio($failed, $thresholds['max_failed']),
                    $this->ratio($oldestPending, $thresholds['max_oldest_seconds']),
                    $this->ratio($timeoutFailures, $thresholds['max_timeout_failures']),
                ),
                'breach_count' => count($queueViolations),
            ];

            $violations = array_merge($violations, $queueViolations);
            $assessed[] = $queue;
        }

        return [
            'pass' => $violations === [],
            'queues' => $assessed,
            'violations' => $violations,
        ];
    }

    /**
     * @return list<string>
     */
    private function parseQueues(string $raw): array
    {
        $parts = array_filter(array_map(
            static fn (string $item): string => strtolower(trim($item)),
            explode(',', $raw)
        ), static fn (string $item): bool => $item !== '');

        $dedup = [];
        foreach ($parts as $part) {
            $dedup[$part] = true;
        }

        return array_keys($dedup);
    }

    /**
     * @param  mixed  $value
     */
    private function isTruthy(mixed $value): bool
    {
        if (is_bool($value)) {
            return $value;
        }

        $normalized = strtolower(trim((string) $value));

        return in_array($normalized, ['1', 'true', 'yes', 'on'], true);
    }

    /**
     * @return array<string,mixed>
     */
    private function queuePolicy(): array
    {
        $policy = config('ops.queue_backlog_probe');

        return is_array($policy) ? $policy : [];
    }

    /**
     * @param  mixed  $rawQueues
     * @return list<string>
     */
    private function normalizeQueueList(mixed $rawQueues): array
    {
        if (! is_array($rawQueues)) {
            return [];
        }

        $normalized = [];
        foreach ($rawQueues as $queue) {
            $value = strtolower(trim((string) $queue));
            if ($value === '') {
                continue;
            }
            $normalized[$value] = true;
        }

        return array_keys($normalized);
    }

    /**
     * @param  array<string,mixed>  $policy
     */
    private function resolveWindowMinutes(array $policy): int
    {
        $option = $this->option('window-minutes');
        if ($option !== null && trim((string) $option) !== '') {
            return max(1, (int) $option);
        }

        return max(1, (int) ($policy['window_minutes'] ?? 60));
    }

    /**
     * @param  array<string,mixed>  $policy
     */
    private function resolveStrict(array $policy): bool
    {
        $option = $this->option('strict');
        if ($option !== null && trim((string) $option) !== '') {
            return $this->isTruthy($option);
        }

        return (bool) ($policy['strict_default'] ?? false);
    }

    /**
     * @param  list<string>  $queues
     * @param  array<string,mixed>  $policy
     * @return array<string,array{max_pending:int,max_failed:int,max_oldest_seconds:int,max_timeout_failures:int}>
     */
    private function resolveThresholdsByQueue(array $queues, array $policy): array
    {
        $rawThresholds = $policy['thresholds'] ?? [];
        $configured = is_array($rawThresholds) ? $rawThresholds : [];
        $resolved = [];

        foreach ($queues as $queue) {
            $queueConfig = is_array($configured[$queue] ?? null) ? $configured[$queue] : [];

            $thresholds = [
                'max_pending' => max(0, (int) ($queueConfig['max_pending'] ?? 200)),
                'max_failed' => max(0, (int) ($queueConfig['max_failed'] ?? 20)),
                'max_oldest_seconds' => max(0, (int) ($queueConfig['max_oldest_seconds'] ?? 300)),
                'max_timeout_failures' => max(0, (int) ($queueConfig['max_timeout_failures'] ?? 3)),
            ];

            foreach (self::THRESHOLD_KEYS as $key) {
                $optionName = str_replace('_', '-', $key);
                $override = $this->option($optionName);
                if ($override === null || trim((string) $override) === '') {
                    continue;
                }
                $thresholds[$key] = max(0, (int) $override);
            }

            $resolved[$queue] = $thresholds;
        }

        return $resolved;
    }

    /**
     * @param  array<string,array{max_pending:int,max_failed:int,max_oldest_seconds:int,max_timeout_failures:int}>  $thresholdsByQueue
     * @return array{max_pending:int,max_failed:int,max_oldest_seconds:int,max_timeout_failures:int}
     */
    private function thresholdForQueue(string $queueName, array $thresholdsByQueue): array
    {
        $thresholds = $thresholdsByQueue[$queueName] ?? [
            'max_pending' => 200,
            'max_failed' => 20,
            'max_oldest_seconds' => 300,
            'max_timeout_failures' => 3,
        ];

        return [
            'max_pending' => max(0, (int) ($thresholds['max_pending'] ?? 0)),
            'max_failed' => max(0, (int) ($thresholds['max_failed'] ?? 0)),
            'max_oldest_seconds' => max(0, (int) ($thresholds['max_oldest_seconds'] ?? 0)),
            'max_timeout_failures' => max(0, (int) ($thresholds['max_timeout_failures'] ?? 0)),
        ];
    }

    private function ratio(int $value, int $threshold): float
    {
        if ($threshold <= 0) {
            return $value > 0 ? 1.0 : 0.0;
        }

        return round($value / $threshold, 4);
    }
}
