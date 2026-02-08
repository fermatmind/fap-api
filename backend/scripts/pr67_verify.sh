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
ART_DIR="${ART_DIR:-${BACKEND_DIR}/artifacts/pr67}"
DRIVER_FILE="${BACKEND_DIR}/app/Services/Assessment/Drivers/GenericLikertDriver.php"
TEST_FILE="${BACKEND_DIR}/tests/Unit/Psychometrics/GenericLikertDriverTest.php"

mkdir -p "${ART_DIR}"
exec > "${ART_DIR}/verify.log" 2>&1

echo "[PR67][VERIFY] start"

php -l "${DRIVER_FILE}" > "${ART_DIR}/php_lint_driver.log"
php -l "${TEST_FILE}" > "${ART_DIR}/php_lint_test.log"

cd "${BACKEND_DIR}"
php artisan test --testsuite=Unit | tee "${ART_DIR}/unit_tests.log"
cd "${REPO_DIR}"

echo "verify=pass" > "${ART_DIR}/verify_done.txt"
echo "[PR67][VERIFY] pass"
