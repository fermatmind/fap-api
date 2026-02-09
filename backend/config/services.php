<?php

return [

    /*
    |--------------------------------------------------------------------------
    | Third Party Services
    |--------------------------------------------------------------------------
    |
    | This file is for storing the credentials for third party services such
    | as Mailgun, Postmark, AWS and more. This file provides the de facto
    | location for this type of information, allowing packages to have
    | a conventional file to locate the various service credentials.
    |
    */

    'postmark' => [
        'key' => env('POSTMARK_API_KEY'),
    ],

    'resend' => [
        'key' => env('RESEND_API_KEY'),
    ],

    'ses' => [
        'key' => env('AWS_ACCESS_KEY_ID'),
        'secret' => env('AWS_SECRET_ACCESS_KEY'),
        'region' => env('AWS_DEFAULT_REGION', 'us-east-1'),
    ],

    'slack' => [
        'notifications' => [
            'bot_user_oauth_token' => env('SLACK_BOT_USER_OAUTH_TOKEN'),
            'channel' => env('SLACK_BOT_USER_DEFAULT_CHANNEL'),
        ],
    ],

    'stripe' => [
        'webhook_secret' => env('STRIPE_WEBHOOK_SECRET'),
        'webhook_tolerance_seconds' => (int) env('STRIPE_WEBHOOK_TOLERANCE_SECONDS', env('STRIPE_WEBHOOK_TOLERANCE', 300)),
        'webhook_tolerance' => (int) env('STRIPE_WEBHOOK_TOLERANCE', 300),
    ],

    'billing' => [
        'webhook_secret' => env('BILLING_WEBHOOK_SECRET', ''),
        'webhook_secret_optional_envs' => array_values(array_filter(array_map(
            'trim',
            explode(',', (string) env('BILLING_WEBHOOK_SECRET_OPTIONAL_ENVS', 'local,testing,ci'))
        ))),
        'webhook_tolerance_seconds' => (int) env('BILLING_WEBHOOK_TOLERANCE_SECONDS', env('BILLING_WEBHOOK_TOLERANCE', 300)),
        'webhook_tolerance' => (int) env('BILLING_WEBHOOK_TOLERANCE', 300),
        'allow_legacy_signature' => (bool) env('BILLING_WEBHOOK_ALLOW_LEGACY_SIGNATURE', false), // kept for backward compatibility
    ],

    'payment_webhook' => [
        'lock_ttl_seconds' => (int) env('PAYMENT_WEBHOOK_LOCK_TTL_SECONDS', 10),
        'lock_block_seconds' => (int) env('PAYMENT_WEBHOOK_LOCK_BLOCK_SECONDS', 5),
        'success_event_types' => [
            'stripe' => array_values(array_filter(array_map(
                static fn ($v) => strtolower(trim((string) $v)),
                explode(',', (string) env(
                    'PAYMENT_WEBHOOK_STRIPE_SUCCESS_EVENTS',
                    'payment_succeeded,payment_intent.succeeded,charge.succeeded,checkout.session.completed,invoice.payment_succeeded'
                ))
            ))),
            'billing' => array_values(array_filter(array_map(
                static fn ($v) => strtolower(trim((string) $v)),
                explode(',', (string) env(
                    'PAYMENT_WEBHOOK_BILLING_SUCCESS_EVENTS',
                    'payment_succeeded,payment.success,payment_completed,paid'
                ))
            ))),
        ],
    ],

    'integrations' => [
        'webhook_tolerance_seconds' => (int) env('INTEGRATIONS_WEBHOOK_TOLERANCE_SECONDS', 300),
        'allow_unsigned_without_secret' => (bool) env('INTEGRATIONS_WEBHOOK_ALLOW_UNSIGNED', false),
        'allow_legacy_signature' => (bool) env('INTEGRATIONS_WEBHOOK_ALLOW_LEGACY_SIGNATURE', false),
        'providers' => [
            'mock' => [
                'webhook_secret' => env('INTEGRATIONS_WEBHOOK_MOCK_SECRET'),
                'webhook_tolerance_seconds' => (int) env(
                    'INTEGRATIONS_WEBHOOK_MOCK_TOLERANCE_SECONDS',
                    env('INTEGRATIONS_WEBHOOK_TOLERANCE_SECONDS', 300)
                ),
            ],
            'apple_health' => [
                'webhook_secret' => env('INTEGRATIONS_WEBHOOK_APPLE_HEALTH_SECRET'),
            ],
            'google_fit' => [
                'webhook_secret' => env('INTEGRATIONS_WEBHOOK_GOOGLE_FIT_SECRET'),
            ],
            'calendar' => [
                'webhook_secret' => env('INTEGRATIONS_WEBHOOK_CALENDAR_SECRET'),
            ],
            'screen_time' => [
                'webhook_secret' => env('INTEGRATIONS_WEBHOOK_SCREEN_TIME_SECRET'),
            ],
        ],
    ],

];
