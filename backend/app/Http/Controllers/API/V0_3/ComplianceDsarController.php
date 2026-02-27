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

        $requestId = (string) Str::uuid();
        $now = now();

        DB::table('dsar_requests')->insert([
            'id' => $requestId,
            'org_id' => $context['org_id'],
            'subject_user_id' => (int) $payload['subject_user_id'],
            'requested_by_user_id' => $context['actor_user_id'],
            'executed_by_user_id' => null,
            'mode' => (string) ($payload['mode'] ?? 'hybrid_anonymize'),
            'status' => 'pending',
            'reason' => isset($payload['reason']) ? trim((string) $payload['reason']) : null,
            'payload_json' => json_encode([
                'ip' => $request->ip(),
                'user_agent' => (string) $request->userAgent(),
            ], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES),
            'result_json' => null,
            'requested_at' => $now,
            'executed_at' => null,
            'created_at' => $now,
            'updated_at' => $now,
        ]);

        return response()->json([
            'ok' => true,
            'request_id' => $requestId,
            'status' => 'pending',
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

        $lock = DB::transaction(function () use ($id, $context): array {
            $row = DB::table('dsar_requests')
                ->where('id', $id)
                ->where('org_id', $context['org_id'])
                ->lockForUpdate()
                ->first();

            if ($row === null) {
                return ['state' => 'missing'];
            }

            $status = trim((string) ($row->status ?? 'pending'));
            $subjectUserId = (int) ($row->subject_user_id ?? 0);
            $payload = $this->decodeJson($row->payload_json ?? null) ?? [];
            $execution = is_array($payload['execution'] ?? null) ? $payload['execution'] : [];

            $referenceId = trim((string) ($execution['reference_id'] ?? ''));
            if ($referenceId === '') {
                $referenceId = (string) Str::uuid();
            }

            $taskId = trim((string) ($execution['task_id'] ?? ''));
            if ($taskId === '' && SchemaBaseline::hasTable('dsar_request_tasks')) {
                $existingTaskId = DB::table('dsar_request_tasks')
                    ->where('request_id', $id)
                    ->where('domain', 'orchestration')
                    ->where('action', 'execute')
                    ->orderByDesc('created_at')
                    ->value('id');
                $taskId = is_string($existingTaskId) ? trim($existingTaskId) : '';
            }
            if ($taskId === '') {
                $taskId = (string) Str::uuid();
            }

            if ($status === 'pending') {
                if (SchemaBaseline::hasTable('dsar_request_tasks')) {
                    $existing = DB::table('dsar_request_tasks')
                        ->where('request_id', $id)
                        ->where('domain', 'orchestration')
                        ->where('action', 'execute')
                        ->orderByDesc('created_at')
                        ->first();

                    if ($existing === null) {
                        DB::table('dsar_request_tasks')->insert([
                            'id' => $taskId,
                            'request_id' => $id,
                            'org_id' => $context['org_id'],
                            'subject_user_id' => $subjectUserId > 0 ? $subjectUserId : null,
                            'domain' => 'orchestration',
                            'action' => 'execute',
                            'status' => 'pending',
                            'error_code' => null,
                            'stats_json' => json_encode([
                                'queued_by_user_id' => $context['actor_user_id'],
                                'reference_id' => $referenceId,
                            ], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES),
                            'started_at' => null,
                            'finished_at' => null,
                            'created_at' => now(),
                            'updated_at' => now(),
                        ]);
                    } else {
                        $taskId = (string) ($existing->id ?? $taskId);
                    }
                }

                $payload['execution'] = [
                    'reference_id' => $referenceId,
                    'task_id' => $taskId,
                    'queued_by_user_id' => $context['actor_user_id'],
                    'queued_at' => now()->toISOString(),
                ];

                DB::table('dsar_requests')
                    ->where('id', $id)
                    ->where('org_id', $context['org_id'])
                    ->update([
                        'status' => 'running',
                        'executed_by_user_id' => $context['actor_user_id'],
                        'payload_json' => json_encode($payload, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES),
                        'updated_at' => now(),
                    ]);

                $this->appendStatusTransitionAudit(
                    $id,
                    $context['org_id'],
                    $subjectUserId,
                    'pending',
                    'running',
                    $referenceId,
                    $taskId
                );

                return [
                    'state' => 'dispatch',
                    'status' => 'running',
                    'task_id' => $taskId,
                    'reference_id' => $referenceId,
                ];
            }

            return [
                'state' => 'existing',
                'status' => $status !== '' ? $status : 'running',
                'task_id' => $taskId,
                'reference_id' => $referenceId,
            ];
        });

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
            );
        }

        return response()->json([
            'ok' => true,
            'request_id' => $id,
            'status' => $status,
            'task_id' => $taskId !== '' ? $taskId : null,
            'job_reference' => $referenceId !== '' ? $referenceId : null,
        ], 202);
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
