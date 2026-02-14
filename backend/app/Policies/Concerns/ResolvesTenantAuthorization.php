<?php

declare(strict_types=1);

namespace App\Policies\Concerns;

use App\Models\AdminUser;
use App\Models\Attempt;
use App\Models\Share;
use App\Support\OrgContext;
use App\Support\Rbac\PermissionNames;

trait ResolvesTenantAuthorization
{
    protected function currentOrgId(): int
    {
        return (int) app(OrgContext::class)->orgId();
    }

    protected function sameOrg(int $recordOrgId): bool
    {
        $ctxOrg = $this->currentOrgId();
        if ($ctxOrg <= 0) {
            return $recordOrgId === 0;
        }

        return $ctxOrg === $recordOrgId;
    }

    protected function canRead(mixed $user, int $recordOrgId): bool
    {
        if (!$this->sameOrg($recordOrgId)) {
            return false;
        }

        if ($user instanceof AdminUser) {
            return $user->hasPermission(PermissionNames::ADMIN_OPS_READ);
        }

        return true;
    }

    protected function canWrite(mixed $user, int $recordOrgId): bool
    {
        if (!$this->sameOrg($recordOrgId)) {
            return false;
        }

        return $user instanceof AdminUser
            && $user->hasPermission(PermissionNames::ADMIN_OPS_WRITE);
    }

    protected function resolveShareOrgId(Share $share): int
    {
        return (int) (Attempt::withoutGlobalScopes()
            ->where('id', (string) $share->attempt_id)
            ->value('org_id') ?? 0);
    }
}
