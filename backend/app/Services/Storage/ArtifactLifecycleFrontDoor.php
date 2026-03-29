<?php

declare(strict_types=1);

namespace App\Services\Storage;

use App\Models\ArtifactLifecycleEvent;
use App\Models\ArtifactLifecycleJob;
use App\Support\SchemaBaseline;

final class ArtifactLifecycleFrontDoor
{
    public function __construct(
        private readonly AttemptReceiptRecorder $receipts,
        private readonly RetentionPolicyResolver $retentionPolicyResolver,
    ) {}

    /**
     * @param  array<string,mixed>  $plan
     * @param  callable(array<string,mixed>):array<string,mixed>  $executor
     * @return array<string,mixed>
     */
    public function execute(string $jobType, array $plan, callable $executor): array
    {
        if (! $this->isEnabled() || ! SchemaBaseline::hasTable('artifact_lifecycle_jobs')) {
            return $executor($plan);
        }

        $attemptIds = $this->attemptIdsFromPlan($plan);
        foreach ($attemptIds as $attemptId) {
            $this->retentionPolicyResolver->ensureAttemptBinding($attemptId, 'lifecycle_front_door');
        }

        $job = ArtifactLifecycleJob::query()->create([
            'attempt_id' => $attemptIds[0] ?? null,
            'artifact_slot_id' => null,
            'job_type' => $jobType,
            'state' => 'running',
            'reason_code' => null,
            'blocked_reason_code' => null,
            'idempotency_key' => $this->buildIdempotencyKey($jobType, $plan),
            'request_payload_json' => $this->requestPayload($plan),
            'result_payload_json' => null,
            'attempt_count' => count($attemptIds),
            'started_at' => now(),
            'finished_at' => null,
        ]);

        $this->createEvent($job, 'job_started', null, 'running', [
            'plan_path' => data_get($plan, '_meta.plan_path'),
            'summary' => $plan['summary'] ?? null,
        ]);
        $this->recordRequestedReceipts($jobType, $attemptIds, $plan, (int) $job->id);

        try {
            $plan['_front_door'] = [
                'job_id' => (int) $job->id,
                'job_type' => $jobType,
            ];
            $result = $executor($plan);
            $finalState = $this->stateFromResult((string) ($result['status'] ?? 'executed'));

            $job->forceFill([
                'state' => $finalState,
                'reason_code' => $this->reasonCodeFromResult($result),
                'blocked_reason_code' => $this->blockedReasonFromResult($result),
                'result_payload_json' => $this->resultPayload($result),
                'finished_at' => now(),
            ])->save();

            foreach ($this->normalizedResults($result) as $item) {
                $candidateState = $this->candidateState((string) ($item['status'] ?? 'unknown'));
                $this->createEvent(
                    $job,
                    'candidate_'.trim((string) ($item['status'] ?? 'unknown')),
                    'running',
                    $candidateState,
                    $item
                );
                $this->recordCompletionReceipt($jobType, $item, $result, (int) $job->id);
            }

            $this->createEvent($job, 'job_finished', 'running', $finalState, [
                'summary' => $result['summary'] ?? null,
                'run_path' => $result['run_path'] ?? null,
                'status' => $result['status'] ?? null,
            ]);

            return $result;
        } catch (\Throwable $e) {
            $job->forceFill([
                'state' => 'failed',
                'reason_code' => 'EXECUTION_FAILED',
                'blocked_reason_code' => null,
                'result_payload_json' => [
                    'error_class' => $e::class,
                    'error_message' => $e->getMessage(),
                ],
                'finished_at' => now(),
            ])->save();

            $this->createEvent($job, 'job_failed', 'running', 'failed', [
                'error_class' => $e::class,
                'error_message' => $e->getMessage(),
            ]);
            foreach ($attemptIds as $attemptId) {
                $this->receipts->record(
                    $attemptId,
                    $this->failureReceiptType($jobType),
                    [
                        'job_type' => $jobType,
                        'job_id' => (int) $job->id,
                        'error_class' => $e::class,
                        'error_message' => $e->getMessage(),
                    ],
                    [
                        'source_system' => 'artifact_lifecycle_front_door',
                        'source_ref' => data_get($plan, '_meta.plan_path'),
                        'actor_type' => 'system',
                        'actor_id' => $jobType,
                        'occurred_at' => now(),
                        'recorded_at' => now(),
                        'idempotency_key' => hash('sha256', implode('|', [$attemptId, $jobType, 'failed', (string) $job->id])),
                    ],
                    true
                );
            }

            throw $e;
        }
    }

