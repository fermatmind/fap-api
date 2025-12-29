<?php
// file: backend/config/content_packs.php

return [
    // content_packages 根目录（你项目里通常是 base_path("../content_packages")）
    'root' => base_path('../content_packages'),

    // 最终兜底：如果所有候选都找不到，直接用这个 pack_id（最稳定）
    // 建议填你现成的：MBTI.cn-mainland.zh-CN.v0.2.1-TEST
    'default_pack_id' => env('FAP_DEFAULT_PACK_ID', ''),

    // region 降级链（按顺序尝试）
    'region_fallbacks' => [
        // 例子：港澳台先落 CN_MAINLAND，再落 GLOBAL
        'HK' => ['CN_MAINLAND', 'GLOBAL'],
        'MO' => ['CN_MAINLAND', 'GLOBAL'],
        'TW' => ['CN_MAINLAND', 'GLOBAL'],
        // 未知 region 默认兜底
        '*'  => ['GLOBAL'],
    ],

    // locale 降级（baseLocale）
    // zh-HK -> zh；en-US -> en
    'locale_fallback' => true,

    // 兜底 locale（最终兜底用）
    'default_locale' => env('FAP_DEFAULT_LOCALE', 'en'),

    // 兜底 region（最终兜底用）
    'default_region' => env('FAP_DEFAULT_REGION', 'GLOBAL'),
];