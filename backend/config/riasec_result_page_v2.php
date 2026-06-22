<?php

return [
    'enabled' => env('RIASEC_RESULT_PAGE_V2_ENABLED', false),
    'staging_runtime_enabled' => env('RIASEC_RESULT_PAGE_V2_STAGING_RUNTIME_ENABLED', false),
    'pilot_runtime_enabled' => env('RIASEC_RESULT_PAGE_V2_PILOT_RUNTIME_ENABLED', false),
    'pilot_kill_switch_enabled' => env('RIASEC_RESULT_PAGE_V2_PILOT_KILL_SWITCH_ENABLED', false),
    'allowed_environments' => array_values(array_filter(array_map(
        static fn (string $environment): string => trim($environment),
        explode(',', (string) env('RIASEC_RESULT_PAGE_V2_ALLOWED_ENVIRONMENTS', 'local,testing,staging')),
    ))),
    'pilot_allowed_environments' => array_values(array_filter(array_map(
        static fn (string $environment): string => trim($environment),
        explode(',', (string) env('RIASEC_RESULT_PAGE_V2_PILOT_ALLOWED_ENVIRONMENTS', 'local,testing,staging')),
    ))),
    'pilot_production_allowlist_enabled' => env('RIASEC_RESULT_PAGE_V2_PILOT_PRODUCTION_ALLOWLIST_ENABLED', false),
    'pilot_allowed_form_codes' => array_values(array_filter(array_map(
        static fn (string $formCode): string => trim($formCode),
        explode(',', (string) env('RIASEC_RESULT_PAGE_V2_PILOT_ALLOWED_FORM_CODES', 'riasec_60,riasec_140')),
    ))),
    'pilot_allowed_locales' => array_values(array_filter(array_map(
        static fn (string $locale): string => trim($locale),
        explode(',', (string) env('RIASEC_RESULT_PAGE_V2_PILOT_ALLOWED_LOCALES', 'zh-CN')),
    ))),
    'pilot_access_allowed_attempt_ids' => array_values(array_filter(array_map(
        static fn (string $attemptId): string => trim($attemptId),
        explode(',', (string) env('RIASEC_RESULT_PAGE_V2_PILOT_ALLOWED_ATTEMPT_IDS', '')),
    ))),
    'pilot_access_allowed_user_ids' => array_values(array_filter(array_map(
        static fn (string $userId): string => trim($userId),
        explode(',', (string) env('RIASEC_RESULT_PAGE_V2_PILOT_ALLOWED_USER_IDS', '')),
    ))),
    'pilot_access_allowed_anon_ids' => array_values(array_filter(array_map(
        static fn (string $anonId): string => trim($anonId),
        explode(',', (string) env('RIASEC_RESULT_PAGE_V2_PILOT_ALLOWED_ANON_IDS', '')),
    ))),
    'pilot_access_allowed_org_ids' => array_values(array_filter(array_map(
        static fn (string $orgId): string => trim($orgId),
        explode(',', (string) env('RIASEC_RESULT_PAGE_V2_PILOT_ALLOWED_ORG_IDS', '')),
    ))),
    'production_runtime_enabled' => env('RIASEC_RESULT_PAGE_V2_PRODUCTION_RUNTIME_ENABLED', false),
    'production_rollout_enabled' => env('RIASEC_RESULT_PAGE_V2_PRODUCTION_ROLLOUT_ENABLED', false),
    'production_rollout_manual_approval_granted' => env('RIASEC_RESULT_PAGE_V2_PRODUCTION_MANUAL_APPROVAL_GRANTED', false),
];
