<?php

namespace App\Http\Controllers\API\V0_2\Admin;

use App\Http\Controllers\Controller;
use App\Support\Rbac\PermissionNames;
use App\Support\Rbac\RbacService;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

class AdminAuditController extends Controller
{
    public function index(Request $request)
    {
        $this->assertPermission(PermissionNames::ADMIN_AUDIT_READ);

        if (!Schema::hasTable('audit_logs')) {
            return response()->json([
                'ok' => false,
                'error' => 'TABLE_MISSING',
                'message' => 'audit_logs missing',
            ], 500);
        }

        $page = max(1, (int) $request->query('page', 1));
        $perPage = max(1, min(200, (int) $request->query('per_page', 50)));
        $action = trim((string) $request->query('action', ''));
        $actorId = trim((string) $request->query('actor_admin_id', ''));
        $dateFrom = trim((string) $request->query('date_from', ''));
        $dateTo = trim((string) $request->query('date_to', ''));

        $query = DB::table('audit_logs')->orderByDesc('created_at');
        if ($action !== '') {
            $query->where('action', $action);
        }
        if ($actorId !== '') {
            $query->where('actor_admin_id', $actorId);
        }
        if ($dateFrom !== '') {
            $query->where('created_at', '>=', $dateFrom);
        }
        if ($dateTo !== '') {
            $query->where('created_at', '<=', $dateTo);
        }

        $total = (clone $query)->count();
        $items = $query->forPage($page, $perPage)->get();

        return response()->json([
            'ok' => true,
            'data' => [
                'items' => $items,
                'page' => $page,
                'per_page' => $perPage,
                'total' => $total,
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
