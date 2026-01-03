<?php

// file: backend/config/content.php

return [
    /**
     * content_packages 根目录
     *
     * 你的部署结构是：
     * /var/www/fap-api/releases/.../repo/backend   (Laravel base_path)
     * /var/www/fap-api/releases/.../repo/content_packages
     *
     * 所以 packs_root 必须指向 base_path('../content_packages')
     */
    'packs_root' => base_path('..' . DIRECTORY_SEPARATOR . 'content_packages'),

    /**
     * 默认版本映射：没传 content_package_version 时用它
     *
     * ContentPackResolver::resolve($scaleCode, ...) 里会取：
     * config("content.default_versions.$scaleCode")
     *
     * 你当前线上走的是 scaleCode=default（目录也是 content_packages/default/...）
     * 所以这里必须有 'default' 这个 key，否则就会报：
     * "No default content_package_version configured for scale=default."
     */
    'default_versions' => [
        // ✅ 线上默认 scale=default 时用这个
        'default' => env('FAP_CONTENT_PACKAGE_VERSION', 'MBTI-CN-v0.2.1-TEST'),

        // ✅ 可选：兼容旧路径/旧调用（如果历史上有人传 scale=MBTI）
        'MBTI' => env('FAP_CONTENT_PACKAGE_VERSION', 'MBTI-CN-v0.2.1-TEST'),
    ],

    /**
     * 全局兜底 pack_id（如果你未来要做 manifest fallback/global fallback 可用）
     * 目前不影响你这次修复
     */
    'global_fallback_pack_id' => env('FAP_GLOBAL_FALLBACK_PACK_ID', 'MBTI.global.en.default'),
];