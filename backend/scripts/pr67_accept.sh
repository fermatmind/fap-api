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
ART_DIR="${BACKEND_DIR}/artifacts/pr67"
SERVE_PORT="${SERVE_PORT:-1867}"
DB_PATH="/tmp/pr67.sqlite"

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

bash -n "${BACKEND_DIR}/scripts/pr67_accept.sh"
bash -n "${BACKEND_DIR}/scripts/pr67_verify.sh"

cd "${BACKEND_DIR}"
composer install --no-interaction --no-progress
php artisan migrate:fresh --force
cd "${REPO_DIR}"

ART_DIR="${ART_DIR}" SERVE_PORT="${SERVE_PORT}" bash "${BACKEND_DIR}/scripts/pr67_verify.sh"

if [[ ! -f "${ART_DIR}/verify_done.txt" ]]; then
  echo "[PR67][ACCEPT][FAIL] verify_done.txt not found" >&2
  exit 1
fi

GATE_DRIVER_REVERSE_WEIGHT="FAIL"
if cd "${BACKEND_DIR}" && php artisan test tests/Unit/Psychometrics/GenericLikertDriverTest.php 2>&1 | tee "${ART_DIR}/driver_focus_test.log"; then
  GATE_DRIVER_REVERSE_WEIGHT="PASS"
fi

GATE_INVALID_WARNING="FAIL"
if cd "${BACKEND_DIR}" && php artisan test --filter test_invalid_answer_scores_zero_and_logs_warning_without_sensitive_fields 2>&1 | tee "${ART_DIR}/invalid_warning_test.log"; then
  GATE_INVALID_WARNING="PASS"
fi

if [[ "${GATE_DRIVER_REVERSE_WEIGHT}" != "PASS" || "${GATE_INVALID_WARNING}" != "PASS" ]]; then
  echo "[PR67][ACCEPT][FAIL] driver gates failed" >&2
  exit 1
fi

{
  echo "PR67 Acceptance Summary"
  echo "- pass_items:"
  echo "  - sqlite_migrate_fresh: PASS"
  echo "  - unit_testsuite: PASS"
  echo "  - pr67_verify: PASS"
  echo "  - driver_reverse_weight_gate: ${GATE_DRIVER_REVERSE_WEIGHT}"
  echo "  - invalid_answer_warning_gate: ${GATE_INVALID_WARNING}"
  echo "- key_outputs:"
  echo "  - serve_port: ${SERVE_PORT}"
  echo "  - verify_log: backend/artifacts/pr67/verify.log"
  echo "  - unit_tests_log: backend/artifacts/pr67/unit_tests.log"
  echo "  - driver_focus_log: backend/artifacts/pr67/driver_focus_test.log"
  echo "  - invalid_warning_log: backend/artifacts/pr67/invalid_warning_test.log"
  echo "- driver_assertions:"
  echo "  - reverse_weight_nested_rule: ${GATE_DRIVER_REVERSE_WEIGHT}"
  echo "  - invalid_answer_warning_contract: ${GATE_INVALID_WARNING}"
} > "${ART_DIR}/summary.txt"

cd "${REPO_DIR}"
bash "${BACKEND_DIR}/scripts/sanitize_artifacts.sh" 67

if grep -R -n -E "FAP_ADMIN_TOKEN=|Authorization: Bearer|BEGIN PRIVATE KEY|password=|DB_PASSWORD=|/Users/|/home/|/private/" "${ART_DIR}" >/dev/null; then
  grep -R -n -E "FAP_ADMIN_TOKEN=|Authorization: Bearer|BEGIN PRIVATE KEY|password=|DB_PASSWORD=|/Users/|/home/|/private/" "${ART_DIR}" > "${ART_DIR}/sanitize_failures.txt" || true
  cat "${ART_DIR}/sanitize_failures.txt" >&2 || true
  echo "[PR67][ACCEPT][FAIL] artifact sanitization check failed" >&2
  exit 1
fi

bash -n "${BACKEND_DIR}/scripts/pr67_accept.sh"
bash -n "${BACKEND_DIR}/scripts/pr67_verify.sh"

echo "[PR67][ACCEPT] pass"
