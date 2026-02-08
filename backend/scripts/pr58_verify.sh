#!/usr/bin/env bash
set -euo pipefail

export CI=true
export FAP_NONINTERACTIVE=1
export COMPOSER_NO_INTERACTION=1
export GIT_TERMINAL_PROMPT=0
export NO_COLOR=1
export PAGER=cat
export GIT_PAGER=cat
export TERM=dumb
export XDEBUG_MODE=off
export LANG=en_US.UTF-8

REPO_DIR="$(cd "$(dirname "${BASH_SOURCE[0]}")/../.." && pwd)"
BACKEND_DIR="${REPO_DIR}/backend"
ART_DIR="${ART_DIR:-${BACKEND_DIR}/artifacts/pr58}"

mkdir -p "${ART_DIR}"
exec > "${ART_DIR}/verify.log" 2>&1

cd "${BACKEND_DIR}"
php artisan test --testsuite=Unit > "${ART_DIR}/unit_test.txt" 2>&1

php -r '
$files = glob("database/migrations/*.php");
sort($files);
$fp = fopen("'"${ART_DIR}"'/scan.txt", "wb");
if (!$fp) { fwrite(STDERR, "scan_open_failed\n"); exit(1); }
foreach ($files as $file) {
    $lines = file($file);
    if (!is_array($lines)) { continue; }
    foreach ($lines as $idx => $line) {
        if (preg_match("/Schema::dropIfExists\\s*\\(|Schema::hasTable\\s*\\(|catch\\s*\\(\\\\Throwable/", $line) === 1) {
            fwrite($fp, $file . ":" . ($idx + 1) . ":" . rtrim($line, "\\r\\n") . PHP_EOL);
        }
    }
}
fclose($fp);
' 

php -r '
$src = @file_get_contents("'"${ART_DIR}"'/unit_test.txt");
if (!is_string($src) || $src === "") {
    fwrite(STDERR, "unit_test_output_missing\n");
    exit(1);
}
if (strpos($src, "FAIL") !== false) {
    fwrite(STDERR, "unit_test_contains_fail\n");
    exit(1);
}
if (strpos($src, "PASS") === false) {
    fwrite(STDERR, "unit_test_missing_pass\n");
    exit(1);
}
' 

echo "verify_done" > "${ART_DIR}/verify_done.txt"
