<?php

declare(strict_types=1);

namespace App\Support\Rbac;

use App\Models\AdminUser;
use App\Models\Permission;
use App\Models\Role;
use Illuminate\Support\Facades\Schema;

class RbacService
{
    public function grantRole(AdminUser $user, string $roleName): void
    {
        if (!$this->tablesReady()) {
            return;
        }

        $role = Role::firstOrCreate([
            'name' => $roleName,
        ], [
            'description' => null,
        ]);

        $user->roles()->syncWithoutDetaching([$role->id]);
    }

    public function revokeRole(AdminUser $user, string $roleName): void
    {
        if (!$this->tablesReady()) {
            return;
        }

        $role = Role::where('name', $roleName)->first();
        if ($role === null) {
            return;
        }

        $user->roles()->detach([$role->id]);
    }

    /**
     * @param list<string> $permissionNames
     */
    public function syncRolePermissions(string $roleName, array $permissionNames): void
    {
        if (!$this->tablesReady()) {
            return;
        }

        $role = Role::firstOrCreate([
            'name' => $roleName,
        ], [
            'description' => null,
        ]);

        $permissionIds = [];
        foreach (array_unique($permissionNames) as $name) {
            $name = trim((string) $name);
            if ($name === '') {
                continue;
            }
            $permission = Permission::firstOrCreate([
                'name' => $name,
            ], [
                'description' => null,
            ]);
            $permissionIds[] = $permission->id;
        }

        $role->permissions()->sync($permissionIds);
    }

    public function assertCan(AdminUser $user, string $permissionName): void
    {
        if (!$user->hasPermission($permissionName)) {
            abort(404, 'Not Found');
        }
    }

    private function tablesReady(): bool
    {
        return \App\Support\SchemaBaseline::hasTable('admin_users')
            && \App\Support\SchemaBaseline::hasTable('roles')
            && \App\Support\SchemaBaseline::hasTable('permissions')
            && \App\Support\SchemaBaseline::hasTable('role_user')
            && \App\Support\SchemaBaseline::hasTable('permission_role');
    }
}
