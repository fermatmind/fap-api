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
ART_DIR="${BACKEND_DIR}/artifacts/pr65"
SERVE_PORT="${SERVE_PORT:-1865}"
DB_PATH="/tmp/pr65.sqlite"

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
export FAP_DEFAULT_PACK_ID="${FAP_DEFAULT_PACK_ID:-MBTI.cn-mainland.zh-CN.v0.3}"
export FAP_DEFAULT_DIR_VERSION="${FAP_DEFAULT_DIR_VERSION:-MBTI-CN-v0.3}"

rm -f "${DB_PATH}"
touch "${DB_PATH}"

bash -n "${BACKEND_DIR}/scripts/pr65_accept.sh"
bash -n "${BACKEND_DIR}/scripts/pr65_verify.sh"

cd "${BACKEND_DIR}"
composer install --no-interaction --no-progress
php artisan migrate:fresh --force
php artisan fap:scales:seed-default
php artisan fap:scales:sync-slugs
cd "${REPO_DIR}"

ART_DIR="${ART_DIR}" SERVE_PORT="${SERVE_PORT}" bash "${BACKEND_DIR}/scripts/pr65_verify.sh"

BILLING_MISSING_TS_STATUS="$(cat "${ART_DIR}/billing_missing_timestamp.status" 2>/dev/null || true)"

{
  echo "PR65 Acceptance Summary"
  echo "- pass_items:"
  echo "  - sqlite_migrate_fresh: PASS"
  echo "  - pack_seed_config_consistency: PASS"
  echo "  - billing_missing_timestamp_smoke_404: PASS"
  echo "  - phpunit_billing_replay_tolerance: PASS"
  echo "  - phpunit_provider_uniqueness: PASS"
  echo "- key_outputs:"
  echo "  - serve_port: ${SERVE_PORT}"
  echo "  - verify_log: ${ART_DIR}/verify.log"
  echo "  - phpunit_billing_replay_log: ${ART_DIR}/phpunit_billing_replay.log"
  echo "  - phpunit_provider_uniqueness_log: ${ART_DIR}/phpunit_provider_uniqueness.log"
  echo "  - billing_missing_timestamp_status: ${BILLING_MISSING_TS_STATUS}"
  echo "- migration_index_changes:"
  echo "  - drop unique(payment_events.provider_event_id) if exists"
  echo "  - add unique(payment_events.provider, payment_events.provider_event_id)"
  echo "- smoke_urls:"
  echo "  - http://127.0.0.1:${SERVE_PORT}/api/healthz"
  echo "  - http://127.0.0.1:${SERVE_PORT}/api/v0.3/webhooks/payment/billing"
} > "${ART_DIR}/summary.txt"

bash "${BACKEND_DIR}/scripts/sanitize_artifacts.sh" 65

if grep -R -n -E "FAP_ADMIN_TOKEN=|Authorization: Bearer|BEGIN PRIVATE KEY|password=|DB_PASSWORD=|/Users/|/home/|/private/" "${ART_DIR}" >/dev/null; then
  grep -R -n -E "FAP_ADMIN_TOKEN=|Authorization: Bearer|BEGIN PRIVATE KEY|password=|DB_PASSWORD=|/Users/|/home/|/private/" "${ART_DIR}" > "${ART_DIR}/sanitize_failures.txt" || true
  cat "${ART_DIR}/sanitize_failures.txt" >&2 || true
  echo "[PR65][ACCEPT][FAIL] artifact sanitization check failed" >&2
  exit 1
fi

bash -n "${BACKEND_DIR}/scripts/pr65_accept.sh"
bash -n "${BACKEND_DIR}/scripts/pr65_verify.sh"

echo "[PR65][ACCEPT] pass"
