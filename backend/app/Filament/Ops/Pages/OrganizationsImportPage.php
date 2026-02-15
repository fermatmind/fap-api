<?php

declare(strict_types=1);

namespace App\Filament\Ops\Pages;

use App\Support\Rbac\PermissionNames;
use Filament\Pages\Page;

class OrganizationsImportPage extends Page
{
    protected static ?string $navigationIcon = 'heroicon-o-arrow-down-tray';

    protected static ?string $navigationGroup = 'Admin';

    protected static ?string $navigationLabel = 'Import Organizations';

    protected static ?int $navigationSort = 6;

    protected static ?string $slug = 'organizations-import';

    protected static string $view = 'filament.ops.pages.organizations-import-page';

    public static function canAccess(): bool
    {
        $guard = (string) config('admin.guard', 'admin');
        $user = auth($guard)->user();

        if (! is_object($user) || ! method_exists($user, 'hasPermission')) {
            return false;
        }

        return $user->hasPermission(PermissionNames::ADMIN_OWNER)
            || $user->hasPermission(PermissionNames::ADMIN_ORG_MANAGE);
    }
}
