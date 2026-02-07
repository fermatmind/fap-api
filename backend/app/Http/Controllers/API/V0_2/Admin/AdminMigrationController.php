<?php

declare(strict_types=1);

namespace App\Http\Controllers\API\V0_2\Admin;

use App\Http\Controllers\Controller;
use App\Services\Database\MigrationObservabilityService;
use App\Support\Rbac\PermissionNames;
use App\Support\Rbac\RbacService;
use Illuminate\Http\Request;

class AdminMigrationController extends Controller
{
    public function observability(Request $request, MigrationObservabilityService $service)
    {
        $this->assertPermission(PermissionNames::ADMIN_OPS_READ);

        $limit = (int) $request->query('limit', 20);

        return response()->json([
            'ok' => true,
            'data' => $service->snapshot($limit),
        ]);
    }

    public function rollbackPreview(Request $request, MigrationObservabilityService $service)
    {
        $this->assertPermission(PermissionNames::ADMIN_OPS_READ);

        $steps = (int) $request->query('steps', 1);

        return response()->json([
            'ok' => true,
            'data' => $service->rollbackPreview($steps),
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
