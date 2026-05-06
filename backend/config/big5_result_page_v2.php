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
    'public_pilot_enabled' => env('BIG5_RESULT_PAGE_V2_PUBLIC_PILOT_ENABLED', false),
    'public_pilot_surface_scope' => env('BIG5_RESULT_PAGE_V2_PUBLIC_PILOT_SURFACE_SCOPE', 'result_page_only'),
    'public_pilot_allowed_environments' => array_values(array_filter(array_map(
        static fn (string $environment): string => trim($environment),
        explode(',', (string) env('BIG5_RESULT_PAGE_V2_PUBLIC_PILOT_ALLOWED_ENVIRONMENTS', 'local,testing,staging')),
    ))),
    'public_pilot_production_allowlist_enabled' => env('BIG5_RESULT_PAGE_V2_PUBLIC_PILOT_PRODUCTION_ALLOWLIST_ENABLED', false),
    'public_pilot_allowed_scale_codes' => array_values(array_filter(array_map(
        static fn (string $scaleCode): string => strtoupper(trim($scaleCode)),
        explode(',', (string) env('BIG5_RESULT_PAGE_V2_PUBLIC_PILOT_ALLOWED_SCALE_CODES', 'BIG5_OCEAN')),
    ))),
    'public_pilot_allowed_form_codes' => array_values(array_filter(array_map(
        static fn (string $formCode): string => trim($formCode),
        explode(',', (string) env('BIG5_RESULT_PAGE_V2_PUBLIC_PILOT_ALLOWED_FORM_CODES', 'big5_90,big5_120')),
    ))),
    'public_pilot_allowed_locales' => array_values(array_filter(array_map(
        static fn (string $locale): string => trim($locale),
        explode(',', (string) env('BIG5_RESULT_PAGE_V2_PUBLIC_PILOT_ALLOWED_LOCALES', 'zh,zh-CN')),
    ))),
    'public_pilot_rollout_percentage' => (int) env('BIG5_RESULT_PAGE_V2_PUBLIC_PILOT_ROLLOUT_PERCENTAGE', 0),
    'public_pilot_access_allowed_attempt_ids' => array_values(array_filter(array_map(
        static fn (string $attemptId): string => trim($attemptId),
        explode(',', (string) env('BIG5_RESULT_PAGE_V2_PUBLIC_PILOT_ALLOWED_ATTEMPT_IDS', '')),
    ))),
    'public_pilot_access_allowed_user_ids' => array_values(array_filter(array_map(
        static fn (string $userId): string => trim($userId),
        explode(',', (string) env('BIG5_RESULT_PAGE_V2_PUBLIC_PILOT_ALLOWED_USER_IDS', '')),
    ))),
    'public_pilot_access_allowed_anon_ids' => array_values(array_filter(array_map(
        static fn (string $anonId): string => trim($anonId),
        explode(',', (string) env('BIG5_RESULT_PAGE_V2_PUBLIC_PILOT_ALLOWED_ANON_IDS', '')),
    ))),
    'public_pilot_access_allowed_org_ids' => array_values(array_filter(array_map(
        static fn (string $orgId): string => trim($orgId),
        explode(',', (string) env('BIG5_RESULT_PAGE_V2_PUBLIC_PILOT_ALLOWED_ORG_IDS', '')),
    ))),
];
