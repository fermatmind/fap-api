<?php

declare(strict_types=1);

namespace App\Policies;

use App\Models\AdminUser;
use App\Models\Share;
use App\Policies\Concerns\ResolvesTenantAuthorization;
use App\Support\Rbac\PermissionNames;

class SharePolicy
{
    use ResolvesTenantAuthorization;

    public function viewAny(mixed $user = null): bool
    {
        if ($user instanceof AdminUser) {
            return $user->hasPermission(PermissionNames::ADMIN_OPS_READ);
        }

        return $this->currentOrgId() >= 0;
    }

    public function view(mixed $user, Share $record): bool
    {
        return $this->canRead($user, $this->resolveShareOrgId($record));
    }

    public function update(mixed $user, Share $record): bool
    {
        return $this->canWrite($user, $this->resolveShareOrgId($record));
    }

    public function delete(mixed $user, Share $record): bool
    {
        return $this->canWrite($user, $this->resolveShareOrgId($record));
    }
}
