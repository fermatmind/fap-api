<?php

return [
    'reports' => [
        'keep_days' => (int) env('STORAGE_RETENTION_REPORTS_DAYS', 0),
        'keep_timestamp_backups' => (int) env('STORAGE_RETENTION_REPORT_TS_KEEP', 0),
    ],
    'content_releases' => [
        'keep_last_n' => (int) env('STORAGE_RETENTION_RELEASES_KEEP_LAST', 20),
        'keep_days' => (int) env('STORAGE_RETENTION_RELEASES_DAYS', 180),
    ],
    'logs' => [
        // PR-13A only aligns logging.daily fallback to this policy; no prune job consumes it yet.
        'keep_days' => (int) env('STORAGE_RETENTION_LOGS_DAYS', 30),
    ],
    'rotation_audits' => [
        'policy' => (string) env('STORAGE_RETENTION_ROTATION_AUDITS_POLICY', 'ttl'),
        'keep_days' => (int) env('STORAGE_RETENTION_ROTATION_AUDITS_DAYS', 180),
    ],
    'control_plane_artifacts' => [
        'control_plane_snapshots' => [
            'keep_last_n' => (int) env('STORAGE_RETENTION_CONTROL_PLANE_SNAPSHOTS_KEEP_LAST', 30),
        ],
        'plan_dirs' => [
            'keep_last_n' => (int) env('STORAGE_RETENTION_CONTROL_PLANE_PLAN_DIRS_KEEP_LAST', 5),
        ],
        'prune_plans' => [
            'keep_last_n_per_scope' => (int) env('STORAGE_RETENTION_CONTROL_PLANE_PRUNE_PLANS_KEEP_LAST_PER_SCOPE', 3),
        ],
        'retain_latest_audit_referenced' => filter_var(
            env('STORAGE_RETENTION_CONTROL_PLANE_RETAIN_LATEST_AUDIT_REFERENCED', true),
            FILTER_VALIDATE_BOOL
        ),
    ],
];
