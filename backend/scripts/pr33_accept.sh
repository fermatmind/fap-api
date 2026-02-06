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
ART_DIR="${BACKEND_DIR}/artifacts/pr33"
SERVE_PORT="${SERVE_PORT:-18033}"
DB_PATH="/tmp/pr33.sqlite"

cleanup_port() {
  local port="$1"
  lsof -nP -iTCP:"${port}" -sTCP:LISTEN || true
  pid_list="$(lsof -ti tcp:"${port}" || true)"
  if [[ -n "${pid_list}" ]]; then
    echo "${pid_list}" | xargs kill -9 || true
  fi
  lsof -nP -iTCP:"${port}" -sTCP:LISTEN || true
}

cleanup_port "${SERVE_PORT}"
cleanup_port 18000

export DB_CONNECTION=sqlite
export DB_DATABASE="${DB_PATH}"
rm -f "${DB_PATH}"
touch "${DB_PATH}"

cd "${BACKEND_DIR}"
composer install --no-interaction --no-progress
php artisan migrate:fresh --force
cd "${REPO_DIR}"

SERVE_PORT="${SERVE_PORT}" bash "${BACKEND_DIR}/scripts/pr33_verify.sh"

mkdir -p "${ART_DIR}"
ATTEMPT_ID="$(cat "${ART_DIR}/attempt_id.txt" 2>/dev/null || true)"
ORDER_NO="$(cat "${ART_DIR}/order_no.txt" 2>/dev/null || true)"
ORPHAN_ORDER_NO="$(cat "${ART_DIR}/orphan_order_no.txt" 2>/dev/null || true)"

cat > "${ART_DIR}/summary.txt" <<TXT
PR33 Summary
- pack/seed/config: OK
- order + webhook: OK (order_no=${ORDER_NO})
- orphan retry: OK (order_no=${ORPHAN_ORDER_NO})
- api_base: http://127.0.0.1:${SERVE_PORT}
- report_url: http://127.0.0.1:${SERVE_PORT}/api/v0.3/attempts/${ATTEMPT_ID}/report
- schema_changes:
  - payment_events: add status, processed_at, attempts, last_error_code, last_error_message; add indexes (provider, order_no) and status
  - skus: ensure unique index skus_sku_unique
TXT

bash "${BACKEND_DIR}/scripts/sanitize_artifacts.sh" 33

if [[ -f "${ART_DIR}/server.pid" ]]; then
  pid="$(cat "${ART_DIR}/server.pid" || true)"
  if [[ -n "${pid}" ]]; then
    kill "${pid}" >/dev/null 2>&1 || true
  fi
fi
cleanup_port "${SERVE_PORT}"
rm -f "${DB_PATH}"

bash -n "${BACKEND_DIR}/scripts/pr33_accept.sh"
bash -n "${BACKEND_DIR}/scripts/pr33_verify.sh"
