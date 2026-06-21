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
    'public_pilot_production_percentage_enabled' => env('BIG5_RESULT_PAGE_V2_PUBLIC_PILOT_PRODUCTION_PERCENTAGE_ENABLED', false),
    'public_pilot_production_max_percentage' => (int) env('BIG5_RESULT_PAGE_V2_PUBLIC_PILOT_PRODUCTION_MAX_PERCENTAGE', 0),
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
    'production_runtime_enabled' => env('BIG5_RESULT_PAGE_V2_PRODUCTION_RUNTIME_ENABLED', false),
    'production_rollout_configured' => env('BIG5_RESULT_PAGE_V2_PRODUCTION_ROLLOUT_CONFIGURED', false),
    'production_import_gate_passed' => env('BIG5_RESULT_PAGE_V2_PRODUCTION_IMPORT_GATE_PASSED', false),
    'production_release_snapshot_id' => env('BIG5_RESULT_PAGE_V2_PRODUCTION_RELEASE_SNAPSHOT_ID', ''),
    'production_approved_release_snapshot_ids' => array_values(array_filter(array_map(
        static fn (string $snapshotId): string => trim($snapshotId),
        explode(',', (string) env('BIG5_RESULT_PAGE_V2_PRODUCTION_APPROVED_RELEASE_SNAPSHOT_IDS', '')),
    ))),
    'production_disabled_release_snapshot_ids' => array_values(array_filter(array_map(
        static fn (string $snapshotId): string => trim($snapshotId),
        explode(',', (string) env('BIG5_RESULT_PAGE_V2_PRODUCTION_DISABLED_RELEASE_SNAPSHOT_IDS', '')),
    ))),
    'production_emergency_disabled' => env('BIG5_RESULT_PAGE_V2_PRODUCTION_EMERGENCY_DISABLED', false),
    'production_rollout_enabled' => env('BIG5_RESULT_PAGE_V2_PRODUCTION_ROLLOUT_ENABLED', false),
    'production_rollout_mode' => env('BIG5_RESULT_PAGE_V2_PRODUCTION_ROLLOUT_MODE', 'disabled'),
    'production_rollout_manual_approval_granted' => env('BIG5_RESULT_PAGE_V2_PRODUCTION_ROLLOUT_MANUAL_APPROVAL_GRANTED', false),
    'production_rollout_percentage' => (int) env('BIG5_RESULT_PAGE_V2_PRODUCTION_ROLLOUT_PERCENTAGE', 0),
    'production_rollout_max_percentage' => (int) env('BIG5_RESULT_PAGE_V2_PRODUCTION_ROLLOUT_MAX_PERCENTAGE', 0),
    'production_rollout_require_tenant_scope' => env('BIG5_RESULT_PAGE_V2_PRODUCTION_ROLLOUT_REQUIRE_TENANT_SCOPE', true),
    'production_rollout_allowed_attempt_ids' => array_values(array_filter(array_map(
        static fn (string $attemptId): string => trim($attemptId),
        explode(',', (string) env('BIG5_RESULT_PAGE_V2_PRODUCTION_ROLLOUT_ALLOWED_ATTEMPT_IDS', '')),
    ))),
    'production_rollout_allowed_user_ids' => array_values(array_filter(array_map(
        static fn (string $userId): string => trim($userId),
        explode(',', (string) env('BIG5_RESULT_PAGE_V2_PRODUCTION_ROLLOUT_ALLOWED_USER_IDS', '')),
    ))),
    'production_rollout_allowed_anon_ids' => array_values(array_filter(array_map(
        static fn (string $anonId): string => trim($anonId),
        explode(',', (string) env('BIG5_RESULT_PAGE_V2_PRODUCTION_ROLLOUT_ALLOWED_ANON_IDS', '')),
    ))),
    'production_rollout_allowed_org_ids' => array_values(array_filter(array_map(
        static fn (string $orgId): string => trim($orgId),
        explode(',', (string) env('BIG5_RESULT_PAGE_V2_PRODUCTION_ROLLOUT_ALLOWED_ORG_IDS', '')),
    ))),
    'production_rollout_allowed_tenant_ids' => array_values(array_filter(array_map(
        static fn (string $tenantId): string => trim($tenantId),
        explode(',', (string) env('BIG5_RESULT_PAGE_V2_PRODUCTION_ROLLOUT_ALLOWED_TENANT_IDS', '')),
    ))),
    'production_rollout_allowed_scale_codes' => array_values(array_filter(array_map(
        static fn (string $scaleCode): string => strtoupper(trim($scaleCode)),
        explode(',', (string) env('BIG5_RESULT_PAGE_V2_PRODUCTION_ROLLOUT_ALLOWED_SCALE_CODES', '')),
    ))),
    'production_rollout_allowed_form_codes' => array_values(array_filter(array_map(
        static fn (string $formCode): string => trim($formCode),
        explode(',', (string) env('BIG5_RESULT_PAGE_V2_PRODUCTION_ROLLOUT_ALLOWED_FORM_CODES', '')),
    ))),
    'production_rollout_allowed_locales' => array_values(array_filter(array_map(
        static fn (string $locale): string => trim($locale),
        explode(',', (string) env('BIG5_RESULT_PAGE_V2_PRODUCTION_ROLLOUT_ALLOWED_LOCALES', '')),
    ))),
];
