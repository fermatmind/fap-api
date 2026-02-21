<?php

declare(strict_types=1);

namespace App\Http\Controllers\API\V0_3;

use App\Http\Controllers\Controller;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;

final class BigFiveOpsController extends Controller
{
    public function latest(Request $request, int $org_id): JsonResponse
    {
        $region = trim((string) $request->query('region', 'CN_MAINLAND'));
        if ($region === '') {
            $region = 'CN_MAINLAND';
        }
        $locale = trim((string) $request->query('locale', 'zh-CN'));
        if ($locale === '') {
            $locale = 'zh-CN';
        }
        $action = strtolower(trim((string) $request->query('action', '')));
        if (! in_array($action, ['publish', 'rollback'], true)) {
            $action = '';
        }

        $query = DB::table('content_pack_releases')
            ->where('region', $region)
            ->where('locale', $locale)
            ->where(function ($q): void {
                $q->where('to_pack_id', 'BIG5_OCEAN')
                    ->orWhere('from_pack_id', 'BIG5_OCEAN');
            });

        if ($action !== '') {
            $query->where('action', $action);
        }

        $row = $query
            ->orderByDesc('created_at')
            ->orderByDesc('updated_at')
            ->first();

        if (! $row) {
            return response()->json([
                'ok' => false,
                'error_code' => 'RELEASE_NOT_FOUND',
                'message' => 'release not found.',
            ], 404);
        }

        return response()->json([
            'ok' => true,
            'org_id' => $org_id,
            'item' => $this->mapReleaseRow($row),
        ]);
    }

    public function latestAudits(Request $request, int $org_id): JsonResponse
    {
        $region = trim((string) $request->query('region', 'CN_MAINLAND'));
        if ($region === '') {
            $region = 'CN_MAINLAND';
        }
        $locale = trim((string) $request->query('locale', 'zh-CN'));
        if ($locale === '') {
            $locale = 'zh-CN';
        }
        $action = strtolower(trim((string) $request->query('action', '')));
        if (! in_array($action, ['publish', 'rollback'], true)) {
            $action = '';
        }
        $limit = (int) $request->query('limit', 20);
        if ($limit < 1) {
            $limit = 1;
        }
        if ($limit > 100) {
            $limit = 100;
        }

        $query = DB::table('content_pack_releases')
            ->where('region', $region)
            ->where('locale', $locale)
            ->where(function ($q): void {
                $q->where('to_pack_id', 'BIG5_OCEAN')
                    ->orWhere('from_pack_id', 'BIG5_OCEAN');
            });

        if ($action !== '') {
            $query->where('action', $action);
        }

        $row = $query
            ->orderByDesc('created_at')
            ->orderByDesc('updated_at')
            ->first();

        if (! $row) {
            return response()->json([
                'ok' => false,
                'error_code' => 'RELEASE_NOT_FOUND',
                'message' => 'release not found.',
            ], 404);
        }

        $audits = DB::table('audit_logs')
            ->whereIn('action', ['big5_pack_publish', 'big5_pack_rollback'])
            ->where('target_id', (string) ($row->id ?? ''))
            ->orderByDesc('id')
            ->limit($limit)
            ->get();

        $auditItems = [];
        foreach ($audits as $audit) {
            $auditItems[] = $this->mapAuditRow($audit);
        }

        return response()->json([
            'ok' => true,
            'org_id' => $org_id,
            'item' => $this->mapReleaseRow($row),
            'count' => count($auditItems),
            'audits' => $auditItems,
        ]);
    }

    public function releases(Request $request, int $org_id): JsonResponse
    {
        $limit = (int) $request->query('limit', 20);
        if ($limit < 1) {
            $limit = 1;
        }
        if ($limit > 100) {
            $limit = 100;
        }

        $region = trim((string) $request->query('region', 'CN_MAINLAND'));
        if ($region === '') {
            $region = 'CN_MAINLAND';
        }
        $locale = trim((string) $request->query('locale', 'zh-CN'));
        if ($locale === '') {
            $locale = 'zh-CN';
        }

        $action = strtolower(trim((string) $request->query('action', '')));
        if (! in_array($action, ['publish', 'rollback'], true)) {
            $action = '';
        }

        $query = DB::table('content_pack_releases')
            ->where('region', $region)
            ->where('locale', $locale)
            ->where(function ($q): void {
                $q->where('to_pack_id', 'BIG5_OCEAN')
                    ->orWhere('from_pack_id', 'BIG5_OCEAN');
            });

        if ($action !== '') {
            $query->where('action', $action);
        }

        $rows = $query
            ->orderByDesc('created_at')
            ->orderByDesc('updated_at')
            ->limit($limit)
            ->get();

        $items = [];
        foreach ($rows as $row) {
            $items[] = $this->mapReleaseRow($row);
        }

        return response()->json([
            'ok' => true,
            'org_id' => $org_id,
            'count' => count($items),
            'items' => $items,
        ]);
    }

    public function audits(Request $request, int $org_id): JsonResponse
    {
        $limit = (int) $request->query('limit', 20);
        if ($limit < 1) {
            $limit = 1;
        }
        if ($limit > 100) {
            $limit = 100;
        }

        $action = trim((string) $request->query('action', ''));
        if (! in_array($action, ['big5_pack_publish', 'big5_pack_rollback'], true)) {
            $action = '';
        }
        $result = strtolower(trim((string) $request->query('result', '')));
        if (! in_array($result, ['success', 'failed'], true)) {
            $result = '';
        }
        $releaseId = trim((string) $request->query('release_id', ''));

        $query = DB::table('audit_logs')
            ->whereIn('action', ['big5_pack_publish', 'big5_pack_rollback']);

        if ($action !== '') {
            $query->where('action', $action);
        }
        if ($result !== '') {
            $query->where('result', $result);
        }
        if ($releaseId !== '') {
            $query->where('target_id', $releaseId);
        }

        $rows = $query
            ->orderByDesc('id')
            ->limit($limit)
            ->get();

        $items = [];
        foreach ($rows as $row) {
            $items[] = $this->mapAuditRow($row);
        }

        return response()->json([
            'ok' => true,
            'org_id' => $org_id,
            'count' => count($items),
            'items' => $items,
        ]);
    }

