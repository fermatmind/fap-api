<?php

use App\Support\Rbac\PermissionNames;

return [
    'token' => env('FAP_ADMIN_TOKEN', ''),
    'panel_enabled' => (bool) env('FAP_ADMIN_PANEL_ENABLED', false),
    'guard' => env('FAP_ADMIN_GUARD', 'admin'),
    'url' => env('FAP_ADMIN_URL', 'http://localhost:18010/ops'),

    'permissions' => PermissionNames::all(),
    'role_permissions' => PermissionNames::defaultRolePermissions(),
];
