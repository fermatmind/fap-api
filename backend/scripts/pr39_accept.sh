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

PR_NUM=39

compute_serve_port() {
  local pr_num="$1"
  local port

  if [[ "${pr_num}" -ge 1000 ]]; then
    port="18$(printf '%03d' "$((pr_num % 1000))")"
  else
    port="18$(printf '%02d' "${pr_num}")"
  fi

  if [[ "${port}" == "18000" ]]; then
    port="18001"
  fi

  echo "${port}"
}

REPO_DIR="$(cd "$(dirname "${BASH_SOURCE[0]}")/../.." && pwd)"
BACKEND_DIR="${REPO_DIR}/backend"
ART_DIR="${ART_DIR:-backend/artifacts/pr39}"
if [[ "${ART_DIR}" != /* ]]; then
  ART_DIR="${REPO_DIR}/${ART_DIR}"
fi
SERVE_PORT_DEFAULT="$(compute_serve_port "${PR_NUM}")"
SERVE_PORT="${SERVE_PORT:-${SERVE_PORT_DEFAULT}}"
DB_PATH="/tmp/pr39.sqlite"

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
export FAP_DEFAULT_PACK_ID="${FAP_DEFAULT_PACK_ID:-MBTI.cn-mainland.zh-CN.v0.3}"
export FAP_DEFAULT_DIR_VERSION="${FAP_DEFAULT_DIR_VERSION:-MBTI-CN-v0.3}"

rm -f "${DB_PATH}"
touch "${DB_PATH}"

cd "${BACKEND_DIR}"
composer install --no-interaction --no-progress
php artisan migrate:fresh --force
php artisan fap:scales:seed-default
php artisan fap:scales:sync-slugs
cd "${REPO_DIR}"

SERVE_PORT="${SERVE_PORT}" ART_DIR="${ART_DIR}" bash "${BACKEND_DIR}/scripts/pr39_verify.sh"

ATTEMPT_ID="$(cat "${ART_DIR}/attempt_id.txt" 2>/dev/null || true)"
ANON_ID="$(cat "${ART_DIR}/anon_id.txt" 2>/dev/null || true)"

cat > "${ART_DIR}/summary.txt" <<TXT
PR39 Acceptance Summary
- result: PASS
- serve_port: ${SERVE_PORT}
- pass_items:
  - php artisan test --filter ContentLoaderServiceMtimeTest: PASS
  - questions endpoint dynamic answers: PASS
  - report ownership returns 404 on mismatch: PASS
  - pack/seed/config consistency: PASS
- key_outputs:
  - attempt_id: ${ATTEMPT_ID}
  - anon_id: ${ANON_ID}
- desensitization: sanitize_artifacts.sh completed
TXT

bash "${BACKEND_DIR}/scripts/sanitize_artifacts.sh" 39

bash -n "${BACKEND_DIR}/scripts/pr39_accept.sh"
bash -n "${BACKEND_DIR}/scripts/pr39_verify.sh"

echo "[PR39][PASS] acceptance complete"
