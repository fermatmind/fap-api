<?php

declare(strict_types=1);

namespace App\Services\Ops;

use App\Services\SeoIntel\Decision\SeoWeeklyDecisionReceiptService;
use Carbon\CarbonImmutable;
use RuntimeException;

final class SchedulerHeartbeatService
{
    public const CONTRACT_VERSION = 'ops.scheduler_heartbeat.v2';

    public const RUNNER = 'cron_schedule_run';

    private const FUTURE_TOLERANCE_SECONDS = 5;

    public function __construct(private readonly ?string $path = null) {}

    public static function schedulerContractRevision(): string
    {
        return hash('sha256', implode('|', [
            'cron.v2',
            'cadence_seconds=60',
            'weekly_mode=foreground',
            SeoWeeklyDecisionReceiptService::capabilityRevision(),
        ]));
    }

    /** @return array<string, mixed> */
    public function record(string $event, ?int $exitCode = null, ?CarbonImmutable $now = null): array
    {
        if (! in_array($event, ['started', 'completed', 'overlap'], true)) {
            throw new RuntimeException('Unsupported scheduler heartbeat event.');
        }
        if ($event === 'completed' && $exitCode === null) {
            throw new RuntimeException('Completed scheduler heartbeat requires an exit code.');
        }
        if ($event !== 'completed' && $exitCode !== null) {
            throw new RuntimeException('Only a completed scheduler heartbeat accepts an exit code.');
        }

        $now ??= CarbonImmutable::now('UTC');
        $previous = $this->readRaw();
        $lastCompletedAt = is_array($previous) && is_string($previous['last_completed_at'] ?? null)
            ? $previous['last_completed_at']
            : null;
        $lastExitCode = is_array($previous) && is_int($previous['last_exit_code'] ?? null)
            ? $previous['last_exit_code']
            : null;
        $status = $event;
        if ($event === 'completed') {
            $lastCompletedAt = $now->format('Y-m-d\TH:i:s\Z');
            $lastExitCode = $exitCode;
            $status = ($previous['status'] ?? null) === 'overlap'
                ? 'overlap'
                : ($exitCode === 0 ? 'healthy' : 'failed');
        }

        $payload = [
            'schema_version' => self::CONTRACT_VERSION,
            'scheduler_contract_revision' => self::schedulerContractRevision(),
            'runner' => self::RUNNER,
            'observed_at' => $now->format('Y-m-d\TH:i:s\Z'),
            'last_completed_at' => $lastCompletedAt,
            'last_exit_code' => $lastExitCode,
            'status' => $status,
        ];
        $this->writeAtomic($payload);

        return $payload;
    }

