<?php

declare(strict_types=1);

namespace App\Console\Commands\Ops;

use App\Jobs\ExecuteDsarRequestJob;
use App\Support\SchemaBaseline;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

final class DsarRequeue extends Command
{
    protected $signature = 'ops:dsar-requeue
        {--org-id= : Org id}
        {--request-id= : DSAR request id}
        {--actor-user-id= : Actor user id}
        {--reason=manual_requeue : Replay reason}
        {--json=1 : Output JSON payload}';

    protected $description = 'Replay a failed DSAR request with idempotent no-duplicate semantics.';

    public function handle(): int
    {
        $orgId = $this->positiveInt($this->option('org-id'));
        $actorUserId = $this->positiveInt($this->option('actor-user-id'));
        $requestId = trim((string) ($this->option('request-id') ?? ''));
        $reason = trim((string) ($this->option('reason') ?? 'manual_requeue'));
        if ($reason === '') {
            $reason = 'manual_requeue';
        }

        if ($orgId <= 0 || $actorUserId <= 0 || $requestId === '') {
            $payload = [
                'ok' => false,
                'state' => 'invalid',
                'request_id' => $requestId !== '' ? $requestId : null,
                'status' => null,
                'task_id' => null,
                'job_reference' => null,
                'reason' => $reason,
                'error_code' => 'INVALID_ARGUMENTS',
            ];

            return $this->outputPayload($payload, self::FAILURE);
        }

        $result = DB::transaction(function () use ($orgId, $requestId, $actorUserId, $reason): array {
            return $this->requeueWithinTransaction($orgId, $requestId, $actorUserId, $reason);
        });

        if (($result['state'] ?? '') === 'dispatch') {
            ExecuteDsarRequestJob::dispatch(
                (string) $result['request_id'],
                $orgId,
                $actorUserId,
                (string) $result['task_id'],
                (string) $result['job_reference']
            )->afterCommit();
        }

        $payload = [
            'ok' => ($result['state'] ?? '') !== 'missing',
            'state' => (string) ($result['state'] ?? 'missing'),
            'request_id' => (string) ($result['request_id'] ?? $requestId),
            'status' => (string) ($result['status'] ?? ''),
            'task_id' => $result['task_id'] ?? null,
            'job_reference' => $result['job_reference'] ?? null,
            'reason' => $reason,
        ];

        $exitCode = ($result['state'] ?? '') === 'missing' ? self::FAILURE : self::SUCCESS;

        return $this->outputPayload($payload, $exitCode);
    }

