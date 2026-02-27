<?php

declare(strict_types=1);

namespace App\Console\Commands\Ops;

use App\Services\Ops\QueueBacklogProbeService;
use Illuminate\Console\Command;

final class QueueBacklogProbe extends Command
{
    protected $signature = 'ops:queue-backlog-probe
        {--queues=attempts,reports,commerce : Comma-separated queue names}
        {--window-minutes=60 : Failure telemetry lookback window in minutes}
        {--max-pending=200 : Warn threshold for pending jobs per queue}
        {--max-failed=20 : Warn threshold for failed jobs per queue}
        {--max-oldest-seconds=300 : Warn threshold for oldest pending job age}
        {--max-timeout-failures=3 : Warn threshold for timeout-like failed jobs in window}
        {--strict=0 : Exit non-zero when any queue exceeds threshold}
        {--json=1 : Output JSON payload}';

    protected $description = 'Queue backlog probe for attempts/reports/commerce capacity governance.';

    public function __construct(private readonly QueueBacklogProbeService $probeService)
    {
        parent::__construct();
    }

    public function handle(): int
    {
        $queues = $this->parseQueues((string) $this->option('queues'));
        $windowMinutes = max(1, (int) $this->option('window-minutes'));

        $thresholds = [
            'max_pending' => max(0, (int) $this->option('max-pending')),
            'max_failed' => max(0, (int) $this->option('max-failed')),
            'max_oldest_seconds' => max(0, (int) $this->option('max-oldest-seconds')),
            'max_timeout_failures' => max(0, (int) $this->option('max-timeout-failures')),
        ];

        $payload = $this->probeService->probe($queues, $windowMinutes);
        $assessment = $this->assessQueues(
            is_array($payload['queues'] ?? null) ? $payload['queues'] : [],
            $thresholds
        );

        $payload['thresholds'] = $thresholds;
        $payload['queues'] = $assessment['queues'];
        $payload['violations'] = $assessment['violations'];
        $payload['pass'] = $assessment['pass'];

        if ($this->isTruthy($this->option('json'))) {
            $this->line((string) json_encode($payload, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES));
        } else {
            $this->info('queue_backlog_probe');
            $this->line(sprintf(
                'driver=%s connection=%s window_minutes=%d',
                (string) ($payload['queue_driver'] ?? ''),
                (string) ($payload['queue_connection'] ?? ''),
                (int) ($payload['window_minutes'] ?? 0)
            ));
            foreach ($assessment['queues'] as $queue) {
                $backlog = is_array($queue['backlog'] ?? null) ? $queue['backlog'] : [];
                $failures = is_array($queue['failures'] ?? null) ? $queue['failures'] : [];
                $this->line(sprintf(
                    '%s status=%s pending=%d reserved=%d delayed=%d oldest_pending_s=%d failed=%d timeout_failed_window=%d',
                    (string) ($queue['queue'] ?? ''),
                    (string) ($queue['status'] ?? 'ok'),
                    (int) ($backlog['pending'] ?? 0),
                    (int) ($backlog['reserved'] ?? 0),
                    (int) ($backlog['delayed'] ?? 0),
                    (int) ($backlog['oldest_pending_seconds'] ?? 0),
                    (int) ($failures['total'] ?? 0),
                    (int) ($failures['window_timeout_total'] ?? 0)
                ));
            }
            if ($assessment['violations'] !== []) {
                $this->warn('violations='.json_encode($assessment['violations'], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES));
            }
        }

        if ($this->isTruthy($this->option('strict')) && ! ($assessment['pass'] ?? true)) {
            return self::FAILURE;
        }

        return self::SUCCESS;
    }

    /**
     * @param  list<array<string,mixed>>  $queues
     * @param  array{max_pending:int,max_failed:int,max_oldest_seconds:int,max_timeout_failures:int}  $thresholds
     * @return array{
     *     pass:bool,
     *     queues:list<array<string,mixed>>,
     *     violations:list<array{queue:string,metric:string,value:int,threshold:int}>
     * }
     */
    private function assessQueues(array $queues, array $thresholds): array
    {
        $violations = [];
        $assessed = [];

        foreach ($queues as $queue) {
            $backlog = is_array($queue['backlog'] ?? null) ? $queue['backlog'] : [];
            $failures = is_array($queue['failures'] ?? null) ? $queue['failures'] : [];
            $queueName = (string) ($queue['queue'] ?? '');
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
            if ($queueViolations !== []) {
                $queue['status'] = 'warn';
            } else {
                $queue['status'] = $existingStatus;
            }
            $queue['violations'] = $queueViolations;

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

    private function isTruthy(mixed $value): bool
    {
        if (is_bool($value)) {
            return $value;
        }

        $normalized = strtolower(trim((string) $value));

        return in_array($normalized, ['1', 'true', 'yes', 'on'], true);
    }
}
