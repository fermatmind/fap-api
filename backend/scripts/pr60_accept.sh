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
ART_DIR="${BACKEND_DIR}/artifacts/pr60"
SERVE_PORT="${SERVE_PORT:-1860}"
DB_PATH="/tmp/pr60.sqlite"

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
  if [[ -f "${ART_DIR}/server.pid" ]]; then
    local pid
    pid="$(cat "${ART_DIR}/server.pid" || true)"
    if [[ -n "${pid}" ]] && ps -p "${pid}" >/dev/null 2>&1; then
      kill "${pid}" >/dev/null 2>&1 || true
    fi
  fi

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
export FAP_DEFAULT_REGION="${FAP_DEFAULT_REGION:-CN_MAINLAND}"
export FAP_DEFAULT_LOCALE="${FAP_DEFAULT_LOCALE:-zh-CN}"
export FAP_DEFAULT_PACK_ID="${FAP_DEFAULT_PACK_ID:-MBTI.cn-mainland.zh-CN.v0.2.2}"
export FAP_DEFAULT_DIR_VERSION="${FAP_DEFAULT_DIR_VERSION:-MBTI-CN-v0.2.2}"

rm -f "${DB_PATH}"
touch "${DB_PATH}"

cd "${BACKEND_DIR}"
composer install --no-interaction --no-progress
php artisan migrate:fresh --force
php artisan fap:scales:seed-default
php artisan fap:scales:sync-slugs
cd "${REPO_DIR}"

ART_DIR="${ART_DIR}" SERVE_PORT="${SERVE_PORT}" bash "${BACKEND_DIR}/scripts/pr60_verify.sh"
[[ -f "${ART_DIR}/verify_done.txt" ]] || { echo "verify script did not finish" >&2; exit 1; }

cd "${BACKEND_DIR}"
php artisan test --filter HighIdorOwnership404Test > "${ART_DIR}/phpunit_high_idor_ownership_404.txt" 2>&1
php artisan test --filter OrgIsolationAttemptsTest > "${ART_DIR}/phpunit_org_isolation_attempts.txt" 2>&1
php artisan test --filter CommerceOrderIdempotencyTest > "${ART_DIR}/phpunit_commerce_order_idempotency.txt" 2>&1
cd "${REPO_DIR}"

ABORT_403_REPORT="${ART_DIR}/abort_403_assertions.txt"
: > "${ABORT_403_REPORT}"
ABORT403_OK=1
for target in \
  "backend/app/Http/Controllers/API/V0_3/AttemptsController.php" \
  "backend/app/Http/Controllers/API/V0_3/AttemptReadController.php" \
  "backend/app/Http/Controllers/API/V0_3/CommerceController.php" \
  "backend/app/Services/Commerce/OrderManager.php"; do
  if grep -n "abort(403)" "${target}" > /tmp/pr60_abort403_tmp.txt; then
    ABORT403_OK=0
    echo "FAIL ${target}" >> "${ABORT_403_REPORT}"
    cat /tmp/pr60_abort403_tmp.txt >> "${ABORT_403_REPORT}"
  else
    echo "PASS ${target}" >> "${ABORT_403_REPORT}"
  fi
done
rm -f /tmp/pr60_abort403_tmp.txt
if [[ "${ABORT403_OK}" != "1" ]]; then
  echo "abort(403) assertion failed" >&2
  cat "${ABORT_403_REPORT}" >&2
  exit 1
fi

ATTEMPT_ID="$(cat "${ART_DIR}/attempt_id.txt" 2>/dev/null || true)"
ORDER_NO="$(cat "${ART_DIR}/order_no.txt" 2>/dev/null || true)"
ANSWER_COUNT_LINE="$(cat "${ART_DIR}/answers_meta.txt" 2>/dev/null || true)"

cat > "${ART_DIR}/summary.txt" <<TXT
PR60 Acceptance Summary
- pass_items:
  - migrate_fresh_sqlite: OK
  - scales_seed_and_slug_sync: OK
  - verify_script_idor_404: PASS
  - phpunit_high_idor_ownership_404: PASS
  - phpunit_org_isolation_attempts: PASS
  - phpunit_commerce_order_idempotency: PASS
  - abort_403_assertion: PASS
- key_outputs:
  - serve_port: ${SERVE_PORT}
  - attempt_id: ${ATTEMPT_ID}
  - order_no: ${ORDER_NO}
  - ${ANSWER_COUNT_LINE}
  - verify_log: ${ART_DIR}/verify.log
  - server_log: ${ART_DIR}/server.log
- smoke_urls:
  - http://127.0.0.1:${SERVE_PORT}/api/healthz
  - http://127.0.0.1:${SERVE_PORT}/api/v0.3/attempts/${ATTEMPT_ID}/result
  - http://127.0.0.1:${SERVE_PORT}/api/v0.3/orders/${ORDER_NO}
  - http://127.0.0.1:${SERVE_PORT}/api/v0.3/attempts/${ATTEMPT_ID}/stats
- schema_changes:
  - none (no migration added in PR60)
TXT

bash "${BACKEND_DIR}/scripts/sanitize_artifacts.sh" 60

bash -n "${BACKEND_DIR}/scripts/pr60_accept.sh"
bash -n "${BACKEND_DIR}/scripts/pr60_verify.sh"

echo "[PR60][ACCEPT] pass"