    /**
     * @return array{
     *   state:string,
     *   request_id?:string,
     *   status?:string,
     *   task_id?:string,
     *   job_reference?:string
     * }
     */
    private function requeueWithinTransaction(int $orgId, string $requestId, int $actorUserId, string $reason): array
    {
        if (! SchemaBaseline::hasTable('dsar_requests')) {
            return ['state' => 'missing'];
        }

        $row = DB::table('dsar_requests')
            ->where('id', $requestId)
            ->where('org_id', $orgId)
            ->lockForUpdate()
            ->first();

        if ($row === null) {
            return ['state' => 'missing'];
        }

        $status = trim((string) ($row->status ?? 'pending'));
        if ($status === '') {
            $status = 'pending';
        }

        $payload = $this->decodeJson($row->payload_json ?? null) ?? [];
        $execution = is_array($payload['execution'] ?? null) ? $payload['execution'] : [];
        $existingTaskId = trim((string) ($execution['task_id'] ?? ''));
        $existingReferenceId = trim((string) ($execution['reference_id'] ?? ''));

        $this->appendAudit(
            requestId: (string) ($row->id ?? ''),
            orgId: (int) ($row->org_id ?? 0),
            subjectUserId: $this->nullablePositiveInt($row->subject_user_id ?? null),
            eventType: 'requeue_requested',
            level: 'info',
            message: 'dsar replay requested',
            context: [
                'previous_status' => $status,
                'reason' => $reason,
                'actor_user_id' => $actorUserId,
                'task_id' => $existingTaskId !== '' ? $existingTaskId : null,
                'reference_id' => $existingReferenceId !== '' ? $existingReferenceId : null,
            ]
        );

        if ($status !== 'failed') {
            return [
                'state' => 'noop',
                'request_id' => (string) ($row->id ?? $requestId),
                'status' => $status,
                'task_id' => $existingTaskId !== '' ? $existingTaskId : null,
                'job_reference' => $existingReferenceId !== '' ? $existingReferenceId : null,
            ];
        }

        $now = now();
        $taskId = (string) Str::uuid();
        $referenceId = (string) Str::uuid();
        $requeueCount = max(0, (int) ($execution['requeue_count'] ?? 0)) + 1;

        $payload['execution'] = [
            'task_id' => $taskId,
            'reference_id' => $referenceId,
            'queued_by_user_id' => $actorUserId,
            'queued_at' => $now->toISOString(),
            'requeue_count' => $requeueCount,
            'requeue_reason' => $reason,
            'requeue_requested_at' => $now->toISOString(),
            'requeue_requested_by_user_id' => $actorUserId,
        ];

        DB::table('dsar_requests')
            ->where('id', $requestId)
            ->where('org_id', $orgId)
            ->update([
                'status' => 'running',
                'executed_by_user_id' => $actorUserId,
                'result_json' => null,
                'payload_json' => json_encode($payload, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES),
                'updated_at' => $now,
            ]);

        if (SchemaBaseline::hasTable('dsar_request_tasks')) {
            DB::table('dsar_request_tasks')->insert([
                'id' => $taskId,
                'request_id' => $requestId,
                'org_id' => $orgId,
                'subject_user_id' => $this->nullablePositiveInt($row->subject_user_id ?? null),
                'domain' => 'orchestration',
                'action' => 'execute',
                'status' => 'pending',
                'error_code' => null,
                'stats_json' => json_encode([
                    'queued_by_user_id' => $actorUserId,
                    'reference_id' => $referenceId,
                    'requeue' => true,
                    'requeue_count' => $requeueCount,
                    'requeue_reason' => $reason,
                ], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES),
                'started_at' => null,
                'finished_at' => null,
                'created_at' => $now,
                'updated_at' => $now,
            ]);
        }

        $this->appendAudit(
            requestId: (string) ($row->id ?? ''),
            orgId: (int) ($row->org_id ?? 0),
            subjectUserId: $this->nullablePositiveInt($row->subject_user_id ?? null),
            eventType: 'dsar_status_transition',
            level: 'info',
            message: 'dsar request transitioned from failed to running',
            context: [
                'from' => 'failed',
                'to' => 'running',
                'task_id' => $taskId,
                'reference_id' => $referenceId,
            ]
        );

        $this->appendAudit(
            requestId: (string) ($row->id ?? ''),
            orgId: (int) ($row->org_id ?? 0),
            subjectUserId: $this->nullablePositiveInt($row->subject_user_id ?? null),
            eventType: 'requeue_started',
            level: 'info',
            message: 'dsar replay dispatched',
            context: [
                'task_id' => $taskId,
                'reference_id' => $referenceId,
                'requeue_count' => $requeueCount,
                'reason' => $reason,
            ]
        );

        return [
            'state' => 'dispatch',
            'request_id' => (string) ($row->id ?? $requestId),
            'status' => 'running',
            'task_id' => $taskId,
            'job_reference' => $referenceId,
        ];
    }

    /**
     * @param  array<string,mixed>  $context
     */
    private function appendAudit(
        string $requestId,
        int $orgId,
        ?int $subjectUserId,
        string $eventType,
        string $level,
        string $message,
        array $context
    ): void {
        if (! SchemaBaseline::hasTable('dsar_audit_logs')) {
            return;
        }

        $now = now();
        DB::table('dsar_audit_logs')->insert([
            'request_id' => $requestId,
            'org_id' => $orgId,
            'subject_user_id' => $subjectUserId,
            'event_type' => $eventType,
            'level' => $level,
            'message' => $message,
            'context_json' => json_encode($context, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES),
            'occurred_at' => $now,
            'created_at' => $now,
            'updated_at' => $now,
        ]);
    }

    /**
     * @return array<string,mixed>|null
     */
    private function decodeJson(mixed $value): ?array
    {
        if (is_array($value)) {
            return $value;
        }
        if (! is_string($value) || trim($value) === '') {
            return null;
        }

        $decoded = json_decode($value, true);

        return is_array($decoded) ? $decoded : null;
    }

    private function nullablePositiveInt(mixed $value): ?int
    {
        $value = trim((string) $value);
        if ($value === '' || preg_match('/^\d+$/', $value) !== 1) {
            return null;
        }

        $int = (int) $value;

        return $int > 0 ? $int : null;
    }

    private function positiveInt(mixed $value): int
    {
        return $this->nullablePositiveInt($value) ?? 0;
    }

    private function outputPayload(array $payload, int $exitCode): int
    {
        if ($this->isTruthy($this->option('json'))) {
            $this->line((string) json_encode($payload, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES));
        } else {
            $this->line(sprintf(
                'state=%s request_id=%s status=%s task_id=%s job_reference=%s reason=%s',
                (string) ($payload['state'] ?? ''),
                (string) ($payload['request_id'] ?? ''),
                (string) ($payload['status'] ?? ''),
                (string) ($payload['task_id'] ?? ''),
                (string) ($payload['job_reference'] ?? ''),
                (string) ($payload['reason'] ?? '')
            ));
        }

        return $exitCode;
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
