<?php

return [
    // Admin (used by /api/v0.2/admin/*)
    'admin_token' => env('FAP_ADMIN_TOKEN', ''),

    // Content packages
    'content_package_version' => env('FAP_CONTENT_PACKAGE_VERSION', 'MBTI-CN-v0.2.2'),
    'content_packages_dir'    => env('FAP_CONTENT_PACKAGES_DIR', null),

    // Profile / scoring
    'profile_version' => env('FAP_PROFILE_VERSION', 'mbti32-v2.5'),

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
