<?php

declare(strict_types=1);

namespace App\Filament\Ops\Support;

use App\Support\Rbac\PermissionNames;

final class ContentAccess
{
    public static function canRead(): bool
    {
        return self::hasAnyPermission([
            PermissionNames::ADMIN_CONTENT_READ,
            PermissionNames::ADMIN_CONTENT_WRITE,
            PermissionNames::ADMIN_CONTENT_RELEASE,
            PermissionNames::ADMIN_CONTENT_PUBLISH,
            PermissionNames::ADMIN_OWNER,
        ]);
    }

    public static function canWrite(): bool
    {
        return self::hasAnyPermission([
            PermissionNames::ADMIN_CONTENT_WRITE,
            PermissionNames::ADMIN_CONTENT_PUBLISH,
            PermissionNames::ADMIN_OWNER,
        ]);
    }

    public static function canRelease(): bool
    {
        return self::hasAnyPermission([
            PermissionNames::ADMIN_CONTENT_RELEASE,
            PermissionNames::ADMIN_CONTENT_PUBLISH,
            PermissionNames::ADMIN_OWNER,
        ]);
    }

    public static function canReleaseContentPacks(): bool
    {
        return self::hasAnyPermission([
            PermissionNames::ADMIN_CONTENT_RELEASE,
            PermissionNames::ADMIN_OWNER,
        ]);
    }

    public static function canReview(): bool
    {
        return self::hasAnyPermission([
            PermissionNames::ADMIN_APPROVAL_REVIEW,
            PermissionNames::ADMIN_CONTENT_RELEASE,
            PermissionNames::ADMIN_CONTENT_PUBLISH,
            PermissionNames::ADMIN_OWNER,
        ]);
    }

    public static function canOpenWorkflow(): bool
    {
        return self::canWrite() || self::canReview();
    }

    public static function canAssignOwner(): bool
    {
        return self::canWrite() || self::canReview();
    }

    public static function canAssignReviewer(): bool
    {
        return self::canReview();
    }

    public static function isOwner(): bool
    {
        return self::hasAnyPermission([
            PermissionNames::ADMIN_OWNER,
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
