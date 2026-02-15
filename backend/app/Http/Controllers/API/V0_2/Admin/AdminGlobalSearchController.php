<?php

declare(strict_types=1);

namespace App\Http\Controllers\API\V0_2\Admin;

use App\Http\Controllers\Controller;
use App\Services\Audit\AuditLogger;
use App\Services\Ops\GlobalSearchService;
use App\Support\Rbac\PermissionNames;
use App\Support\Rbac\RbacService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class AdminGlobalSearchController extends Controller
{
    public function __construct(
        private readonly GlobalSearchService $globalSearch,
    ) {
    }

    public function search(Request $request, AuditLogger $audit): JsonResponse
    {
        $this->assertAnyPermission([
            PermissionNames::ADMIN_GLOBAL_SEARCH,
            PermissionNames::ADMIN_OPS_READ,
        ]);

        $query = trim((string) $request->query('q', ''));
        if ($query === '') {
            return response()->json([
                'ok' => true,
                'data' => [
                    'items' => [],
                    'query' => '',
                    'elapsed_ms' => 0,
                ],
            ]);
        }

        $result = $this->globalSearch->search($query);

        $audit->log(
            $request,
            'global_search',
            'GlobalSearch',
            null,
            [
                'query' => $query,
                'result_count' => count($result['items'] ?? []),
            ]
        );

        return response()->json([
            'ok' => true,
            'data' => $result,
        ]);
    }

    private function assertPermission(string $permission): void
    {
        $user = auth((string) config('admin.guard', 'admin'))->user();
        if ($user !== null) {
            app(RbacService::class)->assertCan($user, $permission);
        }
    }

    /**
     * @param list<string> $permissions
     */
    private function assertAnyPermission(array $permissions): void
    {
        $user = auth((string) config('admin.guard', 'admin'))->user();
        if ($user === null || ! method_exists($user, 'hasPermission')) {
            return;
        }

        foreach ($permissions as $permission) {
            if ($user->hasPermission($permission)) {
                return;
            }
        }

        abort(404, 'Not Found');
    }
}
