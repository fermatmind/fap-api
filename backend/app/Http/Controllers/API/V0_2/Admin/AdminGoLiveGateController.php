<?php

declare(strict_types=1);

namespace App\Http\Controllers\API\V0_2\Admin;

use App\Http\Controllers\Controller;
use App\Services\Ops\GoLiveGateService;
use App\Support\Rbac\PermissionNames;
use App\Support\Rbac\RbacService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class AdminGoLiveGateController extends Controller
{
    public function __construct(
        private readonly GoLiveGateService $gate,
    ) {
    }

    public function show(Request $request): JsonResponse
    {
        $this->assertAnyPermission([
            PermissionNames::ADMIN_GO_LIVE_GATE,
            PermissionNames::ADMIN_OWNER,
        ]);

        return response()->json([
            'ok' => true,
            'data' => $this->gate->snapshot(),
        ]);
    }

    public function run(Request $request): JsonResponse
    {
        $this->assertAnyPermission([
            PermissionNames::ADMIN_GO_LIVE_GATE,
            PermissionNames::ADMIN_OWNER,
        ]);

        return response()->json([
            'ok' => true,
            'data' => $this->gate->run(),
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
