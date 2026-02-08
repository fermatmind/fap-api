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
ART_DIR="${BACKEND_DIR}/artifacts/pr62"
SERVE_PORT="${SERVE_PORT:-1862}"
DB_PATH="/tmp/pr62.sqlite"

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
export FAP_DEFAULT_REGION="${FAP_DEFAULT_REGION:-CN_MAINLAND}"
export FAP_DEFAULT_LOCALE="${FAP_DEFAULT_LOCALE:-zh-CN}"
export FAP_DEFAULT_PACK_ID="${FAP_DEFAULT_PACK_ID:-MBTI.cn-mainland.zh-CN.v0.2.2}"
export FAP_DEFAULT_DIR_VERSION="${FAP_DEFAULT_DIR_VERSION:-MBTI-CN-v0.2.2}"

rm -f "${DB_PATH}"
touch "${DB_PATH}"

cd "${BACKEND_DIR}"
composer install --no-interaction --no-progress
php artisan migrate:fresh --force
cd "${REPO_DIR}"

ART_DIR="${ART_DIR}" SERVE_PORT="${SERVE_PORT}" bash "${BACKEND_DIR}/scripts/pr62_verify.sh"

{
  echo "PR62 Acceptance Summary"
  echo "- pass_items:"
  echo "  - migrate_fresh_sqlite: PASS"
  echo "  - payment_webhook_atomicity_tests: PASS"
  echo "- key_outputs:"
  echo "  - serve_port: ${SERVE_PORT}"
  echo "  - verify_log: ${ART_DIR}/verify.log"
  echo "- changed_files:"
  git -C "${REPO_DIR}" diff --name-only | sed 's/^/  - /'
} > "${ART_DIR}/summary.txt"

bash "${BACKEND_DIR}/scripts/sanitize_artifacts.sh" 62
bash -n "${BACKEND_DIR}/scripts/pr62_accept.sh"
bash -n "${BACKEND_DIR}/scripts/pr62_verify.sh"
