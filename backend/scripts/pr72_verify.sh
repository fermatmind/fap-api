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
ART_DIR="${ART_DIR:-${BACKEND_DIR}/artifacts/pr72}"
CONTROLLER_FILE="${BACKEND_DIR}/app/Http/Controllers/API/V0_3/BigFiveOpsController.php"

mkdir -p "${ART_DIR}"
exec > "${ART_DIR}/verify.log" 2>&1

fail() {
  echo "[PR72][VERIFY][FAIL] $*"
  exit 1
}

echo "[PR72][VERIFY] start"

if rg -n "DB::table\(|Artisan::call\(" "${CONTROLLER_FILE}" > "${ART_DIR}/controller_forbidden_calls.txt"; then
  fail "BigFiveOpsController still contains DB::table or Artisan::call"
fi

echo "verify=pass" > "${ART_DIR}/verify_done.txt"
echo "[PR72][VERIFY] pass"
