<?php

$filamentAvailable = class_exists(\Filament\PanelProvider::class);

return array_values(array_filter([
    App\Providers\AppServiceProvider::class,
    (env('FAP_ADMIN_PANEL_ENABLED', false) && $filamentAvailable)
        ? App\Providers\Filament\AdminPanelProvider::class
        : null,
    (env('FAP_TENANT_PANEL_ENABLED', false) && $filamentAvailable)
        ? App\Providers\Filament\TenantPanelProvider::class
        : null,
]));
