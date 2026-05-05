<?php

return [
    'enabled' => env('BIG5_RESULT_PAGE_V2_ENABLED', false),
    'pilot_runtime_enabled' => env('BIG5_RESULT_PAGE_V2_PILOT_RUNTIME_ENABLED', false),
    'pilot_allowed_environments' => array_values(array_filter(array_map(
        static fn (string $environment): string => trim($environment),
        explode(',', (string) env('BIG5_RESULT_PAGE_V2_PILOT_ALLOWED_ENVIRONMENTS', 'local,testing,staging')),
    ))),
    'pilot_production_allowlist_enabled' => env('BIG5_RESULT_PAGE_V2_PILOT_PRODUCTION_ALLOWLIST_ENABLED', false),
];
