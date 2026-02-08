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
ART_DIR="${ART_DIR:-${BACKEND_DIR}/artifacts/pr61}"

mkdir -p "${ART_DIR}"

cd "${BACKEND_DIR}"
php artisan route:list > "${ART_DIR}/route_list.txt"
php artisan test --testsuite=Unit 2>&1 | tee "${ART_DIR}/unit.log"

php -r "echo 'PHP='.PHP_VERSION.PHP_EOL;" | tee "${ART_DIR}/php_version.txt"
grep -n -E "scoring_invalid_answer" "${ART_DIR}/unit.log" > "${ART_DIR}/warning_hits.txt" || true
grep -n -E "PASS|OK \\(" "${ART_DIR}/unit.log" > "${ART_DIR}/unit_pass_markers.txt"

echo "[PR61][VERIFY] pass"
