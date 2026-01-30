<?php

declare(strict_types=1);

$appUrl = rtrim((string) env('APP_URL', 'http://localhost'), '/');

return [
    'default_region' => env('FAP_DEFAULT_REGION', 'CN_MAINLAND'),

    'regions' => [
        'CN_MAINLAND' => [
            'currency' => 'CNY',
            'default_locale' => 'zh-CN',
            'compliance_flags' => [
                'pipl' => true,
                'gdpr' => false,
            ],
            'legal_urls' => [
                'terms' => env('FAP_CN_TERMS_URL', $appUrl . '/legal/terms'),
                'privacy' => env('FAP_CN_PRIVACY_URL', $appUrl . '/legal/privacy'),
                'refund' => env('FAP_CN_REFUND_URL', $appUrl . '/legal/refund'),
            ],
            'policy_versions' => [
                'terms' => env('FAP_CN_TERMS_VERSION', '2026-01-01'),
                'privacy' => env('FAP_CN_PRIVACY_VERSION', '2026-01-01'),
                'refund' => env('FAP_CN_REFUND_VERSION', '2026-01-01'),
            ],
        ],
        'US' => [
            'currency' => 'USD',
            'default_locale' => 'en-US',
            'compliance_flags' => [
                'pipl' => false,
                'gdpr' => false,
            ],
            'legal_urls' => [
                'terms' => env('FAP_US_TERMS_URL', $appUrl . '/legal/terms'),
                'privacy' => env('FAP_US_PRIVACY_URL', $appUrl . '/legal/privacy'),
                'refund' => env('FAP_US_REFUND_URL', $appUrl . '/legal/refund'),
            ],
            'policy_versions' => [
                'terms' => env('FAP_US_TERMS_VERSION', '2026-01-01'),
                'privacy' => env('FAP_US_PRIVACY_VERSION', '2026-01-01'),
                'refund' => env('FAP_US_REFUND_VERSION', '2026-01-01'),
            ],
        ],
        'EU' => [
            'currency' => 'EUR',
            'default_locale' => 'en-GB',
            'compliance_flags' => [
                'pipl' => false,
                'gdpr' => true,
            ],
            'legal_urls' => [
                'terms' => env('FAP_EU_TERMS_URL', $appUrl . '/legal/terms'),
                'privacy' => env('FAP_EU_PRIVACY_URL', $appUrl . '/legal/privacy'),
                'refund' => env('FAP_EU_REFUND_URL', $appUrl . '/legal/refund'),
            ],
            'policy_versions' => [
                'terms' => env('FAP_EU_TERMS_VERSION', '2026-01-01'),
                'privacy' => env('FAP_EU_PRIVACY_VERSION', '2026-01-01'),
                'refund' => env('FAP_EU_REFUND_VERSION', '2026-01-01'),
            ],
        ],
    ],
];
