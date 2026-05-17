<?php

return [
    'enabled' => env('SEO_INTEL_ENABLED', false),
    'connection' => env('SEO_INTEL_DB_CONNECTION', 'seo_intel'),
    'write_enabled' => env('SEO_INTEL_WRITE_ENABLED', false),
    'collectors_enabled' => env('SEO_INTEL_COLLECTORS_ENABLED', false),
    'allow_pii_detail' => false,
    'allow_raw_order_no' => false,
    'allow_raw_attempt_id' => false,
    'allow_raw_payment_payload' => false,
    'allow_raw_email' => false,
    'default_environment' => env('APP_ENV', 'production'),
];
