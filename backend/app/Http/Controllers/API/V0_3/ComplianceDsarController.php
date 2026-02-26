<?php

declare(strict_types=1);

namespace App\Http\Controllers\API\V0_3;

use App\Http\Controllers\Controller;
use App\Services\Attempts\UserDataLifecycleService;
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

    public function __construct(
        private readonly UserDataLifecycleService $userDataLifecycleService,
    ) {}

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
            if ($status === 'running') {
                return ['state' => 'running', 'row' => $row];
            }
            if ($status === 'done') {
                return ['state' => 'done', 'row' => $row];
            }

            DB::table('dsar_requests')
                ->where('id', $id)
                ->where('org_id', $context['org_id'])
                ->update([
                    'status' => 'running',
                    'executed_by_user_id' => $context['actor_user_id'],
                    'updated_at' => now(),
                ]);

            $fresh = DB::table('dsar_requests')
                ->where('id', $id)
                ->where('org_id', $context['org_id'])
                ->first();

            return ['state' => 'ready', 'row' => $fresh];
        });

        $state = (string) ($lock['state'] ?? '');
        if ($state === 'missing') {
            return $this->requestNotFound();
        }
        if ($state === 'running') {
            return response()->json([
                'ok' => true,
                'request_id' => $id,
                'status' => 'running',
            ], 202);
        }
        if ($state === 'done') {
            /** @var object $row */
            $row = $lock['row'];

            return response()->json([
                'ok' => true,
                'request' => $this->mapRequestRow($row),
            ]);
        }

        /** @var object $row */
        $row = $lock['row'];
        $mode = trim((string) ($row->mode ?? 'hybrid_anonymize'));
        $subjectUserId = (int) ($row->subject_user_id ?? 0);

        $result = $this->userDataLifecycleService->process(
            $context['org_id'],
            $subjectUserId,
            $mode,
            [
                'actor_user_id' => $context['actor_user_id'],
                'request_id' => (string) ($row->id ?? $id),
                'reason' => (string) ($row->reason ?? 'user_dsar_request'),
            ]
        );

        $status = ($result['ok'] ?? false) === true ? 'done' : 'failed';
        $now = now();

        DB::table('dsar_requests')
            ->where('id', $id)
            ->where('org_id', $context['org_id'])
            ->update([
                'status' => $status,
                'result_json' => json_encode($result, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES),
                'executed_by_user_id' => $context['actor_user_id'],
                'executed_at' => $now,
                'updated_at' => $now,
            ]);

        $updated = $this->findRequestRow($id, $context['org_id']);

        return response()->json([
            'ok' => ($result['ok'] ?? false) === true,
            'request' => $updated !== null ? $this->mapRequestRow($updated) : null,
            'result' => $result,
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
}
