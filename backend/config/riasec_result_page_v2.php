<?php

return [
    'enabled' => env('RIASEC_RESULT_PAGE_V2_ENABLED', false),
    'staging_runtime_enabled' => env('RIASEC_RESULT_PAGE_V2_STAGING_RUNTIME_ENABLED', false),
    'pilot_runtime_enabled' => env('RIASEC_RESULT_PAGE_V2_PILOT_RUNTIME_ENABLED', false),
    'allowed_environments' => array_values(array_filter(array_map(
        static fn (string $environment): string => trim($environment),
        explode(',', (string) env('RIASEC_RESULT_PAGE_V2_ALLOWED_ENVIRONMENTS', 'local,testing,staging')),
    ))),
    'production_runtime_enabled' => env('RIASEC_RESULT_PAGE_V2_PRODUCTION_RUNTIME_ENABLED', false),
    'production_rollout_enabled' => env('RIASEC_RESULT_PAGE_V2_PRODUCTION_ROLLOUT_ENABLED', false),
    'production_rollout_manual_approval_granted' => env('RIASEC_RESULT_PAGE_V2_PRODUCTION_MANUAL_APPROVAL_GRANTED', false),
];
