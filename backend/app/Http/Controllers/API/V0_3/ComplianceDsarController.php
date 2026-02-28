<?php

declare(strict_types=1);

namespace App\Http\Controllers\API\V0_3;

use App\Http\Controllers\Controller;
use App\Jobs\ExecuteDsarRequestJob;
use App\Support\SchemaBaseline;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Illuminate\Validation\Rule;

final class ComplianceDsarController extends Controller
{
    /** @var array<int,string> */
    private const ALLOWED_MODES = ['hybrid_anonymize', 'delete'];

    /** @var array<int,string> */
    private const ALLOWED_ROLES = ['owner', 'admin'];

    public function store(Request $request): JsonResponse
    {
        $context = $this->resolveActorContext($request);
        if ($context === null) {
            return $this->orgNotFound();
        }

        if (! SchemaBaseline::hasTable('dsar_requests')) {
            return response()->json([
                'ok' => false,
                'error_code' => 'DSAR_NOT_READY',
                'message' => 'dsar request table is not ready.',
            ], 503);
        }

        $payload = $request->validate([
            'subject_user_id' => ['required', 'integer', 'min:1'],
            'mode' => ['nullable', 'string', Rule::in(self::ALLOWED_MODES)],
            'reason' => ['nullable', 'string', 'max:255'],
        ]);

        $orgId = $context['org_id'];
        $actorUserId = $context['actor_user_id'];
        $subjectUserId = (int) $payload['subject_user_id'];
        $mode = (string) ($payload['mode'] ?? 'hybrid_anonymize');
        $reason = isset($payload['reason']) ? trim((string) $payload['reason']) : null;
        $initialPayload = [
            'ip' => $request->ip(),
            'user_agent' => (string) $request->userAgent(),
        ];

        $lock = DB::transaction(function () use ($orgId, $actorUserId, $subjectUserId, $mode, $reason, $initialPayload): array {
            $active = DB::table('dsar_requests')
                ->where('org_id', $orgId)
                ->where('subject_user_id', $subjectUserId)
                ->where('mode', $mode)
                ->whereIn('status', ['pending', 'running'])
                ->orderByDesc('created_at')
                ->orderByDesc('id')
                ->lockForUpdate()
                ->first();

            if ($active === null) {
                $requestId = (string) Str::uuid();
                $now = now();
                DB::table('dsar_requests')->insert([
                    'id' => $requestId,
                    'org_id' => $orgId,
                    'subject_user_id' => $subjectUserId,
                    'requested_by_user_id' => $actorUserId,
                    'executed_by_user_id' => null,
                    'mode' => $mode,
                    'status' => 'pending',
                    'reason' => $reason,
                    'payload_json' => json_encode($initialPayload, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES),
                    'result_json' => null,
                    'requested_at' => $now,
                    'executed_at' => null,
                    'created_at' => $now,
                    'updated_at' => $now,
                ]);
            } else {
                $requestId = trim((string) ($active->id ?? ''));
            }

            return $this->lockExecutionState($requestId, $orgId, $actorUserId, true);
        });

        if (($lock['state'] ?? '') === 'missing') {
            return $this->requestNotFound();
        }

        $requestId = (string) ($lock['request_id'] ?? '');
        $status = (string) ($lock['status'] ?? 'running');
        $taskId = (string) ($lock['task_id'] ?? '');
        $referenceId = (string) ($lock['reference_id'] ?? '');

        if (($lock['state'] ?? '') === 'dispatch') {
            ExecuteDsarRequestJob::dispatch(
                $requestId,
                $orgId,
                $actorUserId,
                $taskId,
                $referenceId
            )->afterCommit();
        }

        return response()->json([
            'ok' => true,
            'request_id' => $requestId,
            'status' => $status,
            'meta' => [
                'execution' => [
                    'task_id' => $taskId !== '' ? $taskId : null,
                    'job_reference' => $referenceId !== '' ? $referenceId : null,
                ],
            ],
        ], 202);
    }

    public function show(Request $request, string $id): JsonResponse
    {
        $context = $this->resolveActorContext($request);
        if ($context === null) {
            return $this->orgNotFound();
        }

        $row = $this->findRequestRow($id, $context['org_id']);
        if ($row === null) {
            return $this->requestNotFound();
        }

        return response()->json([
            'ok' => true,
            'request' => $this->mapRequestRow($row),
        ]);
    }