    /** @return array<string, mixed> */
    public function check(int $maxAgeSeconds, ?CarbonImmutable $now = null): array
    {
        if ($maxAgeSeconds < 1 || $maxAgeSeconds > 3600) {
            throw new RuntimeException('Scheduler heartbeat max age is outside the supported range.');
        }

        $now ??= CarbonImmutable::now('UTC');
        $payload = $this->readRaw();
        if (! is_array($payload)) {
            return $this->failure('missing_or_malformed');
        }
        $expectedKeys = [
            'schema_version',
            'scheduler_contract_revision',
            'runner',
            'observed_at',
            'last_completed_at',
            'last_exit_code',
            'status',
        ];
        $actualKeys = array_keys($payload);
        sort($expectedKeys);
        sort($actualKeys);
        if ($actualKeys !== $expectedKeys
            || ($payload['schema_version'] ?? null) !== self::CONTRACT_VERSION
            || ($payload['scheduler_contract_revision'] ?? null) !== self::schedulerContractRevision()
            || ($payload['runner'] ?? null) !== self::RUNNER
            || ! is_string($payload['observed_at'] ?? null)
            || ! is_string($payload['last_completed_at'] ?? null)
            || ! is_int($payload['last_exit_code'] ?? null)
            || ! is_string($payload['status'] ?? null)) {
            return $this->failure('contract_mismatch');
        }

        try {
            $observedAt = CarbonImmutable::createFromFormat('!Y-m-d\TH:i:s\Z', $payload['observed_at'], 'UTC');
            $completedAt = CarbonImmutable::createFromFormat('!Y-m-d\TH:i:s\Z', $payload['last_completed_at'], 'UTC');
        } catch (\Throwable) {
            return $this->failure('timestamp_malformed');
        }
        if ($observedAt === false || $completedAt === false) {
            return $this->failure('timestamp_malformed');
        }

        $age = (int) floor($observedAt->diffInSeconds($now, false));
        if ($age < -self::FUTURE_TOLERANCE_SECONDS) {
            return $this->failure('future', $payload, $age);
        }
        if ($age > $maxAgeSeconds) {
            return $this->failure('stale', $payload, $age);
        }
        if ($completedAt->greaterThan($observedAt)) {
            return $this->failure('completion_after_observation', $payload, $age);
        }
        $status = (string) $payload['status'];
        if (! in_array($status, ['started', 'healthy', 'failed', 'overlap'], true)) {
            return $this->failure('contract_mismatch', $payload, $age);
        }
        if ($status === 'overlap') {
            return $this->failure('overlap', $payload, $age);
        }
        if ($status === 'started') {
            $completionAge = (int) floor($completedAt->diffInSeconds($now, false));
            if (($payload['last_exit_code'] ?? null) !== 0) {
                return $this->failure('in_progress_after_failed_completion', $payload, $age);
            }
            if ($completionAge > $maxAgeSeconds) {
                return $this->failure('previous_completion_stale', $payload, $age);
            }

            return array_merge($payload, [
                'ok' => true,
                'reason' => 'in_progress',
                'age_seconds' => max(0, $age),
            ]);
        }
        if ($status !== 'healthy' || ($payload['last_exit_code'] ?? null) !== 0) {
            return $this->failure('failed', $payload, $age);
        }

        return array_merge($payload, [
            'ok' => true,
            'reason' => 'healthy',
            'age_seconds' => max(0, $age),
        ]);
    }

    /** @return array<string, mixed>|null */
    private function readRaw(): ?array
    {
        $path = $this->heartbeatPath();
        if (! is_file($path)) {
            return null;
        }
        $contents = file_get_contents($path);
        if (! is_string($contents)) {
            return null;
        }

        try {
            $payload = json_decode($contents, true, 16, JSON_THROW_ON_ERROR);
        } catch (\Throwable) {
            return null;
        }

        return is_array($payload) ? $payload : null;
    }

    /** @param array<string, mixed> $payload */
    private function writeAtomic(array $payload): void
    {
        $path = $this->heartbeatPath();
        $directory = dirname($path);
        if (! is_dir($directory) && ! mkdir($directory, 0775, true) && ! is_dir($directory)) {
            throw new RuntimeException('Scheduler heartbeat directory is unavailable.');
        }
        $temporary = $path.'.tmp.'.bin2hex(random_bytes(8));
        $bytes = file_put_contents(
            $temporary,
            json_encode($payload, JSON_THROW_ON_ERROR | JSON_UNESCAPED_SLASHES)."\n",
            LOCK_EX,
        );
        if (! is_int($bytes) || $bytes < 1 || ! chmod($temporary, 0660) || ! rename($temporary, $path)) {
            @unlink($temporary);
            throw new RuntimeException('Scheduler heartbeat atomic write failed.');
        }
    }

    /** @param array<string, mixed>|null $payload @return array<string, mixed> */
    private function failure(string $reason, ?array $payload = null, ?int $age = null): array
    {
        return [
            'ok' => false,
            'reason' => $reason,
            'scheduler_contract_revision' => self::schedulerContractRevision(),
            'runner' => self::RUNNER,
            'observed_at' => is_string($payload['observed_at'] ?? null) ? $payload['observed_at'] : null,
            'last_completed_at' => is_string($payload['last_completed_at'] ?? null) ? $payload['last_completed_at'] : null,
            'last_exit_code' => is_int($payload['last_exit_code'] ?? null) ? $payload['last_exit_code'] : null,
            'status' => is_string($payload['status'] ?? null) ? $payload['status'] : null,
            'age_seconds' => $age,
        ];
    }

    private function heartbeatPath(): string
    {
        return $this->path ?? storage_path('app/ops/scheduler-heartbeat.json');
    }
}
