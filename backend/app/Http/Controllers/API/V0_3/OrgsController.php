<?php

namespace App\Http\Controllers\API\V0_3;

use App\Http\Controllers\Controller;
use App\Services\Org\OrganizationService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;

class OrgsController extends Controller
{
    public function __construct(private OrganizationService $orgs)
    {
    }

    /**
     * POST /api/v0.3/orgs
     */
    public function store(Request $request): JsonResponse
    {
        $payload = $request->validate([
            'name' => ['required', 'string', 'max:255'],
        ]);

        $userId = $this->resolveUserId($request);
        if ($userId === null) {
            return response()->json([
                'ok' => false,
                'error' => 'UNAUTHORIZED',
                'message' => 'Missing or invalid fm_token.',
            ], 401);
        }

        $orgId = $this->orgs->createOrg((string) $payload['name'], $userId);
        $org = DB::table('organizations')->where('id', $orgId)->first();

        return response()->json([
            'ok' => true,
            'org' => [
                'org_id' => (int) ($org->id ?? $orgId),
                'name' => (string) ($org->name ?? $payload['name']),
                'owner_user_id' => (int) ($org->owner_user_id ?? $userId),
                'role' => 'owner',
            ],
        ]);
    }

    /**
     * GET /api/v0.3/orgs/me
     */
    public function me(Request $request): JsonResponse
    {
        $userId = $this->resolveUserId($request);
        if ($userId === null) {
            return response()->json([
                'ok' => false,
                'error' => 'UNAUTHORIZED',
                'message' => 'Missing or invalid fm_token.',
            ], 401);
        }

        $rows = DB::table('organization_members as m')
            ->join('organizations as o', 'o.id', '=', 'm.org_id')
            ->where('m.user_id', $userId)
            ->orderBy('m.org_id')
            ->get([
                'm.org_id',
                'm.role',
                'o.name',
                'o.owner_user_id',
            ]);

        $items = [];
        foreach ($rows as $row) {
            $items[] = [
                'org_id' => (int) $row->org_id,
                'name' => (string) $row->name,
                'role' => (string) $row->role,
                'owner_user_id' => (int) $row->owner_user_id,
            ];
        }

        return response()->json([
            'ok' => true,
            'items' => $items,
        ]);
    }

    private function resolveUserId(Request $request): ?int
    {
        $raw = (string) ($request->attributes->get('fm_user_id') ?? $request->attributes->get('user_id') ?? '');
        if ($raw === '' || !preg_match('/^\d+$/', $raw)) {
            return null;
        }

        return (int) $raw;
    }
}
