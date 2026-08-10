<?php

declare(strict_types=1);

return [
    'funnel_daily' => [
        'reporting_timezone' => env('ANALYTICS_FUNNEL_REPORTING_TIMEZONE', 'Asia/Shanghai'),
        'storage_timezone' => env('ANALYTICS_STORAGE_TIMEZONE', 'UTC'),
    ],

    'provider_freshness' => [
        'enabled' => (bool) env('ANALYTICS_PROVIDER_FRESHNESS_ENABLED', false),
        'timezone' => 'Asia/Shanghai',
        'cache_store' => env('ANALYTICS_PROVIDER_FRESHNESS_CACHE_STORE'),
        'cache_key' => 'analytics:provider-freshness:v1',
        'timeout_seconds' => min(10, max(1, (int) env('ANALYTICS_PROVIDER_FRESHNESS_TIMEOUT_SECONDS', 8))),
        'max_attempts' => min(4, max(1, (int) env('ANALYTICS_PROVIDER_FRESHNESS_MAX_ATTEMPTS', 3))),
        'retry_base_delay_ms' => max(0, (int) env('ANALYTICS_PROVIDER_FRESHNESS_RETRY_BASE_DELAY_MS', 150)),
        'retry_jitter_ms' => max(0, (int) env('ANALYTICS_PROVIDER_FRESHNESS_RETRY_JITTER_MS', 100)),
        'allowed_provider_lag_days' => max(0, (int) env('ANALYTICS_PROVIDER_FRESHNESS_ALLOWED_LAG_DAYS', 2)),
        'backend_stale_hours' => max(1, (int) env('ANALYTICS_PROVIDER_FRESHNESS_BACKEND_STALE_HOURS', 30)),
        'minimum_backend_activity' => max(1, (int) env('ANALYTICS_PROVIDER_FRESHNESS_MIN_BACKEND_ACTIVITY', 5)),

        'ga4' => [
            'enabled' => (bool) env('ANALYTICS_PROVIDER_FRESHNESS_GA4_ENABLED', false),
            'property_id' => env('ANALYTICS_PROVIDER_FRESHNESS_GA4_PROPERTY_ID'),
            'service_account_json' => env('ANALYTICS_PROVIDER_FRESHNESS_GA4_SERVICE_ACCOUNT_JSON'),
            'token_endpoint' => 'https://oauth2.googleapis.com/token',
            'report_endpoint' => 'https://analyticsdata.googleapis.com/v1beta/properties/%s:runReport',
            'readonly_scope' => 'https://www.googleapis.com/auth/analytics.readonly',
        ],

        'baidu' => [
            'enabled' => (bool) env('ANALYTICS_PROVIDER_FRESHNESS_BAIDU_ENABLED', false),
            'site_id' => env('ANALYTICS_PROVIDER_FRESHNESS_BAIDU_SITE_ID'),
            'access_token' => env('ANALYTICS_PROVIDER_FRESHNESS_BAIDU_ACCESS_TOKEN'),
            'refresh_token' => env('ANALYTICS_PROVIDER_FRESHNESS_BAIDU_REFRESH_TOKEN'),
            'client_id' => env('ANALYTICS_PROVIDER_FRESHNESS_BAIDU_CLIENT_ID'),
            'client_secret' => env('ANALYTICS_PROVIDER_FRESHNESS_BAIDU_CLIENT_SECRET'),
            'token_endpoint' => 'https://openapi.baidu.com/oauth/2.0/token',
            'report_endpoint' => 'https://openapi.baidu.com/rest/2.0/tongji/report/getData',
        ],
    ],

    'smoke_attempt_exclusion' => [
        /*
         * Production smoke attempts are operational probes, not growth signals.
         * Keep the default empty so deploy/runtime config can provide exact ids
         * without hardcoding live attempt identifiers in the repository.
         */
        'attempt_ids' => array_values(array_filter(array_map(
            static fn (string $value): string => trim($value),
            explode(',', (string) env('ANALYTICS_SMOKE_EXCLUDED_ATTEMPT_IDS', ''))
        ))),

        'anon_id_prefixes' => [
            'codex_probe_',
        ],

        'traffic_quality_labels' => [
            'smoke',
            'probe',
            'codex_probe',
            'internal_smoke',
            'production_smoke',
        ],
    ],
];
