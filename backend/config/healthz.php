<?php

return [
    'allowed_ips' => array_filter(array_map('trim', explode(',', (string) env('HEALTHZ_ALLOWED_IPS', '127.0.0.1/32')))),
    'verbose' => (bool) env('HEALTHZ_VERBOSE', false),
    'public_content_p95_warning_threshold_ms' => (int) env('HEALTHZ_PUBLIC_CONTENT_P95_WARNING_MS', 5000),
    'public_content_p95_query_window_minutes' => (int) env('HEALTHZ_PUBLIC_CONTENT_P95_WINDOW_MINUTES', 5),
];
