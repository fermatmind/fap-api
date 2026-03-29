<?php

declare(strict_types=1);

namespace App\Services\Ops;

use App\Support\Rbac\PermissionNames;
use Illuminate\Database\Eloquent\Builder as EloquentBuilder;
use Illuminate\Database\Query\Builder as QueryBuilder;
use Illuminate\Support\Facades\DB;

final class OrgVisibilityResolver
{
    /**
     * @return list<string>
     */
    private function selectablePermissions(): array
    {
        return PermissionNames::all();
    }

    public function canSelectOrganizations(mixed $user): bool
    {
        return $this->hasAnyPermission($user, $this->selectablePermissions());
    }

    public function canManageOrganizations(mixed $user): bool
    {
        return $this->hasAnyPermission($user, [
            PermissionNames::ADMIN_OWNER,
            PermissionNames::ADMIN_ORG_MANAGE,
        ]);
    }

    public function applyTableVisibility(QueryBuilder $query, mixed $user, string $qualifiedIdColumn = 'id'): QueryBuilder
    {
        if (! $this->canSelectOrganizations($user)) {
            return $query->whereRaw('1 = 0');
        }

        return $query;
    }

    public function applyEloquentVisibility(EloquentBuilder $query, mixed $user, string $qualifiedIdColumn = 'id'): EloquentBuilder
    {
        if (! $this->canSelectOrganizations($user)) {
            return $query->whereRaw('1 = 0');
        }

        return $query;
    }

    public function visibleOrganizationsQuery(mixed $user): QueryBuilder
    {
        return $this->applyTableVisibility(
            DB::table('organizations')->select(['id', 'name', 'status', 'domain', 'updated_at']),
            $user
        );
    }

    public function isVisibleOrganization(mixed $user, int $orgId): bool
    {
        if ($orgId <= 0) {
            return false;
        }

        return $this->visibleOrganizationsQuery($user)
            ->where('id', $orgId)
            ->exists();
    }

    /**
     * @param  list<string>  $permissions
     */
    private function hasAnyPermission(mixed $user, array $permissions): bool
    {
        if (! is_object($user) || ! method_exists($user, 'hasPermission')) {
            return false;
        }

        foreach ($permissions as $permission) {
            if ($user->hasPermission($permission)) {
                return true;
            }
        }

        return false;
    }
}
