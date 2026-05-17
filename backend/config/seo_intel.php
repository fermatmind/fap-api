<?php

return [
    'enabled' => env('SEO_INTEL_ENABLED', false),
    'connection' => env('SEO_INTEL_DB_CONNECTION', 'seo_intel'),
    'write_enabled' => env('SEO_INTEL_WRITE_ENABLED', false),
    'collectors_enabled' => env('SEO_INTEL_COLLECTORS_ENABLED', false),
    'dry_run_default' => env('SEO_INTEL_DRY_RUN_DEFAULT', true),
    'allow_external_api_calls' => false,
    'allowed_collectors' => [
        'noop',
    ],
    'default_collector' => 'noop',
    'collector_timeout_seconds' => env('SEO_INTEL_COLLECTOR_TIMEOUT_SECONDS', 30),
    'allow_pii_detail' => false,
    'allow_raw_order_no' => false,
    'allow_raw_attempt_id' => false,
    'allow_raw_payment_payload' => false,
    'allow_raw_email' => false,
    'default_environment' => env('APP_ENV', 'production'),
];
