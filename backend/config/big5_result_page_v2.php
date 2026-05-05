<?php

return [
    'enabled' => env('BIG5_RESULT_PAGE_V2_ENABLED', false),
    'pilot_runtime_enabled' => env('BIG5_RESULT_PAGE_V2_PILOT_RUNTIME_ENABLED', false),
    'pilot_allowed_environments' => array_values(array_filter(array_map(
        static fn (string $environment): string => trim($environment),
        explode(',', (string) env('BIG5_RESULT_PAGE_V2_PILOT_ALLOWED_ENVIRONMENTS', 'local,testing,staging')),
    ))),
    'pilot_production_allowlist_enabled' => env('BIG5_RESULT_PAGE_V2_PILOT_PRODUCTION_ALLOWLIST_ENABLED', false),
    'pilot_access_allowed_attempt_ids' => array_values(array_filter(array_map(
        static fn (string $attemptId): string => trim($attemptId),
        explode(',', (string) env('BIG5_RESULT_PAGE_V2_PILOT_ALLOWED_ATTEMPT_IDS', '')),
    ))),
    'pilot_access_allowed_user_ids' => array_values(array_filter(array_map(
        static fn (string $userId): string => trim($userId),
        explode(',', (string) env('BIG5_RESULT_PAGE_V2_PILOT_ALLOWED_USER_IDS', '')),
    ))),
    'pilot_access_allowed_anon_ids' => array_values(array_filter(array_map(
        static fn (string $anonId): string => trim($anonId),
        explode(',', (string) env('BIG5_RESULT_PAGE_V2_PILOT_ALLOWED_ANON_IDS', '')),
    ))),
    'pilot_access_allowed_org_ids' => array_values(array_filter(array_map(
        static fn (string $orgId): string => trim($orgId),
        explode(',', (string) env('BIG5_RESULT_PAGE_V2_PILOT_ALLOWED_ORG_IDS', '')),
    ))),
];
