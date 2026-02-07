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
ART_DIR="${BACKEND_DIR}/artifacts/pr51"
SERVE_PORT="${SERVE_PORT:-1851}"
DB_PATH="/tmp/pr51.sqlite"

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
export FAP_PACKS_ROOT="${FAP_PACKS_ROOT:-${REPO_DIR}/content_packages}"
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
php -r '
require "vendor/autoload.php";
$app = require "bootstrap/app.php";
$app->make(Illuminate\Contracts\Console\Kernel::class)->bootstrap();
(new Database\Seeders\Pr19CommerceSeeder())->run();
'
cd "${REPO_DIR}"

SERVE_PORT="${SERVE_PORT}" ART_DIR="${ART_DIR}" bash "${BACKEND_DIR}/scripts/pr51_verify.sh"

ATTEMPT_ID="$(cat "${ART_DIR}/attempt_id.txt" 2>/dev/null || true)"
ANON_ID="$(cat "${ART_DIR}/anon_id.txt" 2>/dev/null || true)"
QUESTION_COUNT="$(cat "${ART_DIR}/question_count.txt" 2>/dev/null || true)"
DEFAULT_PACK_ID="$(cat "${ART_DIR}/config_default_pack_id.txt" 2>/dev/null || true)"
STUB_ORDER_NO="$(cat "${ART_DIR}/order_stub_no.txt" 2>/dev/null || true)"
BILLING_ORDER_NO="$(cat "${ART_DIR}/order_billing_no.txt" 2>/dev/null || true)"

cat > "${ART_DIR}/summary.txt" <<TXT
PR51 Acceptance Summary
- pass_items:
  - payment_idempotency_scope_provider: OK
  - dynamic_answers_submit: OK
  - pack_seed_config_consistency: OK
  - middleware_fm_user_id_injection_guard: OK
- key_outputs:
  - attempt_id: ${ATTEMPT_ID}
  - anon_id: ${ANON_ID}
  - question_count(dynamic): ${QUESTION_COUNT}
  - default_pack_id: ${DEFAULT_PACK_ID}
  - order_stub: ${STUB_ORDER_NO}
  - order_billing: ${BILLING_ORDER_NO}
- smoke_urls:
  - http://127.0.0.1:${SERVE_PORT}/api/v0.3/scales/MBTI/questions
  - http://127.0.0.1:${SERVE_PORT}/api/v0.3/orders/stub
  - http://127.0.0.1:${SERVE_PORT}/api/v0.3/orders/billing
- schema_changes:
  - add unique index: orders(org_id, provider, idempotency_key)
  - keep rollback-compatible guard for legacy unique index
TXT

bash "${BACKEND_DIR}/scripts/sanitize_artifacts.sh" 51

bash -n "${BACKEND_DIR}/scripts/pr51_accept.sh"
bash -n "${BACKEND_DIR}/scripts/pr51_verify.sh"
