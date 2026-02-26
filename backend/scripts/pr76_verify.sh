#!/usr/bin/env bash
set -euo pipefail

SCRIPT_DIR="$(cd "$(dirname "${BASH_SOURCE[0]}")" && pwd)"
BACKEND_DIR="$(cd "${SCRIPT_DIR}/.." && pwd)"
ARTIFACT_DIR="${BACKEND_DIR}/artifacts/pr76"
REPORT_FILE="${ARTIFACT_DIR}/events_share_dedupe_explain.txt"

mkdir -p "${ARTIFACT_DIR}"
cd "${BACKEND_DIR}"

php <<'PHP' > "${REPORT_FILE}"
<?php

declare(strict_types=1);

require getcwd().'/vendor/autoload.php';

$app = require getcwd().'/bootstrap/app.php';
$kernel = $app->make(Illuminate\Contracts\Console\Kernel::class);
$kernel->bootstrap();

$table = 'events';
$indexName = 'idx_events_share_dedupe_lookup';
$requiredColumns = ['share_style_g', 'page_session_id_g'];
$sql = 'SELECT 1 FROM events WHERE event_code = ? AND anon_id = ? AND attempt_id = ? AND share_style_g = ? AND page_session_id_g = ? LIMIT 1';
$bindings = ['share_click', 'anon_probe', 'attempt_probe', 'card', 'session_probe'];

$driver = Illuminate\Support\Facades\DB::connection()->getDriverName();
echo "driver={$driver}".PHP_EOL;

if (!Illuminate\Support\Facades\Schema::hasTable($table)) {
    echo '[PR76][FAIL] events table not found.'.PHP_EOL;
    exit(1);
}

foreach ($requiredColumns as $column) {
    if (!Illuminate\Support\Facades\Schema::hasColumn($table, $column)) {
        echo "[PR76][FAIL] missing column: {$column}".PHP_EOL;
        exit(1);
    }
}

if ($driver === 'mysql') {
    $rows = Illuminate\Support\Facades\DB::select('EXPLAIN '.$sql, $bindings);
    $usedKeys = [];

    foreach ($rows as $row) {
        $payload = (array) $row;
        echo json_encode($payload, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES).PHP_EOL;

        $key = trim((string) ($payload['key'] ?? $payload['KEY'] ?? ''));
        if ($key !== '') {
            $usedKeys[] = $key;
        }
    }

    $usedKeys = array_values(array_unique($usedKeys));

    if (!in_array($indexName, $usedKeys, true)) {
        echo '[PR76][FAIL] mysql explain does not use expected index. keys='.implode(',', $usedKeys).PHP_EOL;
        exit(1);
    }

    echo "[PR76][OK] mysql explain uses index {$indexName}.".PHP_EOL;
    exit(0);
}

if ($driver === 'sqlite') {
    $rows = Illuminate\Support\Facades\DB::select('EXPLAIN QUERY PLAN '.$sql, $bindings);
    $matched = false;

    foreach ($rows as $row) {
        $payload = (array) $row;
        echo json_encode($payload, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES).PHP_EOL;

        $detail = strtolower((string) ($payload['detail'] ?? ''));
        if (str_contains($detail, strtolower($indexName))) {
            $matched = true;
        }
    }

    if (!$matched) {
        echo '[PR76][FAIL] sqlite query plan does not hit expected index.'.PHP_EOL;
        exit(1);
    }

    echo "[PR76][OK] sqlite query plan uses index {$indexName}.".PHP_EOL;
    exit(0);
}

echo "[PR76][SKIP] unsupported driver={$driver}.".PHP_EOL;
PHP

cat "${REPORT_FILE}"
