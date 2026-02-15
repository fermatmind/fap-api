<?php

use App\Support\Rbac\PermissionNames;

return [
    'token' => env('FAP_ADMIN_TOKEN', ''),
    'panel_enabled' => (bool) env('FAP_ADMIN_PANEL_ENABLED', false),
    'guard' => env('FAP_ADMIN_GUARD', 'admin'),
    'url' => env('FAP_ADMIN_URL', 'http://localhost:18010/ops'),

    'password_policy' => [
        'min_length' => (int) env('OPS_ADMIN_PASSWORD_MIN_LENGTH', 12),
        'require_uppercase' => (bool) env('OPS_ADMIN_PASSWORD_REQUIRE_UPPERCASE', true),
        'require_lowercase' => (bool) env('OPS_ADMIN_PASSWORD_REQUIRE_LOWERCASE', true),
        'require_number' => (bool) env('OPS_ADMIN_PASSWORD_REQUIRE_NUMBER', true),
        'require_symbol' => (bool) env('OPS_ADMIN_PASSWORD_REQUIRE_SYMBOL', true),
        'history_limit' => (int) env('OPS_ADMIN_PASSWORD_HISTORY_LIMIT', 5),
    ],

    'totp' => [
        'enabled' => (bool) env('OPS_ADMIN_TOTP_ENABLED', true),
        'issuer' => env('OPS_ADMIN_TOTP_ISSUER', 'Fermat Ops'),
    ],

    'permissions' => PermissionNames::all(),
    'role_permissions' => PermissionNames::defaultRolePermissions(),
];
