<?php

declare(strict_types=1);

namespace App\Console\Commands\Ops;

use App\Services\Ops\AttemptSubmissionRecoveryService;
use Illuminate\Console\Command;

final class AttemptSubmissionRecovery extends Command
{
    protected $signature = 'ops:attempt-submission-recovery
        {--attempt-id= : Inspect one attempt id exactly}
        {--window-hours= : Recent lookback window in hours}
        {--limit= : Max recent attempts to inspect}
        {--pending-timeout-minutes= : Threshold for pending/running submissions}
        {--repair=0 : Apply safe compensating actions}
        {--alert= : Emit ops alert when findings exist}
        {--strict= : Exit non-zero when findings exist}
        {--json=1 : Output JSON payload}';

    protected $description = 'Scan attempt submission inconsistencies and apply safe compensating actions.';

    public function __construct(private readonly AttemptSubmissionRecoveryService $service)
    {
        parent::__construct();
    }

    public function handle(): int
    {
        $policy = $this->policy();
        $attemptId = trim((string) ($this->option('attempt-id') ?? ''));
        $windowHours = max(1, (int) ($this->option('window-hours') ?: ($policy['window_hours'] ?? 24)));
        $limit = max(1, (int) ($this->option('limit') ?: ($policy['limit'] ?? 200)));
        $pendingTimeoutMinutes = max(1, (int) ($this->option('pending-timeout-minutes') ?: ($policy['pending_timeout_minutes'] ?? 15)));
        $repair = $this->isTruthy($this->option('repair'));
        $strict = $this->option('strict') === null
            ? (bool) ($policy['strict_default'] ?? false)
            : $this->isTruthy($this->option('strict'));
        $alert = $this->option('alert') === null
            ? (bool) ($policy['alert_default'] ?? true)
            : $this->isTruthy($this->option('alert'));

        $payload = $this->service->recover(
            $attemptId !== '' ? $attemptId : null,
            $windowHours,
            $limit,
            $pendingTimeoutMinutes,
            $repair
        );
        $payload['policy'] = [
            'strict' => $strict,
            'alert' => $alert,
        ];

        if ($alert) {
            $this->service->emitAlert($payload);
        }

        if ($this->isTruthy($this->option('json'))) {
            $this->line((string) json_encode($payload, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES));
        } else {
            $this->info('attempt_submission_recovery');
            $this->line(sprintf(
                'attempt=%s findings=%d repairs=%d repair_mode=%s',
                (string) data_get($payload, 'scope.attempt_id', 'recent'),
                (int) data_get($payload, 'summary.finding_total', 0),
                (int) data_get($payload, 'summary.repair_total', 0),
                $repair ? '1' : '0'
            ));
        }

        if ($strict && (int) data_get($payload, 'summary.finding_total', 0) > 0) {
            return self::FAILURE;
        }

        return self::SUCCESS;
    }

    /**
     * @return array<string,mixed>
     */
    private function policy(): array
    {
        $policy = config('ops.attempt_submission_recovery');

        return is_array($policy) ? $policy : [];
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
