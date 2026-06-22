<?php

$csv = static fn (string $key, string $default = ''): array => array_values(array_filter(array_map(
    static fn (string $value): string => trim($value),
    explode(',', (string) env($key, $default)),
)));

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
    'production_rollout_configured' => env('RIASEC_RESULT_PAGE_V2_PRODUCTION_ROLLOUT_CONFIGURED', false),
    'production_rollout_manual_approval_granted' => env('RIASEC_RESULT_PAGE_V2_PRODUCTION_MANUAL_APPROVAL_GRANTED', false),
    'production_import_gate_passed' => env('RIASEC_RESULT_PAGE_V2_PRODUCTION_IMPORT_GATE_PASSED', false),
    'production_emergency_disabled' => env('RIASEC_RESULT_PAGE_V2_PRODUCTION_EMERGENCY_DISABLED', false),
    'production_release_snapshot_id' => env('RIASEC_RESULT_PAGE_V2_PRODUCTION_RELEASE_SNAPSHOT_ID', ''),
    'production_approved_release_snapshot_ids' => $csv('RIASEC_RESULT_PAGE_V2_PRODUCTION_APPROVED_SNAPSHOT_IDS'),
    'production_disabled_release_snapshot_ids' => $csv('RIASEC_RESULT_PAGE_V2_PRODUCTION_DISABLED_SNAPSHOT_IDS'),
    'production_rollout_mode' => env('RIASEC_RESULT_PAGE_V2_PRODUCTION_ROLLOUT_MODE', 'disabled'),
    'production_rollout_percentage' => (int) env('RIASEC_RESULT_PAGE_V2_PRODUCTION_ROLLOUT_PERCENTAGE', 0),
    'production_rollout_max_percentage' => (int) env('RIASEC_RESULT_PAGE_V2_PRODUCTION_ROLLOUT_MAX_PERCENTAGE', 0),
    'production_rollout_allowed_attempt_ids' => $csv('RIASEC_RESULT_PAGE_V2_PRODUCTION_ALLOWED_ATTEMPT_IDS'),
    'production_rollout_allowed_user_ids' => $csv('RIASEC_RESULT_PAGE_V2_PRODUCTION_ALLOWED_USER_IDS'),
    'production_rollout_allowed_anon_ids' => $csv('RIASEC_RESULT_PAGE_V2_PRODUCTION_ALLOWED_ANON_IDS'),
    'production_rollout_allowed_org_ids' => $csv('RIASEC_RESULT_PAGE_V2_PRODUCTION_ALLOWED_ORG_IDS'),
    'production_rollout_require_tenant_scope' => env('RIASEC_RESULT_PAGE_V2_PRODUCTION_REQUIRE_TENANT_SCOPE', true),
    'production_rollout_allowed_tenant_ids' => $csv('RIASEC_RESULT_PAGE_V2_PRODUCTION_ALLOWED_TENANT_IDS'),
    'production_rollout_allowed_scale_codes' => $csv('RIASEC_RESULT_PAGE_V2_PRODUCTION_ALLOWED_SCALE_CODES', 'RIASEC'),
    'production_rollout_allowed_form_codes' => $csv('RIASEC_RESULT_PAGE_V2_PRODUCTION_ALLOWED_FORM_CODES', 'riasec_60,riasec_140'),
    'production_rollout_allowed_locales' => $csv('RIASEC_RESULT_PAGE_V2_PRODUCTION_ALLOWED_LOCALES', 'zh-CN'),
    'production_post_deploy_smoke_required' => env('RIASEC_RESULT_PAGE_V2_PRODUCTION_POST_DEPLOY_SMOKE_REQUIRED', true),
    'production_post_deploy_smoke_procedure_id' => env('RIASEC_RESULT_PAGE_V2_PRODUCTION_POST_DEPLOY_SMOKE_PROCEDURE_ID', ''),
];
