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
: > "${BLOCKED_OUT}"
rg -n -U --glob '*.php' "dropIfExists\s*\(|dropTable\s*\(|renameColumn\s*\(|->\s*change\s*\(" "${MIG_DIR}" >> "${BLOCKED_OUT}" || true

while IFS= read -r migration; do
  if ! rg -q "RETIREMENT_EVIDENCE_ID" "${migration}"; then
    rg -n -U "dropColumn\s*\(" "${migration}" >> "${BLOCKED_OUT}"
  fi
done < <(rg -l -U --glob '*.php' "dropColumn\s*\(" "${MIG_DIR}" || true)

if [[ -s "${BLOCKED_OUT}" ]]; then
  fail "blocked migration pattern detected"
fi

(
  cd "${BACKEND_DIR}"
  php artisan test --filter MigrationSafetyTest
  php artisan test --filter MigrationRollbackSafetyTest
  php artisan test --filter MigrationsNoSilentCatchTest
  php artisan test --filter MigrationProtectedTablesNoDropTest
  php artisan test --filter MigrationDestructiveRetirementEvidenceTest
) || fail "migration safety tests failed"

echo "verify=pass" > "${ART_DIR}/verify_done.txt"
echo "[PR71][VERIFY] pass"
