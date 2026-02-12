<?php

return [
    'signature_tolerance_seconds' => (int) env('INTEGRATIONS_SIGNATURE_TOLERANCE_SECONDS', 300),
    'webhook_max_payload_bytes' => (int) env('INTEGRATIONS_WEBHOOK_MAX_BYTES', 262144),

    'allowed_providers' => array_values(array_filter(array_map(
        static fn ($v) => strtolower(trim((string) $v)),
        explode(',', (string) env(
            'INTEGRATIONS_ALLOWED_PROVIDERS',
            'mock,apple_health,google_fit,calendar,screen_time'
        ))
    ))),

    'ingest' => [
        'max_samples' => (int) env('INTEGRATIONS_INGEST_MAX_SAMPLES', 500),
        'max_value_depth' => (int) env('INTEGRATIONS_INGEST_MAX_VALUE_DEPTH', 8),
        'max_value_bytes' => (int) env('INTEGRATIONS_INGEST_MAX_VALUE_BYTES', 8192),
    ],

    'providers' => [
        'mock' => ['secret' => (string) env('INTEGRATIONS_INGEST_MOCK_SECRET', '')],
        'apple_health' => ['secret' => (string) env('INTEGRATIONS_INGEST_APPLE_HEALTH_SECRET', '')],
        'google_fit' => ['secret' => (string) env('INTEGRATIONS_INGEST_GOOGLE_FIT_SECRET', '')],
        'calendar' => ['secret' => (string) env('INTEGRATIONS_INGEST_CALENDAR_SECRET', '')],
        'screen_time' => ['secret' => (string) env('INTEGRATIONS_INGEST_SCREEN_TIME_SECRET', '')],
    ],
];
