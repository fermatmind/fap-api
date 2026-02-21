<?php

declare(strict_types=1);

namespace App\Http\Controllers\API\V0_3;

use App\Http\Controllers\Controller;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;

final class BigFiveOpsController extends Controller
{
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
            $items[] = [
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

        return response()->json([
            'ok' => true,
            'org_id' => $org_id,
            'count' => count($items),
            'items' => $items,
        ]);
    }
}

