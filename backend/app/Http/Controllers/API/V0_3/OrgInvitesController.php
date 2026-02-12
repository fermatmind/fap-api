<?php

namespace App\Http\Controllers\API\V0_3;

use App\Http\Controllers\Controller;
use App\Services\Org\InviteService;
use App\Services\Org\Rbac;
use App\Support\OrgContext;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class OrgInvitesController extends Controller
{
    public function __construct(
        private InviteService $invites,
        private Rbac $rbac,
        private OrgContext $orgContext,
    ) {
    }

    /**
     * POST /api/v0.3/orgs/{org_id}/invites
     */
    public function store(Request $request, string $org_id): JsonResponse
    {
        $payload = $request->validate([
            'email' => ['required', 'email', 'max:255'],
        ]);

        $orgId = (int) $org_id;
        if ($orgId <= 0) {
            return $this->orgNotFound();
        }

        if ($this->orgContext->orgId() !== $orgId) {
            return $this->orgNotFound();
        }

        $userId = $this->resolveUserId($request);
        if ($userId === null) {
            return response()->json([
                'ok' => false,
                'error_code' => 'UNAUTHORIZED',
                'message' => 'Missing or invalid fm_token.',
            ], 401);
        }

        $this->rbac->assertRoleIn($orgId, $userId, ['owner', 'admin']);

        $expiresAt = now()->addDays(7);
        $invite = $this->invites->createInvite($orgId, (string) $payload['email'], $expiresAt);

        return response()->json([
            'ok' => true,
            'invite' => $invite,
        ]);
    }

    /**
     * POST /api/v0.3/orgs/invites/accept
     */
    public function accept(Request $request): JsonResponse
    {
        $payload = $request->validate([
            'token' => ['required', 'string', 'max:255'],
        ]);

        $userId = $this->resolveUserId($request);
        if ($userId === null) {
            return response()->json([
                'ok' => false,
                'error_code' => 'UNAUTHORIZED',
                'message' => 'Missing or invalid fm_token.',
            ], 401);
        }

        $res = $this->invites->acceptInvite((string) $payload['token'], $userId);
        if (!($res['ok'] ?? false)) {
            $error = (string) ($res['error_code'] ?? $res['error'] ?? 'INVITE_INVALID');
            $status = match ($error) {
                'INVITE_NOT_FOUND' => 404,
                'INVITE_EXPIRED' => 410,
                'INVITE_ALREADY_ACCEPTED' => 409,
                default => 400,
            };

            return response()->json($res, $status);
        }

        return response()->json($res);
    }

    private function resolveUserId(Request $request): ?int
    {
        $raw = (string) ($request->attributes->get('fm_user_id') ?? $request->attributes->get('user_id') ?? '');
        if ($raw === '' || !preg_match('/^\d+$/', $raw)) {
            return null;
        }

        return (int) $raw;
    }

    private function orgNotFound(): JsonResponse
    {
        return response()->json([
            'ok' => false,
            'error_code' => 'ORG_NOT_FOUND',
            'message' => 'org not found.',
        ], 404);
    }
}
