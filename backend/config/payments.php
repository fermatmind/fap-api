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
    'webhook_max_payload_bytes' => (int) env('PAYMENTS_WEBHOOK_MAX_BYTES', 65536),
];