    public function audit(Request $request, int $org_id, string $audit_id): JsonResponse
    {
        $audit_id = trim($audit_id);
        if ($audit_id === '') {
            return response()->json([
                'ok' => false,
                'error_code' => 'AUDIT_NOT_FOUND',
                'message' => 'audit not found.',
            ], 404);
        }

        $row = DB::table('audit_logs')
            ->where('id', $audit_id)
            ->whereIn('action', ['big5_pack_publish', 'big5_pack_rollback'])
            ->first();

        if (! $row) {
            return response()->json([
                'ok' => false,
                'error_code' => 'AUDIT_NOT_FOUND',
                'message' => 'audit not found.',
            ], 404);
        }

        $release = null;
        $targetType = (string) ($row->target_type ?? '');
        $targetId = (string) ($row->target_id ?? '');
        if ($targetType === 'content_pack_release' && $targetId !== '') {
            $releaseRow = DB::table('content_pack_releases')
                ->where('id', $targetId)
                ->where(function ($q): void {
                    $q->where('to_pack_id', 'BIG5_OCEAN')
                        ->orWhere('from_pack_id', 'BIG5_OCEAN');
                })
                ->first();
            if ($releaseRow) {
                $release = $this->mapReleaseRow($releaseRow);
            }
        }

        return response()->json([
            'ok' => true,
            'org_id' => $org_id,
            'item' => $this->mapAuditRow($row),
            'release' => $release,
        ]);
    }

    public function release(Request $request, int $org_id, string $release_id): JsonResponse
    {
        $release_id = trim($release_id);
        if ($release_id === '') {
            return response()->json([
                'ok' => false,
                'error_code' => 'RELEASE_NOT_FOUND',
                'message' => 'release not found.',
            ], 404);
        }

        $row = DB::table('content_pack_releases')
            ->where('id', $release_id)
            ->where(function ($q): void {
                $q->where('to_pack_id', 'BIG5_OCEAN')
                    ->orWhere('from_pack_id', 'BIG5_OCEAN');
            })
            ->first();

        if (! $row) {
            return response()->json([
                'ok' => false,
                'error_code' => 'RELEASE_NOT_FOUND',
                'message' => 'release not found.',
            ], 404);
        }

        $audits = DB::table('audit_logs')
            ->whereIn('action', ['big5_pack_publish', 'big5_pack_rollback'])
            ->where('target_id', $release_id)
            ->orderByDesc('id')
            ->limit(20)
            ->get();

        $auditItems = [];
        foreach ($audits as $audit) {
            $auditItems[] = $this->mapAuditRow($audit);
        }

        return response()->json([
            'ok' => true,
            'org_id' => $org_id,
            'item' => $this->mapReleaseRow($row),
            'audits' => $auditItems,
        ]);
    }

    /**
     * @return array<string,mixed>
     */
    private function mapReleaseRow(object $row): array
    {
        return [
            'release_id' => (string) ($row->id ?? ''),
            'action' => (string) ($row->action ?? ''),
            'status' => (string) ($row->status ?? ''),
            'message' => (string) ($row->message ?? ''),
            'dir_alias' => (string) ($row->dir_alias ?? ''),
            'region' => (string) ($row->region ?? ''),
            'locale' => (string) ($row->locale ?? ''),
            'from_pack_id' => (string) ($row->from_pack_id ?? ''),
            'to_pack_id' => (string) ($row->to_pack_id ?? ''),
            'from_version_id' => (string) ($row->from_version_id ?? ''),
            'to_version_id' => (string) ($row->to_version_id ?? ''),
            'created_by' => (string) ($row->created_by ?? ''),
            'created_at' => (string) ($row->created_at ?? ''),
            'updated_at' => (string) ($row->updated_at ?? ''),
            'evidence' => [
                'manifest_hash' => (string) ($row->manifest_hash ?? ''),
                'compiled_hash' => (string) ($row->compiled_hash ?? ''),
                'content_hash' => (string) ($row->content_hash ?? ''),
                'norms_version' => (string) ($row->norms_version ?? ''),
                'git_sha' => (string) ($row->git_sha ?? ''),
            ],
        ];
    }

    /**
     * @return array<string,mixed>
     */
    private function mapAuditRow(object $row): array
    {
        return [
            'id' => (int) ($row->id ?? 0),
            'action' => (string) ($row->action ?? ''),
            'result' => (string) ($row->result ?? ''),
            'reason' => (string) ($row->reason ?? ''),
            'target_type' => (string) ($row->target_type ?? ''),
            'target_id' => (string) ($row->target_id ?? ''),
            'request_id' => (string) ($row->request_id ?? ''),
            'created_at' => (string) ($row->created_at ?? ''),
            'meta' => $this->decodeJson((string) ($row->meta_json ?? '')),
        ];
    }

    /**
     * @return array<string,mixed>
     */
    private function decodeJson(string $json): array
    {
        $decoded = json_decode($json, true);
        return is_array($decoded) ? $decoded : [];
    }
}
