#!/usr/bin/env bash

set -Eeuo pipefail

script_dir="$(cd -- "$(dirname -- "${BASH_SOURCE[0]}")" && pwd)"
backend_root="$(cd -- "$script_dir/../.." && pwd)"

exec php -d display_errors=0 -- "$backend_root" <<'PHP'
<?php

declare(strict_types=1);

use Illuminate\Contracts\Console\Kernel;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Queue;

try {
    $backendRoot = $argv[1] ?? null;
    if (! is_string($backendRoot) || $backendRoot === '') {
        throw new RuntimeException('invalid backend root');
    }

    require $backendRoot.'/vendor/autoload.php';
    $app = require $backendRoot.'/bootstrap/app.php';
    $kernel = $app->make(Kernel::class);
    $kernel->bootstrap();

    $safeName = static function (mixed $value, string $label): string {
        $normalized = trim((string) $value);
        if ($normalized === '' || preg_match('/\A[A-Za-z0-9_.-]+\z/D', $normalized) !== 1) {
            throw new RuntimeException('invalid '.$label);
        }

        return $normalized;
    };

    $queueSize = static function (string $connection, string $queue): int {
        $size = Queue::connection($connection)->size($queue);
        if (! is_int($size) && ! ctype_digit((string) $size)) {
            throw new RuntimeException('invalid queue size');
        }

        return (int) $size;
    };

    $queue = $safeName(config('ops.deploy_queue_smoke.queue', 'default'), 'default queue');
    $maxDepth = max(0, (int) config('ops.deploy_queue_smoke.max_depth', 5));
    $waitSeconds = min(30, max(1, (int) config('ops.deploy_queue_smoke.stability_wait_seconds', 10)));
    $maxGrowth = max(0, (int) config('ops.deploy_queue_smoke.max_growth', 1));
    $pendingWindowMinutes = max(1, (int) config('ops.deploy_queue_smoke.pending_window_minutes', 30));
    $maxRecentPending = max(0, (int) config('ops.deploy_queue_smoke.max_recent_pending', 3));

    $defaultConnection = $safeName(config('queue.default', 'redis'), 'default connection');
    $defaultConfig = config('queue.connections.'.$defaultConnection);
    if (! is_array($defaultConfig)) {
        throw new RuntimeException('missing default connection config');
    }
    $defaultDriver = $safeName($defaultConfig['driver'] ?? '', 'default driver');
    $defaultSkipped = $defaultDriver === 'sync';
    $defaultBefore = $defaultSkipped ? 0 : $queueSize($defaultConnection, $queue);

    $reportsConnection = $safeName(
        config('ops.deploy_queue_smoke.reports_connection', 'database_reports'),
        'reports connection',
    );
    $reportsQueue = $safeName(
        config('ops.deploy_queue_smoke.reports_queue', 'reports'),
        'reports queue',
    );
    if ($reportsConnection !== 'database_reports' || $reportsQueue !== 'reports') {
        throw new RuntimeException('reports queue identity drift');
    }

    $reportsConfig = config('queue.connections.'.$reportsConnection);
    if (! is_array($reportsConfig) || (string) ($reportsConfig['driver'] ?? '') !== 'database') {
        throw new RuntimeException('reports driver drift');
    }
    $configuredReportsQueue = $safeName($reportsConfig['queue'] ?? '', 'configured reports queue');
    $reportsTable = $safeName($reportsConfig['table'] ?? '', 'reports table');
    if ($configuredReportsQueue !== $reportsQueue || preg_match('/\A[A-Za-z0-9_]+\z/D', $reportsTable) !== 1) {
        throw new RuntimeException('reports database topology drift');
    }
    $reportsDatabaseConnection = $reportsConfig['connection'] ?? null;
    if ($reportsDatabaseConnection !== null) {
        $reportsDatabaseConnection = $safeName($reportsDatabaseConnection, 'reports database connection');
    }

    $reportsMaxDepth = max(0, (int) config('ops.deploy_queue_smoke.reports_max_depth', 3));
    $reportsMaxGrowth = max(0, (int) config('ops.deploy_queue_smoke.reports_max_growth', 1));
    $reportsMaxOldestSeconds = max(1, (int) config('ops.deploy_queue_smoke.reports_max_oldest_seconds', 180));
    $snapshotMaxPendingSeconds = max(1, (int) config('ops.deploy_queue_smoke.reports_snapshot_max_pending_seconds', 180));

    $reportsBefore = $queueSize($reportsConnection, $reportsQueue);
    sleep($waitSeconds);
    $defaultAfter = $defaultSkipped ? 0 : $queueSize($defaultConnection, $queue);
    $reportsAfter = $queueSize($reportsConnection, $reportsQueue);

    $oldestCreatedAt = DB::connection($reportsDatabaseConnection)
        ->table($reportsTable)
        ->where('queue', $reportsQueue)
        ->min('created_at');
    $oldestReportsSeconds = (is_int($oldestCreatedAt) || ctype_digit((string) $oldestCreatedAt))
        ? max(0, time() - (int) $oldestCreatedAt)
        : 0;

    $stalePendingSnapshots = (int) DB::table('report_snapshots')
        ->where('status', 'pending')
        ->whereRaw('COALESCE(updated_at, created_at) < ?', [now()->subSeconds($snapshotMaxPendingSeconds)])
        ->count();

    $recentPendingSubmissions = (int) DB::table('attempt_submissions')
        ->whereIn('state', ['pending', 'running'])
        ->where('updated_at', '>=', now()->subMinutes($pendingWindowMinutes))
        ->count();

    $payload = [
        'wait_seconds' => $waitSeconds,
        'default_queue' => [
            'connection' => $defaultConnection,
            'driver' => $defaultDriver,
            'queue' => $queue,
            'skipped' => $defaultSkipped,
            'skip_reason' => $defaultSkipped ? 'sync_connection' : null,
            'before' => $defaultBefore,
            'after' => $defaultAfter,
            'max_depth' => $maxDepth,
            'max_growth' => $maxGrowth,
        ],
        'reports_queue' => [
            'connection' => $reportsConnection,
            'driver' => 'database',
            'queue' => $reportsQueue,
            'before' => $reportsBefore,
            'after' => $reportsAfter,
            'max_depth' => $reportsMaxDepth,
            'max_growth' => $reportsMaxGrowth,
            'oldest_seconds' => $oldestReportsSeconds,
            'max_oldest_seconds' => $reportsMaxOldestSeconds,
            'stale_pending_snapshots' => $stalePendingSnapshots,
            'snapshot_max_pending_seconds' => $snapshotMaxPendingSeconds,
        ],
        'recent_pending_submissions' => $recentPendingSubmissions,
        'max_recent_pending_submissions' => $maxRecentPending,
    ];

    $failures = [];
    if (! $defaultSkipped && $defaultAfter > $maxDepth) {
        $failures[] = 'DEFAULT_DEPTH';
    }
    if (! $defaultSkipped && ($defaultAfter - $defaultBefore) > $maxGrowth) {
        $failures[] = 'DEFAULT_GROWTH';
    }
    if ($reportsAfter > $reportsMaxDepth) {
        $failures[] = 'REPORTS_DEPTH';
    }
    if (($reportsAfter - $reportsBefore) > $reportsMaxGrowth) {
        $failures[] = 'REPORTS_GROWTH';
    }
    if ($oldestReportsSeconds > $reportsMaxOldestSeconds) {
        $failures[] = 'REPORTS_OLDEST';
    }
    if ($stalePendingSnapshots > 0) {
        $failures[] = 'REPORT_SNAPSHOT_STALE';
    }
    if ($recentPendingSubmissions > $maxRecentPending) {
        $failures[] = 'SUBMISSION_PENDING';
    }

    if ($failures !== []) {
        fwrite(STDERR, 'QUEUE_SMOKE_FAILED:'.implode(',', $failures).' '.json_encode(
            $payload,
            JSON_THROW_ON_ERROR | JSON_UNESCAPED_SLASHES,
        ).PHP_EOL);
        exit(1);
    }

    echo json_encode(
        ['status' => 'pass'] + $payload,
        JSON_THROW_ON_ERROR | JSON_UNESCAPED_SLASHES,
    ), PHP_EOL;
} catch (Throwable) {
    fwrite(STDERR, "QUEUE_SMOKE_FAILED:PROBE_ERROR\n");
    exit(1);
}
PHP
