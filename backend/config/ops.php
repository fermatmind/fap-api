<?php

return [
    'allowed_host' => env('OPS_ALLOWED_HOST', ''),

    'ip_allowlist' => array_values(array_filter(array_map(
        static fn (string $value): string => trim($value),
        explode(',', (string) env('OPS_IP_ALLOWLIST', ''))
    ))),

    'go_live_gate' => [
        'stripe_secret' => env('STRIPE_SECRET', ''),
        'stripe_webhook_secret' => env('STRIPE_WEBHOOK_SECRET', ''),
        'require_stripe_live' => (bool) env('OPS_GATE_REQUIRE_STRIPE_LIVE', true),
        'payment_refund_drill_ok' => (bool) env('OPS_GATE_PAYMENT_REFUND_DRILL_OK', false),
        'db_restore_drill_ok' => (bool) env('OPS_GATE_DB_RESTORE_DRILL_OK', false),
        'log_rotation_ok' => (bool) env('OPS_GATE_LOG_ROTATION_OK', false),
        'spf_dkim_dmarc_ok' => (bool) env('OPS_GATE_SPF_DKIM_DMARC_OK', false),
        'legal_pages_ok' => (bool) env('OPS_GATE_LEGAL_PAGES_OK', false),
        'conversion_tracking_ok' => (bool) env('OPS_GATE_CONVERSION_TRACKING_OK', false),
        'gsc_sitemap_ok' => (bool) env('OPS_GATE_GSC_SITEMAP_OK', false),
        'backend_sentry_dsn' => env('SENTRY_LARAVEL_DSN', env('SENTRY_DSN', '')),
        'frontend_sentry_dsn' => env('VITE_SENTRY_DSN', ''),
    ],

    'security' => [
        'admin_login_max_attempts' => (int) env('OPS_ADMIN_LOGIN_MAX_ATTEMPTS', 5),
        'admin_login_decay_seconds' => (int) env('OPS_ADMIN_LOGIN_DECAY_SECONDS', 300),
    ],
];
