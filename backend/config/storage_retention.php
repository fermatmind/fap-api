<?php

return [
    'reports' => [
        'keep_days' => (int) env('STORAGE_RETENTION_REPORTS_DAYS', 365),
        'keep_timestamp_backups' => (int) env('STORAGE_RETENTION_REPORT_TS_KEEP', 0),
    ],
    'content_releases' => [
        'keep_last_n' => (int) env('STORAGE_RETENTION_RELEASES_KEEP_LAST', 20),
        'keep_days' => (int) env('STORAGE_RETENTION_RELEASES_DAYS', 180),
    ],
    'logs' => [
        'keep_days' => (int) env('STORAGE_RETENTION_LOGS_DAYS', 30),
    ],
];
