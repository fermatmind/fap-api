<?php

declare(strict_types=1);

return [
    'provider_priority' => [
        'CN_MAINLAND' => [
            'billing',
            'stripe',
            'wechatpay',
            'alipay',
        ],
        'US' => [
            'stripe',
            'billing',
            'paypal',
        ],
        'EU' => [
            'stripe',
            'billing',
        ],
    ],

    'fallback_provider' => env('FAP_PAYMENT_FALLBACK_PROVIDER', 'billing'),
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
];
