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
];
