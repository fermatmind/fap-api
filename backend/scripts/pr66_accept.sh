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
ART_DIR="${BACKEND_DIR}/artifacts/pr66"
SERVE_PORT="${SERVE_PORT:-1866}"
DB_PATH="/tmp/pr66.sqlite"

mkdir -p "${ART_DIR}"

cleanup_port() {
  local port="$1"
  lsof -nP -iTCP:"${port}" -sTCP:LISTEN || true
  local pid_list
  pid_list="$(lsof -ti tcp:"${port}" || true)"
  if [[ -n "${pid_list}" ]]; then
    echo "${pid_list}" | xargs kill -9 || true
  fi
  lsof -nP -iTCP:"${port}" -sTCP:LISTEN || true
}

cleanup() {
  cleanup_port "${SERVE_PORT}"
  cleanup_port 18000
  rm -f "${DB_PATH}"
}
trap cleanup EXIT

cleanup_port "${SERVE_PORT}"
cleanup_port 18000

export APP_ENV=testing
export CACHE_STORE=array
export QUEUE_CONNECTION=sync
export DB_CONNECTION=sqlite
export DB_DATABASE="${DB_PATH}"
export FAP_PACKS_DRIVER=local
export FAP_PACKS_ROOT="${REPO_DIR}/content_packages"

rm -f "${DB_PATH}"
touch "${DB_PATH}"

bash -n "${BACKEND_DIR}/scripts/pr66_accept.sh"
bash -n "${BACKEND_DIR}/scripts/pr66_verify.sh"

cd "${BACKEND_DIR}"
composer install --no-interaction --no-progress
php artisan migrate:fresh --force
cd "${REPO_DIR}"

ART_DIR="${ART_DIR}" SERVE_PORT="${SERVE_PORT}" bash "${BACKEND_DIR}/scripts/pr66_verify.sh"

if [[ ! -f "${ART_DIR}/verify_done.txt" ]]; then
  echo "[PR66][ACCEPT][FAIL] verify_done.txt not found" >&2
  exit 1
fi

GATE_MIGRATION_SAFETY="FAIL"
if grep -E "PASS[[:space:]]+Tests\\\\Unit\\\\Migrations\\\\MigrationSafetyTest" "${ART_DIR}/unit_tests.log" >/dev/null 2>&1; then
  GATE_MIGRATION_SAFETY="PASS"
fi

GATE_ROLLBACK="FAIL"
if grep -E "PASS[[:space:]]+Tests\\\\Unit\\\\Migrations\\\\MigrationRollbackSafetyTest" "${ART_DIR}/unit_tests.log" >/dev/null 2>&1; then
  GATE_ROLLBACK="PASS"
fi

GATE_NO_SILENT_CATCH="FAIL"
if grep -E "PASS[[:space:]]+Tests\\\\Unit\\\\Migrations\\\\MigrationNoSilentCatchTest" "${ART_DIR}/unit_tests.log" >/dev/null 2>&1; then
  GATE_NO_SILENT_CATCH="PASS"
fi

{
  echo "PR66 Acceptance Summary"
  echo "- pass_items:"
  echo "  - sqlite_migrate_fresh: PASS"
  echo "  - migration_lint: PASS"
  echo "  - unit_testsuite: PASS"
  echo "  - pr66_verify: PASS"
  echo "- key_outputs:"
  echo "  - serve_port: ${SERVE_PORT}"
  echo "  - verify_log: ${ART_DIR}/verify.log"
  echo "  - unit_tests_log: ${ART_DIR}/unit_tests.log"
  echo "  - migration_lint_log: ${ART_DIR}/migration_lint.log"
  echo "  - drop_hits: ${ART_DIR}/dropifexists_hits.txt"
  echo "  - catch_hits: ${ART_DIR}/catch_hits.txt"
  echo "- migration_fix_targets:"
  echo "  - backend/database/migrations/2025_12_17_165938_create_events_table.php"
  echo "  - backend/database/migrations/2026_01_22_090010_create_orders_table.php"
  echo "  - backend/database/migrations/2026_01_22_090020_create_benefit_grants_table.php"
  echo "  - backend/database/migrations/2026_01_22_090030_create_payment_events_table.php"
  echo "  - backend/database/migrations/2026_01_28_120000_create_ai_insights_table.php"
  echo "  - backend/database/migrations/2026_01_28_120100_create_ai_insight_feedback_table.php"
  echo "  - backend/database/migrations/2026_01_29_090000_create_scales_registry_table.php"
  echo "  - backend/database/migrations/2026_01_29_090010_create_scale_slugs_table.php"
  echo "  - backend/database/migrations/2026_02_08_040000_create_migration_index_audits_table.php"
  echo "- gate_tests:"
  echo "  - MigrationSafetyTest: ${GATE_MIGRATION_SAFETY}"
  echo "  - MigrationRollbackSafetyTest: ${GATE_ROLLBACK}"
  echo "  - MigrationNoSilentCatchTest: ${GATE_NO_SILENT_CATCH}"
} > "${ART_DIR}/summary.txt"

bash "${BACKEND_DIR}/scripts/sanitize_artifacts.sh" 66

if grep -R -n -E "FAP_ADMIN_TOKEN=|Authorization: Bearer|BEGIN PRIVATE KEY|password=|DB_PASSWORD=|/Users/|/home/|/private/" "${ART_DIR}" >/dev/null; then
  grep -R -n -E "FAP_ADMIN_TOKEN=|Authorization: Bearer|BEGIN PRIVATE KEY|password=|DB_PASSWORD=|/Users/|/home/|/private/" "${ART_DIR}" > "${ART_DIR}/sanitize_failures.txt" || true
  cat "${ART_DIR}/sanitize_failures.txt" >&2 || true
  echo "[PR66][ACCEPT][FAIL] artifact sanitization check failed" >&2
  exit 1
fi

bash -n "${BACKEND_DIR}/scripts/pr66_accept.sh"
bash -n "${BACKEND_DIR}/scripts/pr66_verify.sh"

echo "[PR66][ACCEPT] pass"
