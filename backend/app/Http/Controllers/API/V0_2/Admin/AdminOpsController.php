<?php

namespace App\Http\Controllers\API\V0_2\Admin;

use App\Http\Controllers\Controller;
use App\Services\Audit\AuditLogger;
use App\Support\CacheKeys;
use App\Support\Rbac\PermissionNames;
use App\Support\Rbac\RbacService;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

class AdminOpsController extends Controller
{
    public function healthzSnapshot(Request $request)
    {
        $this->assertPermission(PermissionNames::ADMIN_OPS_READ);

        if (!\App\Support\SchemaBaseline::hasTable('ops_healthz_snapshots')) {
            return response()->json([
                'ok' => false,
                'error_code' => 'TABLE_MISSING',
                'message' => 'ops_healthz_snapshots missing',
            ], 500);
        }

        $env = (string) $request->query('env', '');
        $query = DB::table('ops_healthz_snapshots')->orderByDesc('created_at');
        if ($env !== '') {
            $query->where('env', $env);
        }

        $row = $query->first();

        return response()->json([
            'ok' => true,
            'data' => $row,
        ]);
    }

    public function invalidateCache(Request $request, AuditLogger $audit)
    {
        $this->assertPermission(PermissionNames::ADMIN_CACHE_INVALIDATE);

        $packId = trim((string) $request->input('pack_id', ''));
        $dirVersion = trim((string) $request->input('dir_version', ''));
        $scope = trim((string) $request->input('scope', ''));
        if ($scope === '') {
            $scope = 'pack';
        }

        if (($scope === 'pack' || $scope === 'all') && ($packId === '' || $dirVersion === '')) {
            return response()->json([
                'ok' => false,
                'error_code' => 'INVALID_PARAMS',
                'message' => 'pack_id and dir_version required for scope=pack|all',
            ], 422);
        }

        $keys = [];
        if ($scope === 'index' || $scope === 'all') {
            $keys[] = CacheKeys::packsIndex();
        }
        if ($scope === 'pack' || $scope === 'all') {
            if ($packId !== '' && $dirVersion !== '') {
                $keys[] = CacheKeys::packManifest($packId, $dirVersion);
                $keys[] = CacheKeys::packQuestions($packId, $dirVersion);
                $keys[] = CacheKeys::mbtiQuestions($packId, $dirVersion);
            }
        }

        foreach ($keys as $key) {
            Cache::forget($key);
        }

        $audit->log(
            $request,
            'cache_invalidate',
            'ContentCache',
            $packId !== '' ? ($packId . ':' . $dirVersion) : null,
            [
                'scope' => $scope,
                'keys' => $keys,
            ]
        );

        return response()->json([
            'ok' => true,
            'data' => [
                'scope' => $scope,
                'keys' => $keys,
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
