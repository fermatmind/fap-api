<?php

// file: backend/config/content_packs.php

$defaultPacksRoot = realpath(dirname(base_path()).'/content_packages');
if ($defaultPacksRoot === false || $defaultPacksRoot === '') {
    $defaultPacksRoot = dirname(base_path()).'/content_packages';
}

return [
    // content_packages 根目录（本机/服务器/CI 都建议通过 env 固定）
    // - 本机默认：base_path('../content_packages')
    // - CI 建议：FAP_PACKS_ROOT=../content_packages（相对 backend/ 目录）
    'root' => env('FAP_PACKS_ROOT', $defaultPacksRoot),

    // 内容源驱动：local|s3
    // - local：root 指向 content_packages 根目录
    // - s3：disk + prefix 组合定位内容包根
    'driver' => env('FAP_PACKS_DRIVER', 'local'),
    's3_disk' => env('FAP_S3_DISK', 's3'),
    's3_prefix' => env('FAP_S3_PREFIX', ''),

    // 缓存策略：
    // - driver=local：读取 root
    // - driver=s3：源来自 disk/prefix；实际读取落到 cache_dir（由 PackCache 保证目录存在/更新）
    // - cache_ttl_seconds：到期触发一次 etag 校验与刷新
    'cache_dir' => env('FAP_PACKS_CACHE_DIR', storage_path('app/private/content_packs_cache')),
    'cache_ttl_seconds' => (int) env('FAP_PACKS_CACHE_TTL_SECONDS', 3600),
    'loader_cache_store' => env('CONTENT_LOADER_CACHE_STORE', 'array'),
    'loader_cache_ttl_seconds' => (int) env('CONTENT_LOADER_CACHE_TTL_SECONDS', 300),
    'debug_log' => (bool) env('FAP_PACKS_DEBUG_LOG', false),

    // ✅ CI/服务器建议强约束：默认 pack_id 明确指向你的主包，避免回退到 GLOBAL/en
    // default_pack_id 对应 manifest.json.pack_id（MBTI.cn-mainland.zh-CN.v0.3）
    'default_pack_id' => env('FAP_DEFAULT_PACK_ID', 'MBTI.cn-mainland.zh-CN.v0.3'),
    // demo_pack_id 对应 demo manifest.json.pack_id（default）
    'demo_pack_id' => env('FAP_DEMO_PACK_ID', 'default'),
    // default_dir_version 对应目录名（MBTI-CN-v0.3）
    'default_dir_version' => env('FAP_DEFAULT_DIR_VERSION', 'MBTI-CN-v0.3'),

    // MBTI content source-of-truth:
    // - canonical_dir_versions: current runtime canonical dirs
    // - compat_alias_dir_versions: compat-only aliases that may still be resolved explicitly
    // Canonical truth is defined here, not by scale_identity dir_version v1/v2 naming.
    'canonical_dir_versions' => [
        'MBTI' => env('FAP_DEFAULT_DIR_VERSION', 'MBTI-CN-v0.3'),
    ],
    'compat_alias_dir_versions' => [
        'MBTI' => [
            'MBTI_PERSONALITY_TEST_16_TYPES-CN-v0.3',
        ],
    ],
    'mbti_forms' => [
        'default_form_code' => 'mbti_144',
        'forms' => [
            'mbti_144' => [
                'dir_version' => 'MBTI-CN-v0.3',
                'aliases' => [
                    '144',
                    'standard_144',
                    'pro_144',
                ],
                'public' => [
                    'label' => [
                        'zh' => '144题完整版',
                        'en' => '144-question full version',
                    ],
                    'short_label' => [
                        'zh' => '144题',
                        'en' => '144 questions',
                    ],
                    'estimated_minutes' => 15,
                ],
            ],
            'mbti_93' => [
                'dir_version' => 'MBTI-CN-v0.3-form-93',
                'aliases' => [
                    '93',
                    'standard_93',
                ],
                'public' => [
                    'label' => [
                        'zh' => '93题标准版',
                        'en' => '93-question standard version',
                    ],
                    'short_label' => [
                        'zh' => '93题',
                        'en' => '93 questions',
                    ],
                    'estimated_minutes' => 10,
                ],
            ],
        ],
    ],
    'big5_forms' => [
        'default_form_code' => 'big5_120',
        'forms' => [
            'big5_120' => [
                'dir_version' => 'v1',
                'question_count' => 120,
                'aliases' => [
                    '120',
                    'standard_120',
                ],
                'public' => [
                    'label' => [
                        'zh' => '120题完整版',
                        'en' => '120-question full version',
                    ],
                    'short_label' => [
                        'zh' => '120题',
                        'en' => '120 questions',
                    ],
                    'estimated_minutes' => 15,
                ],
            ],
            'big5_90' => [
                'dir_version' => 'v1-form-90',
                'question_count' => 90,
                'aliases' => [
                    '90',
                    'standard_90',
                ],
                'public' => [
                    'label' => [
                        'zh' => '90题标准版',
                        'en' => '90-question standard version',
                    ],
                    'short_label' => [
                        'zh' => '90题',
                        'en' => '90 questions',
                    ],
                    'estimated_minutes' => 11,
                ],
            ],
        ],
    ],

    // ✅ 默认 region/locale 也钉死到 CN_MAINLAND/zh-CN（仍保留 fallback 机制）
    'default_region' => env('FAP_DEFAULT_REGION', 'CN_MAINLAND'),
    'default_locale' => env('FAP_DEFAULT_LOCALE', 'zh-CN'),

    // region 降级链（按顺序尝试）
    'region_fallbacks' => [
        'US' => ['CN_MAINLAND', 'GLOBAL'],
        // 例子：港澳台先落 CN_MAINLAND，再落 GLOBAL
        'HK' => ['CN_MAINLAND', 'GLOBAL'],
        'MO' => ['CN_MAINLAND', 'GLOBAL'],
        'TW' => ['CN_MAINLAND', 'GLOBAL'],
        // 未知 region 默认兜底（最后仍会走 default_region）
        '*' => ['GLOBAL'],
    ],

    // locale 降级（baseLocale）
    // zh-HK -> zh；en-US -> en
    'locale_fallback' => true,

    // ✅ CI 严格模式：用于“缺配置就直接 fail”，避免 CI 静默回退
    // 在 CI 里设置：FAP_CI_STRICT=1
    'ci_strict' => (bool) env('FAP_CI_STRICT', false),
];
