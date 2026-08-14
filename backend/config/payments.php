<?php

declare(strict_types=1);

$sharedWechatReportProductId = 'FullReport199';
$sharedWechatReportPriceCents = 199;
$mbtiReportSku = 'MBTI_REPORT_FULL_199';

return [
    'provider_priority' => [
        'CN_MAINLAND' => [
            'wechatpay',
            'alipay',
            'billing',
            'stripe',
        ],
        'US' => [
            'lemonsqueezy',
            'stripe',
            'billing',
        ],
        'EU' => [
            'lemonsqueezy',
            'stripe',
            'billing',
        ],
    ],
    'primary_provider_overrides' => array_filter([
        'CN_MAINLAND' => env('PAYMENTS_PRIMARY_PROVIDER_OVERRIDE_CN_MAINLAND', ''),
        'US' => env('PAYMENTS_PRIMARY_PROVIDER_OVERRIDE_US', ''),
        'EU' => env('PAYMENTS_PRIMARY_PROVIDER_OVERRIDE_EU', ''),
    ], static fn (mixed $provider): bool => is_string($provider) && trim($provider) !== ''),

    'fallback_provider' => env('FAP_PAYMENT_FALLBACK_PROVIDER', 'billing'),
    'providers' => [
        'billing' => [
            'enabled' => (bool) env('PAYMENTS_PROVIDER_BILLING_ENABLED', true),
        ],
        'stripe' => [
            'enabled' => (bool) env('PAYMENTS_PROVIDER_STRIPE_ENABLED', true),
        ],
        'lemonsqueezy' => [
            'enabled' => (bool) env('PAYMENTS_PROVIDER_LEMONSQUEEZY_ENABLED', false),
        ],
        'wechatpay' => [
            'enabled' => (bool) env('PAYMENTS_PROVIDER_WECHATPAY_ENABLED', false),
            'auto_enable_when_configured' => (bool) env('PAYMENTS_PROVIDER_WECHATPAY_AUTO_ENABLE_WHEN_CONFIGURED', true),
        ],
        'wechat_mini_virtual' => [
            'enabled' => (bool) env('PAYMENTS_PROVIDER_WECHAT_MINI_VIRTUAL_ENABLED', false),
            'auto_enable_when_configured' => false,
        ],
        'apple_iap' => [
            'enabled' => (bool) env('PAYMENTS_PROVIDER_APPLE_IAP_ENABLED', false),
            'auto_enable_when_configured' => false,
        ],
        'alipay' => [
            'enabled' => (bool) env('PAYMENTS_PROVIDER_ALIPAY_ENABLED', false),
            'auto_enable_when_configured' => (bool) env('PAYMENTS_PROVIDER_ALIPAY_AUTO_ENABLE_WHEN_CONFIGURED', true),
        ],
        'stub' => [
            'enabled' => (bool) env('PAYMENTS_ALLOW_STUB', false),
        ],
    ],
    'allow_stub' => (bool) env('PAYMENTS_ALLOW_STUB', false),
    'webhook_max_payload_bytes' => (int) env('PAYMENTS_WEBHOOK_MAX_BYTES', 262144),
    'signature_tolerance_seconds' => (int) env('PAYMENTS_SIGNATURE_TOLERANCE_SECONDS', 300),
    'stripe' => [
        'webhook_secret' => env('PAYMENTS_STRIPE_WEBHOOK_SECRET', ''),
        'legacy_webhook_secret' => env('PAYMENTS_STRIPE_LEGACY_WEBHOOK_SECRET', ''),
    ],
    'billing' => [
        'webhook_secret' => env('PAYMENTS_BILLING_WEBHOOK_SECRET', ''),
        'legacy_webhook_secret' => env('PAYMENTS_BILLING_LEGACY_WEBHOOK_SECRET', ''),
    ],
    'lemonsqueezy' => [
        'webhook_secret' => env('LEMONSQUEEZY_WEBHOOK_SECRET', ''),
    ],
    'wechat_mini_virtual' => [
        'app_id' => env('WECHAT_MINI_VIRTUAL_APP_ID', ''),
        'app_secret' => env('WECHAT_MINI_VIRTUAL_APP_SECRET', ''),
        'offer_id' => env('WECHAT_MINI_VIRTUAL_OFFER_ID', ''),
        'app_key' => env('WECHAT_MINI_VIRTUAL_APP_KEY', ''),
        'callback_token' => env('WECHAT_MINI_VIRTUAL_CALLBACK_TOKEN', ''),
        'environment' => (int) env('WECHAT_MINI_VIRTUAL_ENVIRONMENT', 1),
        'mode' => env('WECHAT_MINI_VIRTUAL_MODE', 'short_series_goods'),
        'product_id' => $sharedWechatReportProductId,
        'sku' => $mbtiReportSku,
        'price_cents' => $sharedWechatReportPriceCents,
        'http_timeout_seconds' => (int) env('WECHAT_MINI_VIRTUAL_HTTP_TIMEOUT_SECONDS', 8),
        'products' => [
            'BIG5_OCEAN' => [
                'product_id' => $sharedWechatReportProductId,
            ],
            'LOCAL_REPORT' => [
                'product_id' => $sharedWechatReportProductId,
            ],
            'MEMBERSHIP_ANNUAL' => ['product_id' => 'Member365'],
            'MEMBERSHIP_LIFETIME' => ['product_id' => 'MemberForever'],
            'MEMBERSHIP_UPGRADE' => ['product_id' => 'MemberUpgrade999'],
        ],
    ],
    // WeChat Mini Program iOS virtual payment is routed by WeChat to Apple.
    // It uses the same wx.requestVirtualPayment/xpay contract, not merchant-side
    // StoreKit receipts or App Store Server API credentials. iOS supports env=0 only.
    'apple_iap' => [
        'app_id' => env('APPLE_IAP_WECHAT_APP_ID', ''),
        'app_secret' => env('APPLE_IAP_WECHAT_APP_SECRET', ''),
        'offer_id' => env('APPLE_IAP_WECHAT_OFFER_ID', ''),
        'app_key' => env('APPLE_IAP_WECHAT_APP_KEY', ''),
        'callback_token' => env('APPLE_IAP_WECHAT_CALLBACK_TOKEN', ''),
        'environment' => 0,
        'mode' => env('APPLE_IAP_WECHAT_MODE', 'short_series_goods'),
        'product_id' => $sharedWechatReportProductId,
        'sku' => $mbtiReportSku,
        'price_cents' => $sharedWechatReportPriceCents,
        'http_timeout_seconds' => (int) env('APPLE_IAP_WECHAT_HTTP_TIMEOUT_SECONDS', 8),
        'products' => [
            'BIG5_OCEAN' => [
                'product_id' => $sharedWechatReportProductId,
            ],
            'LOCAL_REPORT' => [
                'product_id' => $sharedWechatReportProductId,
            ],
            'MEMBERSHIP_ANNUAL' => ['product_id' => 'Member365'],
            'MEMBERSHIP_LIFETIME' => ['product_id' => 'MemberForever'],
            'MEMBERSHIP_UPGRADE' => ['product_id' => 'MemberUpgrade999'],
        ],
    ],
];
