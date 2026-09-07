<?php

declare(strict_types=1);

return [
    'channel' => env('SEO_COUNCIL_NOTIFICATION_CHANNEL', 'webhook'),
    'recipient' => env('SEO_COUNCIL_MAIL_RECIPIENT', ''),
    'ops_url' => env('SEO_COUNCIL_MAIL_OPS_URL', ''),
    // Staging never falls back to production MAIL_* credentials.
    'staging_smtp' => [
        'transport' => 'smtp',
        'scheme' => env('SEO_COUNCIL_MAIL_SCHEME', 'smtps'),
        'host' => env('SEO_COUNCIL_MAIL_HOST', ''),
        'port' => (int) env('SEO_COUNCIL_MAIL_PORT', 465),
        'username' => env('SEO_COUNCIL_MAIL_USERNAME', ''),
        'password' => env('SEO_COUNCIL_MAIL_PASSWORD', ''),
        'local_domain' => env('SEO_COUNCIL_MAIL_EHLO_DOMAIN', ''),
    ],
    'staging_from' => env('SEO_COUNCIL_MAIL_FROM_ADDRESS', ''),
];
