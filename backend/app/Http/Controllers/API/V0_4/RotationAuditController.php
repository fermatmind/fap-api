<?php

declare(strict_types=1);

namespace App\Http\Controllers\API\V0_4;

use App\Http\Controllers\Controller;
use App\Support\OrgContext;
use App\Support\SchemaBaseline;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;

final class RotationAuditController extends Controller
{
    public function __construct(
        private readonly OrgContext $orgContext,
    ) {}

    public function index(Request $request, string $org_id): JsonResponse
    {
        $orgId = $this->resolveOrgId($org_id);
        if (! $this->isOrgAccessible($orgId)) {
            return $this->orgNotFound();
        }

        if (! SchemaBaseline::hasTable('rotation_audits')) {
            return $this->rotationAuditsNotReady();
        }

        $payload = $request->validate([
            'scope' => ['nullable', 'string', 'max:64'],
            'result' => ['nullable', 'string', 'max:32'],
            'batch' => ['nullable', 'string', 'max:64'],
            'key_version' => ['nullable', 'integer', 'min:1', 'max:65535'],
            'limit' => ['nullable', 'integer', 'min:1', 'max:200'],
        ]);

        $keepDays = max(1, (int) config('storage_retention.rotation_audits.keep_days', 180));
        $policy = trim((string) config('storage_retention.rotation_audits.policy', 'ttl'));
        $cutoffAt = now()->subDays($keepDays);
        $limit = (int) ($payload['limit'] ?? 50);

        $query = DB::table('rotation_audits')
            ->where('org_id', $orgId);

        if (SchemaBaseline::hasColumn('rotation_audits', 'created_at')) {
            $query->where('created_at', '>=', $cutoffAt);
        }

        $scope = trim((string) ($payload['scope'] ?? ''));
        if ($scope !== '') {
            $query->where('scope', $scope);
        }

        $result = trim((string) ($payload['result'] ?? ''));
        if ($result !== '') {
            $query->where('result', $result);
        }

        $batch = trim((string) ($payload['batch'] ?? ''));
        if ($batch !== '') {
            $query->where('batch_ref', $batch);
        }

        $keyVersion = (int) ($payload['key_version'] ?? 0);
        if ($keyVersion > 0) {
            $query->where('key_version', $keyVersion);
        }

        $rows = $query
            ->orderByDesc('created_at')
            ->limit($limit)
            ->get();

        $items = [];
        foreach ($rows as $row) {
            $items[] = $this->mapRow($row);
        }

        return response()->json([
            'ok' => true,
            'retention' => [
                'policy' => $policy === '' ? 'ttl' : $policy,
                'keep_days' => $keepDays,
                'cutoff_at' => $cutoffAt->toIso8601String(),
            ],
            'items' => $items,
        ]);
    }

    public function show(Request $request, string $org_id, string $id): JsonResponse
    {
        $orgId = $this->resolveOrgId($org_id);
        if (! $this->isOrgAccessible($orgId)) {
            return $this->orgNotFound();
        }

        if (! SchemaBaseline::hasTable('rotation_audits')) {
            return $this->rotationAuditsNotReady();
        }

        $keepDays = max(1, (int) config('storage_retention.rotation_audits.keep_days', 180));
        $cutoffAt = now()->subDays($keepDays);

        $query = DB::table('rotation_audits')
            ->where('org_id', $orgId)
            ->where('id', $id);

        if (SchemaBaseline::hasColumn('rotation_audits', 'created_at')) {
            $query->where('created_at', '>=', $cutoffAt);
        }

        $row = $query->first();
        if ($row === null) {
            return response()->json([
                'ok' => false,
                'error_code' => 'ROTATION_AUDIT_NOT_FOUND',
                'message' => 'rotation audit not found.',
            ], 404);
        }

        return response()->json([
            'ok' => true,
            'audit' => $this->mapRow($row),
        ]);
    }

    private function resolveOrgId(string $orgId): int
    {
        $orgId = trim($orgId);
        if ($orgId === '' || preg_match('/^\d+$/', $orgId) !== 1) {
            return 0;
        }

        return (int) $orgId;
    }

    private function isOrgAccessible(int $orgId): bool
    {
        return $orgId > 0 && $this->orgContext->orgId() === $orgId;
    }

    private function orgNotFound(): JsonResponse
    {
        return response()->json([
            'ok' => false,
            'error_code' => 'ORG_NOT_FOUND',
            'message' => 'org not found.',
        ], 404);
    }

    private function rotationAuditsNotReady(): JsonResponse
    {
        return response()->json([
            'ok' => false,
            'error_code' => 'ROTATION_AUDIT_NOT_READY',
            'message' => 'rotation audits table is not ready.',
        ], 503);
    }

    /**
     * @return array<string,mixed>
     */
    private function mapRow(object $row): array
    {
        return [
            'id' => (string) ($row->id ?? ''),
            'org_id' => (int) ($row->org_id ?? 0),
            'actor' => $this->nullableString($row->actor ?? null),
            'actor_user_id' => $this->nullableInt($row->actor_user_id ?? null),
            'scope' => (string) ($row->scope ?? ''),
            'key_version' => (int) ($row->key_version ?? 0),
            'batch' => $this->nullableString($row->batch_ref ?? null),
            'result' => (string) ($row->result ?? ''),
            'meta' => $this->decodeJson($row->meta_json ?? null),
            'created_at' => $this->nullableString($row->created_at ?? null),
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

        if (! is_string($value)) {
            return null;
        }

        $value = trim($value);
        if ($value === '') {
            return null;
        }

        $decoded = json_decode($value, true);

        return is_array($decoded) ? $decoded : null;
    }

    private function nullableString(mixed $value): ?string
    {
        if ($value === null) {
            return null;
        }

        $value = trim((string) $value);

        return $value === '' ? null : $value;
    }

    private function nullableInt(mixed $value): ?int
    {
        if ($value === null || $value === '') {
            return null;
        }

        $normalized = (int) $value;

        return $normalized > 0 ? $normalized : null;
    }
}
