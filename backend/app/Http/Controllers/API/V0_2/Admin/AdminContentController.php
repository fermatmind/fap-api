<?php

namespace App\Http\Controllers\API\V0_2\Admin;

use App\Http\Controllers\Controller;
use App\Services\Audit\AuditLogger;
use App\Services\Content\Publisher\ContentProbeService;
use App\Support\Rbac\PermissionNames;
use App\Support\Rbac\RbacService;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

class AdminContentController extends Controller
{
    public function index(Request $request)
    {
        $this->assertPermission(PermissionNames::ADMIN_CONTENT_READ);

        if (!Schema::hasTable('content_pack_releases')) {
            return response()->json([
                'ok' => false,
                'error' => 'TABLE_MISSING',
                'message' => 'content_pack_releases missing',
            ], 500);
        }

        $items = DB::table('content_pack_releases')
            ->orderByDesc('created_at')
            ->limit(50)
            ->get();

        return response()->json([
            'ok' => true,
            'data' => [
                'items' => $items,
            ],
        ]);
    }

    public function probe(Request $request, ContentProbeService $probeService, AuditLogger $audit)
    {
        $this->assertPermission(PermissionNames::ADMIN_CONTENT_PROBE);

        if (!Schema::hasTable('content_pack_releases')) {
            return response()->json([
                'ok' => false,
                'error' => 'TABLE_MISSING',
                'message' => 'content_pack_releases missing',
            ], 500);
        }

        $id = (string) $request->route('id');
        $release = DB::table('content_pack_releases')->where('id', $id)->first();
        if (!$release) {
            return response()->json([
                'ok' => false,
                'error' => 'NOT_FOUND',
                'message' => 'release not found',
            ], 404);
        }

        $region = (string) ($release->region ?? '');
        $locale = (string) ($release->locale ?? '');
        $expectedPackId = (string) ($release->to_pack_id ?? '');

        $baseUrl = $request->getSchemeAndHttpHost();
        $started = microtime(true);
        $result = $probeService->probe($baseUrl, $region, $locale, $expectedPackId);
        $elapsedMs = (int) round((microtime(true) - $started) * 1000);

        $audit->log(
            $request,
            'content_release_probe',
            'ContentRelease',
            $id,
            [
                'region' => $region,
                'locale' => $locale,
                'expected_pack_id' => $expectedPackId,
                'elapsed_ms' => $elapsedMs,
                'probes' => $result['probes'] ?? null,
                'ok' => $result['ok'] ?? false,
                'message' => $result['message'] ?? '',
            ]
        );

        return response()->json([
            'ok' => (bool) ($result['ok'] ?? false),
            'data' => [
                'release_id' => $id,
                'elapsed_ms' => $elapsedMs,
                'probes' => $result['probes'] ?? [],
                'message' => $result['message'] ?? '',
            ],
        ]);
    }

    private function assertPermission(string $permission): void
    {
        $user = auth((string) config('admin.guard', 'admin'))->user();
        if ($user !== null) {
            app(RbacService::class)->assertCan($user, $permission);
        }
    }
}
