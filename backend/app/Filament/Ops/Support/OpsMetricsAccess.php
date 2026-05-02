<?php

declare(strict_types=1);

namespace App\Filament\Ops\Support;

use App\Support\Rbac\PermissionNames;

final class OpsMetricsAccess
{
    public static function canViewCommerceMetrics(): bool
    {
        return self::hasAnyPermission([
            PermissionNames::ADMIN_OWNER,
            PermissionNames::ADMIN_FINANCE_WRITE,
            PermissionNames::ADMIN_MENU_COMMERCE,
        ]);
    }

    /**
     * @param  list<string>  $permissions
     */
    private static function hasAnyPermission(array $permissions): bool
    {
        $guard = (string) config('admin.guard', 'admin');
        $user = auth($guard)->user();

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
