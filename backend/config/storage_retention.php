<?php

declare(strict_types=1);

return [
    'reports' => [
        'keep_days' => (int) env('STORAGE_RETENTION_REPORTS_KEEP_DAYS', 365),
        'keep_timestamp_backups' => (int) env('STORAGE_RETENTION_REPORTS_KEEP_TIMESTAMP_BACKUPS', 0),
    ],

    'pdf' => [
        'keep_days_paid' => (int) env('STORAGE_RETENTION_PDF_KEEP_DAYS_PAID', 365),
        'keep_days_free' => (int) env('STORAGE_RETENTION_PDF_KEEP_DAYS_FREE', 30),
    ],

    'releases' => [
        'keep_last_n' => (int) env('STORAGE_RETENTION_RELEASES_KEEP_LAST_N', 20),
        'keep_days' => (int) env('STORAGE_RETENTION_RELEASES_KEEP_DAYS', 180),
    ],

    'logs' => [
        'keep_days' => (int) env('STORAGE_RETENTION_LOGS_KEEP_DAYS', 30),
    ],

    'compatibility' => [
        'keep_legacy_paths_days' => (int) env('STORAGE_RETENTION_KEEP_LEGACY_PATHS_DAYS', 90),
    ],
];
