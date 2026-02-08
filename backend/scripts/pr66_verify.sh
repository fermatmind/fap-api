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
ART_DIR="${ART_DIR:-${BACKEND_DIR}/artifacts/pr66}"

mkdir -p "${ART_DIR}"
exec > "${ART_DIR}/verify.log" 2>&1

echo "[PR66][VERIFY] start"

find "${BACKEND_DIR}/database/migrations" -name '*.php' -print0 | xargs -0 -n1 php -l > "${ART_DIR}/migration_lint.log"

cd "${BACKEND_DIR}"
php artisan test --testsuite=Unit | tee "${ART_DIR}/unit_tests.log"
cd "${REPO_DIR}"

grep -R -n -E "Schema::dropIfExists\(|Schema::drop\(" "${BACKEND_DIR}/database/migrations" | tee "${ART_DIR}/dropifexists_hits.txt" || true
grep -R -n -E "catch[[:space:]]*\([[:space:]]*\\?Throwable|catch[[:space:]]*\([[:space:]]*\\?Exception" "${BACKEND_DIR}/database/migrations" | tee "${ART_DIR}/catch_hits.txt" || true

echo "verify=pass" > "${ART_DIR}/verify_done.txt"
echo "[PR66][VERIFY] pass"
