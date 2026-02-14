<?php

return [
    'panel_enabled' => (bool) env('FAP_TENANT_PANEL_ENABLED', false),
    'guard' => env('FAP_TENANT_GUARD', 'tenant'),
    'url' => env('FAP_TENANT_URL', 'http://localhost:18010/tenant'),
];
