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
    | Self-check / strict-assets "known assets" (optional, for maintainability)
    |--------------------------------------------------------------------------
    | 说明：
    | - 这块不会影响线上运行（除非你的 self-check 逻辑主动读取它）
    | - 建议你后续把 self-check 的“known files 列表”改成读取这份配置，避免写死在命令里
    | - 先把 highlights 三个资产名放进来，保证“已知文件”的定义集中管理
    */
    'selfcheck_known_assets' => [
        'report_highlights_pools.json',
        'report_highlights_rules.json',
        'report_highlights_policy.json',
    ],
];