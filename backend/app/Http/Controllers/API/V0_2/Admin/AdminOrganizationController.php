<?php

declare(strict_types=1);

namespace App\Http\Controllers\API\V0_2\Admin;

use App\Http\Controllers\Controller;
use App\Services\Audit\AuditLogger;
use App\Support\Rbac\PermissionNames;
use App\Support\Rbac\RbacService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;

class AdminOrganizationController extends Controller
{
    public function index(Request $request): JsonResponse
    {
        $this->assertAnyPermission([
            PermissionNames::ADMIN_OPS_READ,
            PermissionNames::ADMIN_ORG_MANAGE,
        ]);

        if (!\App\Support\SchemaBaseline::hasTable('organizations')) {
            return response()->json([
                'ok' => false,
                'error_code' => 'TABLE_MISSING',
                'message' => 'organizations missing',
            ], 500);
        }

        $search = trim((string) $request->query('q', ''));

        $query = DB::table('organizations')
            ->select([
                'id',
                'name',
                'status',
                'domain',
                'timezone',
                'locale',
                'created_at',
                'updated_at',
            ])
            ->orderByDesc('updated_at')
            ->orderByDesc('id');

        if ($search !== '') {
            $query->where(function ($builder) use ($search): void {
                $builder->where('name', 'like', '%'.$search.'%')
                    ->orWhere('domain', 'like', '%'.$search.'%');

                if (preg_match('/^\d+$/', $search) === 1) {
                    $builder->orWhere('id', (int) $search);
                }
            });
        }

        $items = $query->limit(100)->get();

        return response()->json([
            'ok' => true,
            'data' => [
                'items' => $items,
            ],
        ]);
    }

    public function store(Request $request, AuditLogger $audit): JsonResponse
    {
        $this->assertPermission(PermissionNames::ADMIN_ORG_MANAGE);

        if (!\App\Support\SchemaBaseline::hasTable('organizations')) {
            return response()->json([
                'ok' => false,
                'error_code' => 'TABLE_MISSING',
                'message' => 'organizations missing',
            ], 500);
        }

        $payload = $request->validate([
            'name' => ['required', 'string', 'max:255'],
            'domain' => ['nullable', 'string', 'max:191'],
            'timezone' => ['nullable', 'string', 'max:64'],
            'locale' => ['nullable', 'string', 'max:16'],
            'status' => ['nullable', 'in:active,suspended'],
        ]);

        $name = trim((string) $payload['name']);
        $domain = isset($payload['domain']) ? trim((string) $payload['domain']) : null;
        $timezone = trim((string) ($payload['timezone'] ?? 'UTC'));
        $locale = trim((string) ($payload['locale'] ?? 'en-US'));
        $status = trim((string) ($payload['status'] ?? 'active'));

        $orgId = (int) DB::table('organizations')->insertGetId([
            'name' => $name,
            'owner_user_id' => 0,
            'status' => $status,
            'domain' => $domain !== '' ? $domain : null,
            'timezone' => $timezone !== '' ? $timezone : 'UTC',
            'locale' => $locale !== '' ? $locale : 'en-US',
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        $row = DB::table('organizations')
            ->where('id', $orgId)
            ->first([
                'id',
                'name',
                'status',
                'domain',
                'timezone',
                'locale',
                'created_at',
                'updated_at',
            ]);

        $audit->log(
            $request,
            'organization_created',
            'Organization',
            (string) $orgId,
            [
                'name' => $name,
                'domain' => $domain,
                'timezone' => $timezone,
                'locale' => $locale,
            ]
        );

        return response()->json([
            'ok' => true,
            'data' => [
                'organization' => $row,
            ],
        ], 201);
    }

    public function importSync(Request $request, AuditLogger $audit): JsonResponse
    {
        $this->assertPermission(PermissionNames::ADMIN_ORG_MANAGE);

        $audit->log(
            $request,
            'organization_import_sync_requested',
            'Organization',
            null,
            [
                'note' => 'placeholder endpoint',
            ]
        );

        return response()->json([
            'ok' => true,
            'data' => [
                'status' => 'accepted',
                'message' => 'Import/sync is not yet automated. Use runbook: docs/04-ops/admin-ops-runbook.md',
            ],
        ], 202);
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