    private function recordRequestedReceipts(string $jobType, array $attemptIds, array $plan, int $jobId): void
    {
        foreach ($attemptIds as $attemptId) {
            $this->receipts->record(
                $attemptId,
                $this->requestedReceiptType($jobType),
                [
                    'job_type' => $jobType,
                    'job_id' => $jobId,
                    'plan_path' => data_get($plan, '_meta.plan_path'),
                    'summary' => $plan['summary'] ?? null,
                ],
                [
                    'source_system' => 'artifact_lifecycle_front_door',
                    'source_ref' => data_get($plan, '_meta.plan_path'),
                    'actor_type' => 'system',
                    'actor_id' => $jobType,
                    'occurred_at' => now(),
                    'recorded_at' => now(),
                    'idempotency_key' => hash('sha256', implode('|', [$attemptId, $jobType, 'requested', $jobId])),
                ],
                true
            );
        }
    }

    /**
     * @param  array<string,mixed>  $item
     * @param  array<string,mixed>  $result
     */
    private function recordCompletionReceipt(string $jobType, array $item, array $result, int $jobId): void
    {
        $attemptId = trim((string) ($item['attempt_id'] ?? ''));
        if ($attemptId === '') {
            return;
        }

        $status = trim((string) ($item['status'] ?? 'unknown'));
        $this->receipts->record(
            $attemptId,
            $this->completionReceiptType($jobType),
            [
                'job_type' => $jobType,
                'job_id' => $jobId,
                'status' => $status,
                'kind' => $item['kind'] ?? null,
                'source_path' => $item['source_path'] ?? null,
                'target_disk' => $item['target_disk'] ?? null,
                'target_object_key' => $item['target_object_key'] ?? null,
                'run_path' => $result['run_path'] ?? null,
                'summary' => $result['summary'] ?? null,
            ],
            [
                'source_system' => 'artifact_lifecycle_front_door',
                'source_ref' => (string) ($result['run_path'] ?? data_get($result, 'plan_path', '')),
                'actor_type' => 'system',
                'actor_id' => $jobType,
                'occurred_at' => now(),
                'recorded_at' => now(),
                'idempotency_key' => hash('sha256', implode('|', [$attemptId, $jobType, $status, (string) ($item['source_path'] ?? ''), (string) $jobId])),
            ],
            true
        );
    }

    /**
     * @param  array<string,mixed>  $payload
     */
    private function createEvent(ArtifactLifecycleJob $job, string $eventType, ?string $fromState, ?string $toState, array $payload): void
    {
        if (! SchemaBaseline::hasTable('artifact_lifecycle_events')) {
            return;
        }

        ArtifactLifecycleEvent::query()->create([
            'job_id' => (int) $job->id,
            'attempt_id' => $this->firstAttemptId($payload),
            'artifact_slot_id' => null,
            'event_type' => $eventType,
            'from_state' => $fromState,
            'to_state' => $toState,
            'reason_code' => $this->reasonCodeFromResult($payload),
            'payload_json' => $payload,
            'occurred_at' => now(),
        ]);
    }

    /**
     * @param  array<string,mixed>  $plan
     * @return list<string>
     */
    private function attemptIdsFromPlan(array $plan): array
    {
        $ids = [];
        $candidates = is_array($plan['candidates'] ?? null) ? array_values($plan['candidates']) : [];
        foreach ($candidates as $candidate) {
            if (! is_array($candidate)) {
                continue;
            }

            $attemptId = trim((string) ($candidate['attempt_id'] ?? ''));
            if ($attemptId !== '') {
                $ids[$attemptId] = true;
            }
        }

        return array_values(array_keys($ids));
    }

    /**
     * @param  array<string,mixed>  $payload
     */
    private function firstAttemptId(array $payload): ?string
    {
        $attemptId = trim((string) ($payload['attempt_id'] ?? ''));

        return $attemptId !== '' ? $attemptId : null;
    }

