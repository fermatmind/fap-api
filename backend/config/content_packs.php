<?php
// file: backend/config/content_packs.php

declare(strict_types=1);

return [
    // content_packages 根目录（本机/服务器/CI 都建议通过 env 固定）
    // - 本机默认：base_path('../content_packages')
    // - CI 建议：FAP_PACKS_ROOT=../content_packages（相对 backend/ 目录）
    'root' => env('FAP_PACKS_ROOT', base_path('../content_packages')),

    // ✅ CI/服务器建议强约束：默认 pack_id 明确指向你的主包，避免回退到 GLOBAL/en
    // 你现在最稳定的就是：MBTI.cn-mainland.zh-CN.v0.2.1-TEST
    'default_pack_id' => env('FAP_DEFAULT_PACK_ID', 'MBTI.cn-mainland.zh-CN.v0.2.1-TEST'),

    // ✅ 默认 region/locale 也钉死到 CN_MAINLAND/zh-CN（仍保留 fallback 机制）
    'default_region' => env('FAP_DEFAULT_REGION', 'CN_MAINLAND'),
    'default_locale' => env('FAP_DEFAULT_LOCALE', 'zh-CN'),

    // region 降级链（按顺序尝试）
    'region_fallbacks' => [
        // 例子：港澳台先落 CN_MAINLAND，再落 GLOBAL
        'HK' => ['CN_MAINLAND', 'GLOBAL'],
        'MO' => ['CN_MAINLAND', 'GLOBAL'],
        'TW' => ['CN_MAINLAND', 'GLOBAL'],
        // 未知 region 默认兜底（最后仍会走 default_region）
        '*'  => ['GLOBAL'],
    ],

    // locale 降级（baseLocale）
    // zh-HK -> zh；en-US -> en
    'locale_fallback' => true,

    // ✅ CI 严格模式：用于“缺配置就直接 fail”，避免 CI 静默回退
    // 在 CI 里设置：FAP_CI_STRICT=1
    'ci_strict' => (bool) env('FAP_CI_STRICT', false),
];