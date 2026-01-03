<?php

return [
    // repo 根目录下的 content_packages
    'packs_root' => base_path('..' . DIRECTORY_SEPARATOR . 'content_packages'),

    // 默认版本：没传 content_package_version 时用它
    'default_versions' => [
        // ✅ 关键：scale=default 时用这里
        'default' => env('FAP_CONTENT_PACKAGE_VERSION', 'MBTI-CN-v0.2.1-TEST'),

        // （可选但建议保留）兼容你以前显式传 scale=MBTI 的情况
        'MBTI' => env('FAP_CONTENT_PACKAGE_VERSION', 'MBTI-CN-v0.2.1-TEST'),
    ],

    // 全局兜底 pack_id（manifest 里 fallback 也可以指定它）
    'global_fallback_pack_id' => 'MBTI.global.en.default',
];