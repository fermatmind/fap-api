<?php

declare(strict_types=1);

namespace App\Services\Storage;

use App\Models\ArtifactLifecycleEvent;
use App\Models\ArtifactLifecycleJob;
use App\Models\ReportArtifactSlot;
use App\Support\SchemaBaseline;
use Illuminate\Support\Facades\DB;

final class ArtifactLifecycleLedgerWriter
{
    public function __construct(
        private readonly AttemptReceiptRecorder $receipts,
    ) {}

    /**
     * @param  array<string,mixed>  $plan
     * @param  array<string,mixed>  $result
     * @return array<string,mixed>|null
     */
    public function recordArchiveExecution(array $plan, array $result): ?array
    {
        return $this->recordExecution('archive_report_artifacts', 'artifact_archived', $plan, $result);
    }

    /**
     * @param  array<string,mixed>  $plan
     * @param  array<string,mixed>  $result
     * @return array<string,mixed>|null
     */
    public function recordRehydrateExecution(array $plan, array $result): ?array
    {
        return $this->recordExecution('rehydrate_report_artifacts', 'artifact_rehydrated', $plan, $result);
    }

    /**
     * @param  array<string,mixed>  $plan
     * @param  array<string,mixed>  $result
     * @return array<string,mixed>|null
     */
    public function recordShrinkExecution(array $plan, array $result): ?array
    {
        return $this->recordExecution('shrink_archived_report_artifacts', 'artifact_shrunk', $plan, $result);
    }

    /**
     * @param  array<string,mixed>  $plan
     * @param  array<string,mixed>  $result
     * @return array<string,mixed>|null
     */
    private function recordExecution(string $jobType, string $receiptType, array $plan, array $result): ?array
    {
        if (! $this->isEnabled() || ! SchemaBaseline::hasTable('artifact_lifecycle_jobs')) {
            return null;
        }

        $now = now();
        $jobState = $this->normalizeState((string) ($result['status'] ?? ''));

        return DB::transaction(function () use ($jobType, $receiptType, $plan, $result, $jobState, $now): array {
            $job = ArtifactLifecycleJob::query()->updateOrCreate(
                ['idempotency_key' => $this->buildIdempotencyKey($jobType, $plan, $result)],
                [
                    'attempt_id' => $this->firstAttemptId($result),
                    'artifact_slot_id' => $this->firstArtifactSlotId($result),
                    'job_type' => $jobType,
                    'state' => $jobState,
                    'reason_code' => $this->reasonCodeForResult($result),
                    'blocked_reason_code' => $this->blockedReasonCodeForResult($result),
                    'request_payload_json' => $this->buildRequestPayload($plan),
                    'result_payload_json' => $this->buildResultPayload($result),
                    'attempt_count' => $this->attemptCountForResult($result),
                    'started_at' => $now,
                    'finished_at' => $now,
                ]
            );

            $eventRows = [];
            $eventRows[] = $this->createEventRow($job, 'job_started', null, $jobState, $plan, $now);
            foreach ($this->normalizedResults($result) as $item) {
                $status = (string) ($item['status'] ?? 'unknown');
                $eventRows[] = $this->createEventRow(
                    $job,
                    'candidate_'.$status,
                    $this->stateForCandidateFrom($jobType, $item),
                    $this->stateForCandidateTo($jobType, $item),
                    $item,
                    $now
                );

                $attemptId = $this->normalizeText($item['attempt_id'] ?? null);
                if ($attemptId !== null) {
                    $this->receipts->record(
                        $attemptId,
                        $receiptType,
                        [
                            'job_type' => $jobType,
                            'status' => $status,
                            'kind' => $item['kind'] ?? null,
                            'source_path' => $item['source_path'] ?? null,
                            'target_object_key' => $item['target_object_key'] ?? null,
                            'target_disk' => $item['target_disk'] ?? null,
                            'summary' => $result['summary'] ?? [],
                            'run_path' => $result['run_path'] ?? null,
                        ],
                        [
                            'source_system' => 'artifact_lifecycle',
                            'source_ref' => trim((string) ($result['run_path'] ?? data_get($plan, '_meta.plan_path', $jobType))),
                            'actor_type' => 'system',
                            'actor_id' => $jobType,
                            'idempotency_key' => hash('sha256', implode('|', [
                                $attemptId,
                                $jobType,
                                $status,
                                (string) ($item['kind'] ?? ''),
                                (string) ($item['source_path'] ?? ''),
                                (string) ($result['run_path'] ?? ''),
                            ])),
                            'occurred_at' => $now,
                            'recorded_at' => $now,
                        ]
                    );
                }
            }
            $eventRows[] = $this->createEventRow($job, 'job_finished', $jobState, null, $result, $now);

            return [
                'job' => $job->fresh()?->toArray() ?? $job->toArray(),
                'events' => array_values(array_filter($eventRows)),
            ];
        });
    }

