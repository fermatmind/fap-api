<?php

namespace App\Services\Org;

use App\Exceptions\Api\ApiProblemException;

final class Rbac
{
    public function __construct(private MembershipService $memberships) {}

    public function assertRoleIn(int $orgId, int $userId, array $roles): string
    {
        $role = $this->memberships->getRole($orgId, $userId);
        if ($role === null || ! in_array($role, $roles, true)) {
            throw new ApiProblemException(404, 'ORG_NOT_FOUND', 'org not found.');
        }

        return $role;
    }
}
