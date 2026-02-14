<?php

declare(strict_types=1);

namespace App\Policies;

use App\Models\AdminApproval;
use App\Models\AdminUser;
use App\Policies\Concerns\ResolvesTenantAuthorization;
use App\Support\Rbac\PermissionNames;

class AdminApprovalPolicy
{
    use ResolvesTenantAuthorization;

    public function viewAny(mixed $user = null): bool
    {
        return $user instanceof AdminUser
            && $user->hasPermission(PermissionNames::ADMIN_OPS_READ);
    }

    public function view(mixed $user, AdminApproval $record): bool
    {
        return $this->canRead($user, (int) $record->org_id);
    }

    public function create(mixed $user = null): bool
    {
        return $user instanceof AdminUser
            && $user->hasPermission(PermissionNames::ADMIN_OPS_WRITE);
    }

    public function request(mixed $user, int $orgId): bool
    {
        if (! $this->sameOrg($orgId)) {
            return false;
        }

        return $user instanceof AdminUser
            && $user->hasPermission(PermissionNames::ADMIN_OPS_WRITE);
    }

    public function approve(mixed $user, AdminApproval $record): bool
    {
        if (! $this->sameOrg((int) $record->org_id)) {
            return false;
        }

        return $user instanceof AdminUser
            && $user->hasPermission(PermissionNames::ADMIN_APPROVAL_REVIEW);
    }

    public function reject(mixed $user, AdminApproval $record): bool
    {
        return $this->approve($user, $record);
    }
}
