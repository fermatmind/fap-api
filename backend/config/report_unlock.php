<?php

declare(strict_types=1);

return [
    'contract_version' => 'report_unlock.v1',
    'benefit' => 'full_report',
    'scope' => 'attempt',
    'currency' => 'CNY',
    'price_cents' => 499,
    'rollout_scales' => array_values(array_filter(array_map(
        static fn (mixed $value): string => strtoupper(trim((string) $value)),
        explode(',', (string) env('REPORT_UNLOCK_ROLLOUT_SCALES', 'MBTI'))
    ))),
    'supported_locales' => array_values(array_filter(array_map(
        static fn (mixed $value): string => trim((string) $value),
        explode(',', (string) env('REPORT_UNLOCK_SUPPORTED_LOCALES', 'zh-CN'))
    ))),
    'gift_request_ttl_hours' => (int) env('REPORT_UNLOCK_GIFT_REQUEST_TTL_HOURS', 72),
    'sku_by_scale' => [
        'MBTI' => env('REPORT_UNLOCK_MBTI_SKU', 'MBTI_REPORT_FULL'),
    ],
    'providers' => [
        'rewarded_ad' => [
            'available' => (bool) env('REPORT_UNLOCK_REWARDED_AD_AVAILABLE', false),
        ],
        'wechat_mini_virtual' => [
            'available' => (bool) env('REPORT_UNLOCK_WECHAT_MINI_VIRTUAL_AVAILABLE', false),
        ],
        'apple_iap' => [
            'available' => (bool) env('REPORT_UNLOCK_APPLE_IAP_AVAILABLE', false),
        ],
        'gift_purchase' => [
            'available' => (bool) env('REPORT_UNLOCK_GIFT_PURCHASE_AVAILABLE', false),
        ],
    ],
];
