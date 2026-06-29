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
        'X-Payment-Recovery-Token',
        'X-Region',
        'X-Fap-Locale',
        'Idempotency-Key',
        'X-Request-Id',
        'X-Requested-With',
        'Accept',
    ],

    'exposed_headers' => [
        'Content-Disposition',
        'X-Gotenberg-Trace',
        'X-Legacy-Mpdf-Fallback',
        'X-Pdf-Error-Stage',
        'X-Pdf-Surface',
        'X-Pdf-Surface-Version',
        'X-Report-Pdf-Engine',
        'X-Request-Id',
    ],

    'max_age' => 86400,

    'supports_credentials' => false,
];
