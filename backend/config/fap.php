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
];