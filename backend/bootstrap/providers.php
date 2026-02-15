<?php

$filamentAvailable = class_exists(\Filament\PanelProvider::class);
$configCachePath = __DIR__ . '/cache/config.php';
$cachedConfig = is_file($configCachePath) ? require $configCachePath : [];
$cachedConfig = is_array($cachedConfig) ? $cachedConfig : [];

$adminConfig = is_array($cachedConfig['admin'] ?? null)
    ? $cachedConfig['admin']
    : require __DIR__ . '/../config/admin.php';
$tenantConfig = is_array($cachedConfig['tenant'] ?? null)
    ? $cachedConfig['tenant']
    : require __DIR__ . '/../config/tenant.php';

$adminPanelEnabled = (bool) ($adminConfig['panel_enabled'] ?? false);
$tenantPanelEnabled = (bool) ($tenantConfig['panel_enabled'] ?? false);

return array_values(array_filter([
    App\Providers\AppServiceProvider::class,
    ($adminPanelEnabled && $filamentAvailable)
        ? App\Providers\Filament\AdminPanelProvider::class
        : null,
    ($tenantPanelEnabled && $filamentAvailable)
        ? App\Providers\Filament\TenantPanelProvider::class
        : null,
]));