    /**
     * @param  array<string,mixed>  $result
     * @return list<array<string,mixed>>
     */
    private function normalizedResults(array $result): array
    {
        $items = is_array($result['results'] ?? null) ? array_values($result['results']) : [];

        return array_values(array_filter($items, static fn (mixed $item): bool => is_array($item)));
    }

    /**
     * @param  array<string,mixed>  $plan
     * @return array<string,mixed>
     */
    private function requestPayload(array $plan): array
    {
        return [
            'schema' => $plan['schema'] ?? null,
            'mode' => $plan['mode'] ?? null,
            'summary' => $plan['summary'] ?? null,
            'plan_path' => data_get($plan, '_meta.plan_path'),
            'target_disk' => $plan['target_disk'] ?? $plan['disk'] ?? null,
        ];
    }

    /**
     * @param  array<string,mixed>  $result
     * @return array<string,mixed>
     */
    private function resultPayload(array $result): array
    {
        return [
            'schema' => $result['schema'] ?? null,
            'mode' => $result['mode'] ?? null,
            'status' => $result['status'] ?? null,
            'summary' => $result['summary'] ?? null,
            'run_path' => $result['run_path'] ?? null,
        ];
    }

    /**
     * @param  array<string,mixed>  $plan
     */
    private function buildIdempotencyKey(string $jobType, array $plan): string
    {
        return hash('sha256', json_encode([
            'job_type' => $jobType,
            'plan_path' => data_get($plan, '_meta.plan_path'),
            'summary' => $plan['summary'] ?? null,
        ], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES) ?: '');
    }

    private function requestedReceiptType(string $jobType): string
    {
        return match ($jobType) {
            'archive_report_artifacts' => 'artifact_archive_requested',
            'rehydrate_report_artifacts' => 'artifact_rehydrate_requested',
            'shrink_archived_report_artifacts' => 'artifact_shrink_requested',
            'dsar_purge_report_artifacts' => 'artifact_purge_requested',
            default => 'artifact_lifecycle_requested',
        };
    }

    private function completionReceiptType(string $jobType): string
    {
        return match ($jobType) {
            'archive_report_artifacts' => 'artifact_archived',
            'rehydrate_report_artifacts' => 'artifact_rehydrated',
            'shrink_archived_report_artifacts' => 'artifact_shrunk',
            'dsar_purge_report_artifacts' => 'artifact_purged',
            default => 'artifact_lifecycle_completed',
        };
    }

    private function failureReceiptType(string $jobType): string
    {
        return match ($jobType) {
            'archive_report_artifacts' => 'artifact_archive_failed',
            'rehydrate_report_artifacts' => 'artifact_rehydrate_failed',
            'shrink_archived_report_artifacts' => 'artifact_shrink_failed',
            'dsar_purge_report_artifacts' => 'artifact_purge_failed',
            default => 'artifact_lifecycle_failed',
        };
    }

    private function stateFromResult(string $status): string
    {
        return match (trim($status)) {
            'executed', 'succeeded' => 'succeeded',
            'partial_failure', 'failed' => 'failed',
            default => 'succeeded',
        };
    }

    private function candidateState(string $status): string
    {
        $status = trim($status);

        return match (true) {
            str_starts_with($status, 'blocked') => 'blocked',
            str_starts_with($status, 'failed') => 'failed',
            $status === 'copied',
            $status === 'already_archived',
            $status === 'rehydrated',
            $status === 'deleted',
            $status === 'skipped',
            $status === 'skipped_missing_local' => 'succeeded',
            default => 'running',
        };
    }

    /**
     * @param  array<string,mixed>  $payload
     */
    private function reasonCodeFromResult(array $payload): ?string
    {
        $status = trim((string) ($payload['status'] ?? ''));

        return $status !== '' ? strtoupper($status) : null;
    }

    /**
     * @param  array<string,mixed>  $payload
     */
    private function blockedReasonFromResult(array $payload): ?string
    {
        $status = trim((string) ($payload['status'] ?? ''));

        return str_starts_with($status, 'blocked') ? strtoupper($status) : null;
    }

    private function isEnabled(): bool
    {
        return (bool) config('storage_rollout.lifecycle_front_door_enabled', false);
    }
}