    /**
     * @param  array<string,mixed>  $payload
     * @return array<string,mixed>|null
     */
    private function createEventRow(ArtifactLifecycleJob $job, string $eventType, ?string $fromState, ?string $toState, array $payload, mixed $occurredAt): ?array
    {
        if (! SchemaBaseline::hasTable('artifact_lifecycle_events')) {
            return null;
        }

        $event = ArtifactLifecycleEvent::query()->create([
            'job_id' => (int) $job->id,
            'attempt_id' => $this->normalizeText($payload['attempt_id'] ?? null),
            'artifact_slot_id' => $this->resolveArtifactSlotId($payload),
            'event_type' => $eventType,
            'from_state' => $fromState,
            'to_state' => $toState,
            'reason_code' => $this->reasonCodeForResult($payload),
            'payload_json' => $payload,
            'occurred_at' => $occurredAt,
        ]);

        return $event->fresh()?->toArray() ?? $event->toArray();
    }

    /**
     * @param  array<string,mixed>  $plan
     * @param  array<string,mixed>  $result
     */
    private function buildIdempotencyKey(string $jobType, array $plan, array $result): string
    {
        return hash('sha256', json_encode([
            'job_type' => $jobType,
            'plan' => [
                'schema' => $plan['schema'] ?? null,
                'mode' => $plan['mode'] ?? null,
                'target_disk' => $plan['target_disk'] ?? data_get($plan, 'disk'),
                'plan_path' => data_get($plan, '_meta.plan_path'),
            ],
            'result' => [
                'status' => $result['status'] ?? null,
                'run_path' => $result['run_path'] ?? null,
                'summary' => $result['summary'] ?? null,
            ],
        ], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES) ?: '');
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
     * @param  array<string,mixed>  $result
     */
    private function firstAttemptId(array $result): ?string
    {
        foreach ($this->normalizedResults($result) as $item) {
            $attemptId = $this->normalizeText($item['attempt_id'] ?? null);
            if ($attemptId !== null) {
                return $attemptId;
            }
        }

        return null;
    }

    /**
     * @param  array<string,mixed>  $result
     */
    private function attemptCountForResult(array $result): int
    {
        $attemptIds = [];
        foreach ($this->normalizedResults($result) as $item) {
            $attemptId = $this->normalizeText($item['attempt_id'] ?? null);
            if ($attemptId !== null) {
                $attemptIds[$attemptId] = true;
            }
        }

        return count($attemptIds);
    }

    /**
     * @param  array<string,mixed>  $result
     */
    private function firstArtifactSlotId(array $result): ?int
    {
        foreach ($this->normalizedResults($result) as $item) {
            $slotId = $this->resolveArtifactSlotId($item);
            if ($slotId !== null) {
                return $slotId;
            }
        }

        return null;
    }

    /**
     * @param  array<string,mixed>  $result
     */
    private function resolveArtifactSlotId(array $result): ?int
    {
        $attemptId = $this->normalizeText($result['attempt_id'] ?? null);
        $slotCode = $this->slotCodeForResult($result);
        if ($attemptId === null || $slotCode === null || ! SchemaBaseline::hasTable('report_artifact_slots')) {
            return null;
        }

        $slot = ReportArtifactSlot::query()
            ->where('attempt_id', $attemptId)
            ->where('slot_code', $slotCode)
            ->first();

        return $slot instanceof ReportArtifactSlot ? (int) $slot->id : null;
    }

    /**
     * @param  array<string,mixed>  $result
     */
    private function slotCodeForResult(array $result): ?string
    {
        $kind = trim((string) ($result['kind'] ?? ''));

        return match ($kind) {
            'report_json' => 'report_json_full',
            'report_free_pdf' => 'report_pdf_free',
            'report_full_pdf' => 'report_pdf_full',
            default => null,
        };
    }

    /**
     * @param  array<string,mixed>  $result
     */
    private function stateForCandidateFrom(string $jobType, array $result): ?string
    {
        return match ($jobType) {
            'archive_report_artifacts' => 'local_present',
            'rehydrate_report_artifacts' => 'archived',
            'shrink_archived_report_artifacts' => 'local_present',
            default => null,
        };
    }

    /**
     * @param  array<string,mixed>  $result
     */
    private function stateForCandidateTo(string $jobType, array $result): ?string
    {
        $status = (string) ($result['status'] ?? '');

        return match ($jobType) {
            'archive_report_artifacts' => in_array($status, ['copied', 'already_archived'], true) ? 'archived' : 'failed',
            'rehydrate_report_artifacts' => $status === 'rehydrated' ? 'available' : ($status === 'blocked' ? 'blocked' : 'failed'),
            'shrink_archived_report_artifacts' => $status === 'deleted' ? 'missing' : ($status === 'blocked_missing_remote' ? 'blocked' : 'failed'),
            default => null,
        };
    }

    /**
     * @param  array<string,mixed>  $result
     */
    private function reasonCodeForResult(array $result): ?string
    {
        $status = (string) ($result['status'] ?? '');
        if ($status === '') {
            return null;
        }

        return strtoupper($status);
    }

    /**
     * @param  array<string,mixed>  $result
     */
    private function blockedReasonCodeForResult(array $result): ?string
    {
        $status = (string) ($result['status'] ?? '');

        return str_starts_with($status, 'blocked') ? strtoupper($status) : null;
    }

    /**
     * @param  array<string,mixed>  $plan
     * @return array<string,mixed>
     */
    private function buildRequestPayload(array $plan): array
    {
        return [
            'schema' => $plan['schema'] ?? null,
            'mode' => $plan['mode'] ?? null,
            'target_disk' => $plan['target_disk'] ?? data_get($plan, 'disk'),
            'summary' => $plan['summary'] ?? null,
            'plan_path' => data_get($plan, '_meta.plan_path'),
        ];
    }

    /**
     * @param  array<string,mixed>  $result
     * @return array<string,mixed>
     */
    private function buildResultPayload(array $result): array
    {
        return [
            'schema' => $result['schema'] ?? null,
            'mode' => $result['mode'] ?? null,
            'status' => $result['status'] ?? null,
            'target_disk' => $result['target_disk'] ?? data_get($result, 'disk'),
            'summary' => $result['summary'] ?? null,
            'run_path' => $result['run_path'] ?? null,
        ];
    }

    private function normalizeState(string $state): string
    {
        $state = strtolower(trim($state));

        return in_array($state, ['executed', 'success', 'succeeded'], true)
            ? 'succeeded'
            : (in_array($state, ['partial_failure', 'failed', 'blocked'], true) ? 'failed' : 'running');
    }

    private function normalizeText(mixed $value): ?string
    {
        if (! is_string($value) && ! is_numeric($value)) {
            return null;
        }

        $value = trim((string) $value);

        return $value !== '' ? $value : null;
    }

    private function isEnabled(): bool
    {
        return (bool) config('storage_rollout.receipt_ledger_dual_write_enabled', false)
            && (bool) config('storage_rollout.lifecycle_ledger_dual_write_enabled', false);
    }
}
