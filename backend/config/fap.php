<?php

return [
    'content_package_version' => env('FAP_CONTENT_PACKAGE_VERSION', 'MBTI-CN-v0.2.1-TEST'),
    'content_packages_dir'    => env('FAP_CONTENT_PACKAGES_DIR', null),

    'profile_version' => env('FAP_PROFILE_VERSION', 'mbti32-v2.5'),

    // ✅ 关键开关：写入 storage/app/... 的 answers 原文
    'store_answers_to_storage' => (bool) env('FAP_STORE_ANSWERS_TO_STORAGE', false),

    'reads_debug' => (bool) env('FAP_READS_DEBUG', false),
];