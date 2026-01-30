<?php

declare(strict_types=1);

return [
    'provider_priority' => [
        'CN_MAINLAND' => [
            'wechatpay',
            'alipay',
            'stub',
        ],
        'US' => [
            'stripe',
            'paypal',
        ],
        'EU' => [
            'stripe',
        ],
    ],

    'fallback_provider' => env('FAP_PAYMENT_FALLBACK_PROVIDER', 'stub'),
];
