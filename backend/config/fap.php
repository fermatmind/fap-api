<?php

return [
    // Admin (used by /api/v0.2/admin/*)
    'admin_token' => env('FAP_ADMIN_TOKEN', ''),

    // Content packages
    'content_package_version' => env('FAP_CONTENT_PACKAGE_VERSION', 'MBTI-CN-v0.2.2'),
    'content_packages_dir'    => env('FAP_CONTENT_PACKAGES_DIR', null),

    // Profile / scoring
    'profile_version' => env('FAP_PROFILE_VERSION', 'mbti32-v2.5'),

    // Assessment driver registry (scales_registry.assessment_driver)
    'assessment_drivers' => [
        'mbti' => App\Services\Assessment\Drivers\MbtiDriver::class,
        'simple_score' => App\Services\Assessment\Drivers\SimpleScoreDriver::class,
        'simple_score_demo' => App\Services\Assessment\Drivers\SimpleScoreDriver::class,
        'demo_answers' => App\Services\Assessment\Drivers\SimpleScoreDriver::class,
        'generic_likert' => App\Services\Assessment\Drivers\GenericLikertDriver::class,
        'likert' => App\Services\Assessment\Drivers\GenericLikertDriver::class,
        'iq_test' => App\Services\Assessment\Drivers\IqTestDriver::class,
        'iq_raven' => App\Services\Assessment\Drivers\IqTestDriver::class,
        'iq' => App\Services\Assessment\Drivers\IqTestDriver::class,
        'generic_scoring' => App\Services\Assessment\Drivers\GenericScoringDriver::class,
        'big5' => App\Services\Assessment\Drivers\GenericScoringDriver::class,
    ],

    // Persist raw answers (audit)
    'store_answers_to_storage' => (bool) env('FAP_STORE_ANSWERS_TO_STORAGE', false),

    // Reads debug
    'reads_debug' => (bool) env('FAP_READS_DEBUG', false),

    // RuleEngine switches
    're_debug'    => (bool) env('FAP_RE_DEBUG', false),
    're_explain'  => (bool) env('RE_EXPLAIN', false),
    're_ctx_tags' => (bool) env('RE_CTX_TAGS', false),
    're_tags'     => (bool) env('RE_TAGS', false),

    're_tags_debug_n' => (int) env('RE_TAGS_DEBUG_N', 0),

    'rate_limits' => [
        'api_public_per_minute' => (int) env('FAP_RATE_LIMIT_PUBLIC_PER_MINUTE', 120),
        'api_auth_per_minute' => (int) env('FAP_RATE_LIMIT_AUTH_PER_MINUTE', 30),
        'api_attempt_submit_per_minute' => (int) env('FAP_RATE_LIMIT_ATTEMPT_SUBMIT_PER_MINUTE', 20),
        'api_webhook_per_minute' => (int) env('FAP_RATE_LIMIT_WEBHOOK_PER_MINUTE', 60),
        'bypass_in_test_env' => (bool) env('FAP_RATE_LIMIT_BYPASS_IN_TEST_ENV', true),
    ],

    'events' => [
        'max_top_keys' => (int) env('FAP_EVENTS_MAX_TOP_KEYS', 200),
        'max_depth' => (int) env('FAP_EVENTS_MAX_DEPTH', 4),
        'max_list_length' => (int) env('FAP_EVENTS_MAX_LIST_LENGTH', 50),
        'max_string_length' => (int) env('FAP_EVENTS_MAX_STRING_LENGTH', 2048),
        'max_payload_bytes' => (int) env('FAP_EVENTS_MAX_PAYLOAD_BYTES', 131072),
        'ingest_token' => env('EVENT_INGEST_TOKEN', ''),
    ],

    'selfcheck_known_assets' => [
        'manifest.json',
        'version.json',
        'questions.json',
        'scoring_spec.json',
        'quality_checks.json',
        'norms.json',
        'content_graph.json',
        'identity_layers.json',
        'interpretation_spec.json',
        'commercial_spec.json',
        'ai_spec.json',
        'telemetry_spec.json',
        'audit_spec.json',
        'report_borderline_notes.json',
        'report_borderline_templates.json',
        'report_cards_career.json',
        'report_cards_fallback_career.json',
        'report_cards_fallback_growth.json',
        'report_cards_fallback_relationships.json',
        'report_cards_fallback_stress_recovery.json',
        'report_cards_fallback_traits.json',
        'report_cards_growth.json',
        'report_cards_relationships.json',
        'report_cards_stress_recovery.json',
        'report_cards_traits.json',
        'report_highlights.json',
        'report_highlights_policy.json',
        'report_highlights_pools.json',
        'report_highlights_rules.json',
        'report_highlights_templates.json',
        'report_identity_cards.json',
        'report_overrides.json',
        'report_recommended_reads.json',
        'report_roles.json',
        'report_rules.json',
        'report_section_policies.json',
        'report_select_rules.json',
        'report_share_templates.json',
        'report_strategies.json',
        'type_profiles.json',
        'assets/',
        'meta/',
        'reads/',
        'role_cards/',
        'share_templates/',
        'strategy_cards/',
    ],
];
