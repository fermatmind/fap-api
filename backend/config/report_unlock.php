<?php

declare(strict_types=1);

$csv = static fn (string $key, string $default = ''): array => array_values(array_filter(
    array_map(
        static fn (string $value): string => trim($value),
        explode(',', (string) env($key, $default)),
    ),
    static fn (string $value): bool => $value !== '',
));

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
        'BIG5_OCEAN' => env('REPORT_UNLOCK_BIG5_SKU', 'SKU_BIG5_FULL_REPORT_499'),
    ],
    'products' => [
        'MBTI' => [
            'sku' => env('REPORT_UNLOCK_MBTI_SKU', 'MBTI_REPORT_FULL'),
            'benefit_code' => 'MBTI_REPORT_FULL',
            'price_cents' => 499,
            'currency' => 'CNY',
            'scope' => 'attempt',
        ],
        'BIG5_OCEAN' => [
            'sku' => env('REPORT_UNLOCK_BIG5_SKU', 'SKU_BIG5_FULL_REPORT_499'),
            'benefit_code' => 'BIG5_FULL_REPORT',
            'price_cents' => 499,
            'currency' => 'CNY',
            'scope' => 'attempt',
        ],
    ],
    'big5_rollout' => [
        'mode' => env('REPORT_UNLOCK_BIG5_ROLLOUT_MODE', 'disabled'),
        'percentage' => (int) env('REPORT_UNLOCK_BIG5_ROLLOUT_PERCENTAGE', 0),
        'max_percentage' => (int) env('REPORT_UNLOCK_BIG5_ROLLOUT_MAX_PERCENTAGE', 0),
        'emergency_disabled' => (bool) env('REPORT_UNLOCK_BIG5_EMERGENCY_DISABLED', false),
        'allowed_attempt_ids' => $csv('REPORT_UNLOCK_BIG5_ALLOWED_ATTEMPT_IDS'),
        'allowed_user_ids' => $csv('REPORT_UNLOCK_BIG5_ALLOWED_USER_IDS'),
        'allowed_anon_ids' => $csv('REPORT_UNLOCK_BIG5_ALLOWED_ANON_IDS'),
        'allowed_org_ids' => $csv('REPORT_UNLOCK_BIG5_ALLOWED_ORG_IDS'),
        'allowed_tenant_ids' => $csv('REPORT_UNLOCK_BIG5_ALLOWED_TENANT_IDS', '0'),
        'allowed_form_codes' => $csv('REPORT_UNLOCK_BIG5_ALLOWED_FORM_CODES', 'big5_90,big5_120'),
        'allowed_locales' => $csv('REPORT_UNLOCK_BIG5_ALLOWED_LOCALES', 'zh-CN'),
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
