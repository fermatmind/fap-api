<?php

return [
    // Content packages
    'content_package_version' => env('FAP_CONTENT_PACKAGE_VERSION', 'MBTI-CN-v0.2.1-TEST'),
    'content_packages_dir'    => env('FAP_CONTENT_PACKAGES_DIR', null),

    // Profile / scoring
    'profile_version' => env('FAP_PROFILE_VERSION', 'mbti32-v2.5'),

    // Persist raw answers (audit)
    'store_answers_to_storage' => (bool) env('FAP_STORE_ANSWERS_TO_STORAGE', false),

    // Reads debug (turns on extra logs / can force RE explain in reads builder if your code does that)
    'reads_debug' => (bool) env('FAP_READS_DEBUG', false),

    // RuleEngine switches (mapped from your .env keys)
    're_debug'    => (bool) env('FAP_RE_DEBUG', false),
    're_explain'  => (bool) env('RE_EXPLAIN', false),
    're_ctx_tags' => (bool) env('RE_CTX_TAGS', false),
    're_tags'     => (bool) env('RE_TAGS', false),

    // Optional: limit how many tag keys to print when debug is on (0 = unlimited)
    're_tags_debug_n' => (int) env('RE_TAGS_DEBUG_N', 0),

    /*
    |--------------------------------------------------------------------------
    | Self-check known assets (publish-level gate)
    |--------------------------------------------------------------------------
    | 维护说明：
    | - 这里是“发布级门禁”的白名单目录/文件前缀，用于允许自检接受的资产布局
    | - 新增内容包字段/新资产时，要同步更新这里，并在 PR 描述里贴 self-check 通过证据
    | - 只写目录/文件名（相对 pack 根），不要写绝对路径
    */
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
