<?php

return [
    // Admin (used by /api/v0.2/admin/*)
    'admin_token' => env('FAP_ADMIN_TOKEN', ''),

    // Content packages
    'content_package_version' => env('FAP_CONTENT_PACKAGE_VERSION', 'MBTI-CN-v0.2.1-TEST'),
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
        'report_cards_traits.json',
        'report_cards_strengths.json',
        'report_highlights_templates.json',
        'report_overrides.json',
        'report_rules.json',
        'report_recommended_reads.json',
        'report_share_templates.json',
        'type_profiles.json',
        'assets/',
    ],
];