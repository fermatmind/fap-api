<?php

declare(strict_types=1);

return [
    'budget_ms' => [
        'questions_p95_ms' => 200,
        'submit_p95_ms' => 800,
        'report_free_p95_ms' => 500,
        'report_full_p95_ms' => 900,
    ],
    'error_rate_max' => 0.02,
    'smoke' => [
        'requests_per_endpoint' => 12,
        'timeout_seconds' => 8,
        'target_scale' => 'BIG5_OCEAN',
        'fallback_scale' => 'MBTI',
        // Lightweight CI gate hard-checks question delivery only.
        // report/submit are still emitted for observability.
        'required_metrics' => ['questions'],
        'optional_metrics' => ['submit', 'report_free', 'report_full'],
    ],
];
