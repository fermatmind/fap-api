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
ART_DIR="${ART_DIR:-${BACKEND_DIR}/artifacts/pr70}"

mkdir -p "${ART_DIR}"
exec > "${ART_DIR}/verify.log" 2>&1

fail() {
  echo "[PR70][VERIFY][FAIL] $*"
  exit 1
}

echo "[PR70][VERIFY] start"

BIG5_FILE="${BACKEND_DIR}/app/Console/Commands/Big5PsychometricsReport.php"
SDS_FILE="${BACKEND_DIR}/app/Console/Commands/SdsPsychometricsReport.php"
STATIC_ASSERT="${ART_DIR}/streaming_assertions.txt"

{
  grep -n "cursor()" "${BIG5_FILE}"
  grep -n "cursor()" "${SDS_FILE}"
} > "${STATIC_ASSERT}" || fail "cursor() assertion failed"

if grep -n "\$query->get()" "${BIG5_FILE}" "${SDS_FILE}" >> "${STATIC_ASSERT}"; then
  fail "found legacy get() on psychometrics query"
fi

(
  cd "${BACKEND_DIR}"
  php artisan test \
    tests/Feature/Psychometrics/Big5PsychometricsReportCommandTest.php \
    tests/Feature/Psychometrics/SdsPsychometricsReportCommandTest.php
) || fail "psychometrics command tests failed"

echo "verify=pass" > "${ART_DIR}/verify_done.txt"
echo "[PR70][VERIFY] pass"