    public function execute(Request $request, string $id): JsonResponse
    {
        $context = $this->resolveActorContext($request);
        if ($context === null) {
            return $this->orgNotFound();
        }

        $lock = DB::transaction(fn (): array => $this->lockExecutionState(
            $id,
            $context['org_id'],
            $context['actor_user_id'],
            true
        ));

        $state = (string) ($lock['state'] ?? '');
        if ($state === 'missing') {
            return $this->requestNotFound();
        }

        $status = (string) ($lock['status'] ?? 'running');
        $taskId = (string) ($lock['task_id'] ?? '');
        $referenceId = (string) ($lock['reference_id'] ?? '');

        if ($state === 'dispatch') {
            ExecuteDsarRequestJob::dispatch(
                $id,
                $context['org_id'],
                $context['actor_user_id'],
                $taskId,
                $referenceId
            )->afterCommit();
        }

        return response()->json([
            'ok' => true,
            'request_id' => $id,
            'status' => $status,
            'task_id' => $taskId !== '' ? $taskId : null,
            'job_reference' => $referenceId !== '' ? $referenceId : null,
            'meta' => [
                'execution' => [
                    'task_id' => $taskId !== '' ? $taskId : null,
                    'job_reference' => $referenceId !== '' ? $referenceId : null,
                ],
            ],
        ], 202);
    }

    /**
     * @return array{
     *   state:string,
     *   request_id?:string,
     *   status?:string,
     *   task_id?:string,
     *   reference_id?:string
     * }
     */
    private function lockExecutionState(
        string $requestId,
        int $orgId,
        int $actorUserId,
        bool $dispatchWhenRunningWithoutExecution
    ): array {
        $row = DB::table('dsar_requests')
            ->where('id', $requestId)
            ->where('org_id', $orgId)
            ->lockForUpdate()
            ->first();

        if ($row === null) {
            return ['state' => 'missing'];
        }

        $status = trim((string) ($row->status ?? 'pending'));
        $subjectUserId = (int) ($row->subject_user_id ?? 0);
        $payload = $this->decodeJson($row->payload_json ?? null) ?? [];
        $execution = is_array($payload['execution'] ?? null) ? $payload['execution'] : [];

        $payloadReferenceId = trim((string) ($execution['reference_id'] ?? ''));
        $payloadTaskId = trim((string) ($execution['task_id'] ?? ''));
        $executionMissing = $payloadReferenceId === '' || $payloadTaskId === '';

        $referenceId = $payloadReferenceId !== '' ? $payloadReferenceId : (string) Str::uuid();
        $taskRow = null;
        if (SchemaBaseline::hasTable('dsar_request_tasks')) {
            if ($payloadTaskId !== '') {
                $taskRow = DB::table('dsar_request_tasks')
                    ->where('id', $payloadTaskId)
                    ->where('request_id', $requestId)
                    ->first();
            }
            if ($taskRow === null) {
                $taskRow = DB::table('dsar_request_tasks')
                    ->where('request_id', $requestId)
                    ->where('domain', 'orchestration')
                    ->where('action', 'execute')
                    ->orderByDesc('created_at')
                    ->first();
            }
        }

        $taskId = $payloadTaskId !== '' ? $payloadTaskId : trim((string) ($taskRow->id ?? ''));
        if ($taskId === '') {
            $taskId = (string) Str::uuid();
        }

        $taskExists = $taskRow !== null;
        $shouldDispatch = false;
        $now = now();

        if ($status === 'pending') {
            if (SchemaBaseline::hasTable('dsar_request_tasks') && ! $taskExists) {
                $this->createOrchestrationTask($taskId, $requestId, $orgId, $subjectUserId, $actorUserId, $referenceId, $now);
            }

            $payload['execution'] = [
                'reference_id' => $referenceId,
                'task_id' => $taskId,
                'queued_by_user_id' => $actorUserId,
                'queued_at' => $now->toISOString(),
            ];

            DB::table('dsar_requests')
                ->where('id', $requestId)
                ->where('org_id', $orgId)
                ->update([
                    'status' => 'running',
                    'executed_by_user_id' => $actorUserId,
                    'payload_json' => json_encode($payload, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES),
                    'updated_at' => $now,
                ]);

            $this->appendStatusTransitionAudit(
                $requestId,
                $orgId,
                $subjectUserId,
                'pending',
                'running',
                $referenceId,
                $taskId
            );

            $status = 'running';
            $shouldDispatch = true;
        } elseif ($status === 'running' && $executionMissing) {
            if (SchemaBaseline::hasTable('dsar_request_tasks') && ! $taskExists) {
                $this->createOrchestrationTask($taskId, $requestId, $orgId, $subjectUserId, $actorUserId, $referenceId, $now);
                if ($dispatchWhenRunningWithoutExecution) {
                    $shouldDispatch = true;
                }
            } elseif (! SchemaBaseline::hasTable('dsar_request_tasks') && $dispatchWhenRunningWithoutExecution) {
                $shouldDispatch = true;
            }

            $payload['execution'] = [
                'reference_id' => $referenceId,
                'task_id' => $taskId,
                'queued_by_user_id' => $actorUserId,
                'queued_at' => $now->toISOString(),
            ];

            DB::table('dsar_requests')
                ->where('id', $requestId)
                ->where('org_id', $orgId)
                ->update([
                    'payload_json' => json_encode($payload, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES),
                    'updated_at' => $now,
                ]);
        }

        return [
            'state' => $shouldDispatch ? 'dispatch' : 'existing',
            'request_id' => $requestId,
            'status' => $status !== '' ? $status : 'running',
            'task_id' => $taskId,
            'reference_id' => $referenceId,
        ];
    }

