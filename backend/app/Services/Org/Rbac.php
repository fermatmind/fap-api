<?php

namespace App\Services\Org;

use Illuminate\Http\Exceptions\HttpResponseException;

final class Rbac
{
    public function __construct(private MembershipService $memberships)
    {
    }

    public function assertRoleIn(int $orgId, int $userId, array $roles): string
    {
        $role = $this->memberships->getRole($orgId, $userId);
        if ($role === null || !in_array($role, $roles, true)) {
            throw new HttpResponseException(response()->json([
                'ok' => false,
                'error' => 'ORG_NOT_FOUND',
                'message' => 'org not found.',
            ], 404));
        }

        return $role;
    }
}
