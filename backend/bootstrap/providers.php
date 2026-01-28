<?php

$filamentAvailable = class_exists(\Filament\PanelProvider::class);

return array_values(array_filter([
    App\Providers\AppServiceProvider::class,
    (env('FAP_ADMIN_PANEL_ENABLED', true) && $filamentAvailable)
        ? App\Providers\Filament\AdminPanelProvider::class
        : null,
]));