    private function createOrchestrationTask(
        string $taskId,
        string $requestId,
        int $orgId,
        int $subjectUserId,
        int $actorUserId,
        string $referenceId,
        \Illuminate\Support\Carbon $now
    ): void {
        DB::table('dsar_request_tasks')->insert([
            'id' => $taskId,
            'request_id' => $requestId,
            'org_id' => $orgId,
            'subject_user_id' => $subjectUserId > 0 ? $subjectUserId : null,
            'domain' => 'orchestration',
            'action' => 'execute',
            'status' => 'pending',
            'error_code' => null,
            'stats_json' => json_encode([
                'queued_by_user_id' => $actorUserId,
                'reference_id' => $referenceId,
            ], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES),
            'started_at' => null,
            'finished_at' => null,
            'created_at' => $now,
            'updated_at' => $now,
        ]);
    }

    /**
     * @return array{org_id:int,actor_user_id:int,org_role:string}|null
     */
    private function resolveActorContext(Request $request): ?array
    {
        $orgId = $this->positiveIntOrNull(
            $request->attributes->get('org_id') ?? $request->attributes->get('fm_org_id')
        );
        if ($orgId === null || $orgId <= 0) {
            return null;
        }

        $actorUserId = $this->positiveIntOrNull(
            $request->attributes->get('fm_user_id') ?? $request->attributes->get('user_id')
        );
        if ($actorUserId === null) {
            return null;
        }

        $role = trim((string) $request->attributes->get('org_role', ''));
        if (! in_array($role, self::ALLOWED_ROLES, true)) {
            return null;
        }

        return [
            'org_id' => $orgId,
            'actor_user_id' => $actorUserId,
            'org_role' => $role,
        ];
    }

    private function findRequestRow(string $id, int $orgId): ?object
    {
        if (! SchemaBaseline::hasTable('dsar_requests')) {
            return null;
        }

        return DB::table('dsar_requests')
            ->where('id', $id)
            ->where('org_id', $orgId)
            ->first();
    }

    /**
     * @return array<string,mixed>
     */
    private function mapRequestRow(object $row): array
    {
        return [
            'id' => (string) ($row->id ?? ''),
            'org_id' => (int) ($row->org_id ?? 0),
            'subject_user_id' => (int) ($row->subject_user_id ?? 0),
            'requested_by_user_id' => $this->positiveIntOrNull($row->requested_by_user_id ?? null),
            'executed_by_user_id' => $this->positiveIntOrNull($row->executed_by_user_id ?? null),
            'mode' => (string) ($row->mode ?? ''),
            'status' => (string) ($row->status ?? ''),
            'reason' => $row->reason !== null ? (string) $row->reason : null,
            'result' => $this->decodeJson($row->result_json ?? null),
            'requested_at' => $row->requested_at !== null ? (string) $row->requested_at : null,
            'executed_at' => $row->executed_at !== null ? (string) $row->executed_at : null,
        ];
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

    private function positiveIntOrNull(mixed $value): ?int
    {
        $raw = trim((string) $value);
        if ($raw === '' || preg_match('/^\d+$/', $raw) !== 1) {
            return null;
        }

        $int = (int) $raw;

        return $int > 0 ? $int : null;
    }

    private function orgNotFound(): JsonResponse
    {
        return response()->json([
            'ok' => false,
            'error_code' => 'ORG_NOT_FOUND',
            'message' => 'org not found.',
        ], 404);
    }

    private function requestNotFound(): JsonResponse
    {
        return response()->json([
            'ok' => false,
            'error_code' => 'DSAR_REQUEST_NOT_FOUND',
            'message' => 'dsar request not found.',
        ], 404);
    }

    private function appendStatusTransitionAudit(
        string $requestId,
        int $orgId,
        int $subjectUserId,
        string $from,
        string $to,
        string $referenceId,
        string $taskId
    ): void {
        if (! SchemaBaseline::hasTable('dsar_audit_logs')) {
            return;
        }

        $now = now();
        DB::table('dsar_audit_logs')->insert([
            'request_id' => $requestId,
            'org_id' => $orgId,
            'subject_user_id' => $subjectUserId > 0 ? $subjectUserId : null,
            'event_type' => 'dsar_status_transition',
            'level' => 'info',
            'message' => sprintf('dsar request transitioned from %s to %s', $from, $to),
            'context_json' => json_encode([
                'from' => $from,
                'to' => $to,
                'reference_id' => $referenceId,
                'task_id' => $taskId,
            ], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES),
            'occurred_at' => $now,
            'created_at' => $now,
            'updated_at' => $now,
        ]);
    }
}
