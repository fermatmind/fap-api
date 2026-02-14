<?php

declare(strict_types=1);

namespace App\Policies;

use App\Models\AdminUser;
use App\Models\BenefitGrant;
use App\Policies\Concerns\ResolvesTenantAuthorization;
use App\Support\Rbac\PermissionNames;

class BenefitGrantPolicy
{
    use ResolvesTenantAuthorization;

    public function viewAny(mixed $user = null): bool
    {
        if ($user instanceof AdminUser) {
            return $user->hasPermission(PermissionNames::ADMIN_OPS_READ);
        }

        return $this->currentOrgId() >= 0;
    }

    public function view(mixed $user, BenefitGrant $record): bool
    {
        return $this->canRead($user, (int) $record->org_id);
    }

    public function update(mixed $user, BenefitGrant $record): bool
    {
        return $this->canWrite($user, (int) $record->org_id);
    }

    public function delete(mixed $user, BenefitGrant $record): bool
    {
        return $this->canWrite($user, (int) $record->org_id);
    }
}
