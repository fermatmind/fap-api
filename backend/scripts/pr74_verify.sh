#!/usr/bin/env bash
set -euo pipefail

SCRIPT_DIR="$(cd "$(dirname "${BASH_SOURCE[0]}")" && pwd)"
BACKEND_DIR="$(cd "${SCRIPT_DIR}/.." && pwd)"
ARTIFACT_DIR="${BACKEND_DIR}/artifacts/pr74"
REPORT_FILE="${ARTIFACT_DIR}/constructor_injection_limit.txt"

mkdir -p "${ARTIFACT_DIR}"
cd "${BACKEND_DIR}"

php <<'PHP' > "${REPORT_FILE}"
<?php

declare(strict_types=1);

require getcwd().'/vendor/autoload.php';

$targets = [
    'App\\Services\\Report\\ReportGatekeeper' => 8,
    'App\\Services\\Attempts\\AttemptStartService' => 8,
    'App\\Services\\Attempts\\AttemptSubmitService' => 8,
];

$violations = [];
$lines = [];

foreach ($targets as $class => $max) {
    if (!class_exists($class)) {
        $violations[] = "{$class}: class_not_found";
        continue;
    }

    $reflection = new ReflectionClass($class);
    $constructor = $reflection->getConstructor();
    $count = $constructor instanceof ReflectionMethod ? $constructor->getNumberOfParameters() : 0;
    $lines[] = "{$class}: {$count}";

    if ($count > $max) {
        $violations[] = "{$class}: {$count} > {$max}";
    }
}

foreach ($lines as $line) {
    echo $line.PHP_EOL;
}

if ($violations !== []) {
    echo '--- violations ---'.PHP_EOL;
    foreach ($violations as $violation) {
        echo $violation.PHP_EOL;
    }
    exit(1);
}
PHP

echo "[PR74][OK] constructor injection counts are within limit." | tee -a "${REPORT_FILE}"
