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
MIG_DIR="${BACKEND_DIR}/database/migrations"
ART_DIR="${ART_DIR:-${BACKEND_DIR}/artifacts/pr71}"

mkdir -p "${ART_DIR}"
exec > "${ART_DIR}/verify.log" 2>&1

fail() {
  echo "[PR71][VERIFY][FAIL] $*"
  exit 1
}

echo "[PR71][VERIFY] start"

BLOCKED_OUT="${ART_DIR}/blocked_patterns.txt"
if rg -n --glob '*.php' "dropIfExists\(|dropColumn\(|dropTable\(|renameColumn\(|->change\(" "${MIG_DIR}" > "${BLOCKED_OUT}"; then
  fail "blocked migration pattern detected"
fi

(
  cd "${BACKEND_DIR}"
  php artisan test --filter MigrationSafetyTest
  php artisan test --filter MigrationRollbackSafetyTest
  php artisan test --filter MigrationsNoSilentCatchTest
  php artisan test --filter MigrationProtectedTablesNoDropTest
) || fail "migration safety tests failed"

echo "verify=pass" > "${ART_DIR}/verify_done.txt"
echo "[PR71][VERIFY] pass"
