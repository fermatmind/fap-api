<?php

namespace App\Http\Controllers\API\V0_2\Admin;

use App\Http\Controllers\Controller;
use App\Support\Rbac\PermissionNames;
use App\Support\Rbac\RbacService;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

class AdminEventsController extends Controller
{
    public function index(Request $request)
    {
        $this->assertPermission(PermissionNames::ADMIN_EVENTS_READ);

        if (!Schema::hasTable('events')) {
            return response()->json([
                'ok' => false,
                'error' => 'TABLE_MISSING',
                'message' => 'events missing',
            ], 500);
        }

        $page = max(1, (int) $request->query('page', 1));
        $perPage = max(1, min(200, (int) $request->query('per_page', 50)));

        $eventName = trim((string) $request->query('event_name', ''));
        $attemptId = trim((string) $request->query('attempt_id', ''));
        $shareId = trim((string) $request->query('share_id', ''));
        $packId = trim((string) $request->query('pack_id', ''));
        $dirVersion = trim((string) $request->query('dir_version', ''));

        $query = DB::table('events')->orderByDesc('occurred_at');
        if ($eventName !== '') {
            $query->where('event_name', $eventName);
        }
        if ($attemptId !== '') {
            $query->where('attempt_id', $attemptId);
        }
        if ($shareId !== '') {
            $query->where('share_id', $shareId);
        }
        if ($packId !== '') {
            $query->where('pack_id', $packId);
        }
        if ($dirVersion !== '') {
            $query->where('dir_version', $dirVersion);
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
