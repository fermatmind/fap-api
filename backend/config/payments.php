<?php

declare(strict_types=1);

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
        'product_id' => env('WECHAT_MINI_VIRTUAL_PRODUCT_ID', ''),
        'sku' => env('WECHAT_MINI_VIRTUAL_SKU', 'MBTI_REPORT_FULL'),
        'price_cents' => (int) env('WECHAT_MINI_VIRTUAL_PRICE_CENTS', 499),
        'http_timeout_seconds' => (int) env('WECHAT_MINI_VIRTUAL_HTTP_TIMEOUT_SECONDS', 8),
    ],
];
