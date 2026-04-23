<?php

$opsIpAllowlist = array_values(array_filter(array_map(
    static fn (string $value): string => trim($value),
    explode(',', (string) env('OPS_IP_ALLOWLIST', ''))
)));

$opsAccessControl = [
    'enabled' => (bool) env('OPS_ACCESS_CONTROL_ENABLED', true),
    'fail_open' => (bool) env('OPS_ACCESS_FAIL_OPEN', true),
    'emergency_disable' => (bool) env('OPS_EMERGENCY_DISABLE', false),
    'allowed_host' => env('OPS_ALLOWED_HOST', ''),
    'ip_allowlist' => $opsIpAllowlist,
    'admin_login_max_attempts' => (int) env('OPS_ADMIN_LOGIN_MAX_ATTEMPTS', 5),
    'audit_log' => true,
    'rate_limit' => [
        'login' => (int) env('OPS_LOGIN_RATE_LIMIT', (int) env('OPS_ADMIN_LOGIN_MAX_ATTEMPTS', 5)),
        'global' => (int) env('OPS_GLOBAL_RATE_LIMIT', 100),
    ],
    'risk' => [
        'enabled' => (bool) env('OPS_RISK_ENGINE_ENABLED', true),
    ],
];

return [
    'allowed_host' => $opsAccessControl['allowed_host'],

    'ip_allowlist' => $opsAccessControl['ip_allowlist'],

    'access_control' => $opsAccessControl,

    'alert' => [
        'webhook' => env('OPS_ALERT_WEBHOOK'),
    ],

    'content_release_observability' => [
        'cache_invalidation_urls' => array_values(array_filter(array_map(
            static fn (string $value): string => trim($value),
            explode(',', (string) env('OPS_CONTENT_RELEASE_CACHE_INVALIDATION_URLS', ''))
        ))),
        'cache_invalidation_secret' => env('OPS_CONTENT_RELEASE_CACHE_INVALIDATION_SECRET', ''),
        'broadcast_webhook' => env('OPS_CONTENT_RELEASE_BROADCAST_WEBHOOK', ''),
        'http_timeout_seconds' => (int) env('OPS_CONTENT_RELEASE_HTTP_TIMEOUT_SECONDS', 5),
    ],

    'go_live_gate' => [
        // enabled_only: only validate providers that are enabled in payments.providers.*
        // all: validate all known providers regardless of enabled status.
        'payment_policy' => env('OPS_GATE_PAYMENT_POLICY', 'enabled_only'),

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
        'admin_login_max_attempts' => $opsAccessControl['admin_login_max_attempts'],
        'admin_login_decay_seconds' => (int) env('OPS_ADMIN_LOGIN_DECAY_SECONDS', 300),
    ],

    'queue_backlog_probe' => [
        'queues' => ['attempts', 'reports', 'commerce'],
        'window_minutes' => (int) env('OPS_QUEUE_BACKLOG_WINDOW_MINUTES', 60),
        'strict_default' => (bool) env('OPS_QUEUE_BACKLOG_STRICT_DEFAULT', false),
        'thresholds' => [
            'attempts' => [
                'max_pending' => (int) env('OPS_QUEUE_ATTEMPTS_MAX_PENDING', 120),
                'max_failed' => (int) env('OPS_QUEUE_ATTEMPTS_MAX_FAILED', 15),
                'max_oldest_seconds' => (int) env('OPS_QUEUE_ATTEMPTS_MAX_OLDEST_SECONDS', 240),
                'max_timeout_failures' => (int) env('OPS_QUEUE_ATTEMPTS_MAX_TIMEOUT_FAILURES', 3),
            ],
            'reports' => [
                'max_pending' => (int) env('OPS_QUEUE_REPORTS_MAX_PENDING', 60),
                'max_failed' => (int) env('OPS_QUEUE_REPORTS_MAX_FAILED', 10),
                'max_oldest_seconds' => (int) env('OPS_QUEUE_REPORTS_MAX_OLDEST_SECONDS', 300),
                'max_timeout_failures' => (int) env('OPS_QUEUE_REPORTS_MAX_TIMEOUT_FAILURES', 2),
            ],
            'commerce' => [
                'max_pending' => (int) env('OPS_QUEUE_COMMERCE_MAX_PENDING', 40),
                'max_failed' => (int) env('OPS_QUEUE_COMMERCE_MAX_FAILED', 8),
                'max_oldest_seconds' => (int) env('OPS_QUEUE_COMMERCE_MAX_OLDEST_SECONDS', 180),
                'max_timeout_failures' => (int) env('OPS_QUEUE_COMMERCE_MAX_TIMEOUT_FAILURES', 2),
            ],
        ],
        'alert_policy' => [
            'escalation_chain' => ['ops-oncall', 'backend-oncall', 'payments-oncall'],
            'quiet_window' => [
                'timezone' => env('OPS_QUEUE_ALERT_QUIET_TZ', 'Asia/Shanghai'),
                'start' => env('OPS_QUEUE_ALERT_QUIET_START', '02:00'),
                'end' => env('OPS_QUEUE_ALERT_QUIET_END', '08:00'),
            ],
        ],
    ],

    'attempt_chain_audit' => [
        'window_hours' => (int) env('OPS_ATTEMPT_CHAIN_AUDIT_WINDOW_HOURS', 24),
        'limit' => (int) env('OPS_ATTEMPT_CHAIN_AUDIT_LIMIT', 200),
        'pending_timeout_minutes' => (int) env('OPS_ATTEMPT_CHAIN_AUDIT_PENDING_TIMEOUT_MINUTES', 15),
        'strict_default' => (bool) env('OPS_ATTEMPT_CHAIN_AUDIT_STRICT_DEFAULT', false),
    ],

    'attempt_submission_recovery' => [
        'window_hours' => (int) env('OPS_ATTEMPT_SUBMISSION_RECOVERY_WINDOW_HOURS', 24),
        'limit' => (int) env('OPS_ATTEMPT_SUBMISSION_RECOVERY_LIMIT', 200),
        'pending_timeout_minutes' => (int) env('OPS_ATTEMPT_SUBMISSION_RECOVERY_PENDING_TIMEOUT_MINUTES', 15),
        'strict_default' => (bool) env('OPS_ATTEMPT_SUBMISSION_RECOVERY_STRICT_DEFAULT', false),
        'alert_default' => (bool) env('OPS_ATTEMPT_SUBMISSION_RECOVERY_ALERT_DEFAULT', true),
    ],

    'deploy_queue_smoke' => [
        'queue' => env('OPS_DEPLOY_QUEUE_SMOKE_QUEUE', 'default'),
        'max_depth' => (int) env('OPS_DEPLOY_QUEUE_SMOKE_MAX_DEPTH', 5),
        'stability_wait_seconds' => (int) env('OPS_DEPLOY_QUEUE_SMOKE_WAIT_SECONDS', 15),
        'max_growth' => (int) env('OPS_DEPLOY_QUEUE_SMOKE_MAX_GROWTH', 1),
        'pending_window_minutes' => (int) env('OPS_DEPLOY_QUEUE_SMOKE_PENDING_WINDOW_MINUTES', 30),
        'max_recent_pending' => (int) env('OPS_DEPLOY_QUEUE_SMOKE_MAX_RECENT_PENDING', 3),
    ],
];
