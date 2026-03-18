<?php

return [
    'paths' => ['api/*'],

    'allowed_methods' => ['GET', 'POST', 'PUT', 'PATCH', 'DELETE', 'OPTIONS'],

    'allowed_origins' => [
        'https://www.fermatmind.com',
        'https://fermatmind.com',
    ],

    'allowed_origins_patterns' => [],

    'allowed_headers' => [
        'Authorization',
        'Content-Type',
        'X-Org-Id',
        'X-FM-Org-Id',
        'X-Anon-Id',
        'X-Region',
        'X-Fap-Locale',
        'Idempotency-Key',
        'X-Request-Id',
        'X-Requested-With',
        'Accept',
    ],

    'exposed_headers' => [
        'X-Request-Id',
    ],

    'max_age' => 86400,

    'supports_credentials' => false,
];
