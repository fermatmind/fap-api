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

export FAP_PACKS_ROOT="${FAP_PACKS_ROOT:-$(cd "$(dirname "$0")/../.." && pwd)/content_packages}"
export FAP_DEFAULT_REGION="${FAP_DEFAULT_REGION:-CN_MAINLAND}"
export FAP_DEFAULT_LOCALE="${FAP_DEFAULT_LOCALE:-zh-CN}"
export FAP_DEFAULT_PACK_ID="${FAP_DEFAULT_PACK_ID:-MBTI.cn-mainland.zh-CN.v0.2.2}"
export FAP_DEFAULT_DIR_VERSION="${FAP_DEFAULT_DIR_VERSION:-MBTI-CN-v0.2.2}"
export FAP_CONTENT_PACKAGE_VERSION="${FAP_CONTENT_PACKAGE_VERSION:-MBTI-CN-v0.2.2}"

export APP_ENV="${APP_ENV:-testing}"
export CACHE_STORE="${CACHE_STORE:-array}"
export QUEUE_CONNECTION="${QUEUE_CONNECTION:-sync}"
export DB_CONNECTION="${DB_CONNECTION:-sqlite}"
export DB_DATABASE="${DB_DATABASE:-/tmp/pr30_${GITHUB_RUN_ID:-local}_${GITHUB_RUN_ATTEMPT:-0}.sqlite}"

export FAP_RATE_LIMIT_PUBLIC_PER_MINUTE="${FAP_RATE_LIMIT_PUBLIC_PER_MINUTE:-12}"
export FAP_RATE_LIMIT_AUTH_PER_MINUTE="${FAP_RATE_LIMIT_AUTH_PER_MINUTE:-6}"
export FAP_RATE_LIMIT_ATTEMPT_SUBMIT_PER_MINUTE="${FAP_RATE_LIMIT_ATTEMPT_SUBMIT_PER_MINUTE:-6}"
export FAP_RATE_LIMIT_WEBHOOK_PER_MINUTE="${FAP_RATE_LIMIT_WEBHOOK_PER_MINUTE:-30}"

SERVE_PORT="${SERVE_PORT:-1830}"

SCRIPT_DIR="$(cd "$(dirname "${BASH_SOURCE[0]}")" && pwd)"
ROOT_DIR="$(cd "${SCRIPT_DIR}/../.." && pwd)"
BACKEND_DIR="${ROOT_DIR}/backend"
ART_DIR="${BACKEND_DIR}/artifacts/pr30"
LOG_DIR="${ART_DIR}/logs"

mkdir -p "${ART_DIR}" "${LOG_DIR}"

cleanup_port() {
  local port="$1"
  local pids
  pids="$(lsof -ti tcp:"${port}" 2>/dev/null || true)"
  if [[ -n "${pids}" ]]; then
    kill -9 ${pids} || true
  fi
}

wait_health() {
  local url="$1"
  for _ in $(seq 1 80); do
    if curl -fsS "${url}" >/dev/null 2>&1; then
      return 0
    fi
    sleep 0.25
  done
  return 1
}

log() {
  echo "[$(date +'%Y-%m-%d %H:%M:%S')] $*"
}

log "Cleaning ports ${SERVE_PORT} and 18000"
cleanup_port "${SERVE_PORT}"
cleanup_port 18000

PORT_IN_USE=0
if lsof -nP -iTCP:"${SERVE_PORT}" -sTCP:LISTEN >/dev/null 2>&1; then
  PORT_IN_USE=1
  log "Port ${SERVE_PORT} already in use; reusing existing server"
fi

if [[ "${PORT_IN_USE}" -eq 0 ]]; then
  rm -f "${DB_DATABASE}"
fi

log "Prepare Laravel cache dirs"
bash "${BACKEND_DIR}/scripts/ci/prepare_laravel_cache_dirs.sh"

if [[ "${PORT_IN_USE}" -eq 0 ]]; then
  log "Composer install"
  (cd "${BACKEND_DIR}" && composer install --no-interaction --no-progress)
fi

if [[ "${PORT_IN_USE}" -eq 0 ]]; then
  log "Migrate"
  (cd "${BACKEND_DIR}" && php artisan migrate --force)
fi

if [[ "${PORT_IN_USE}" -eq 0 ]]; then
  log "Seed scales"
  (cd "${BACKEND_DIR}" && php artisan fap:scales:seed-default && php artisan fap:scales:sync-slugs)
fi

if [[ "${PORT_IN_USE}" -eq 0 ]]; then
  log "Start server"
  php "${BACKEND_DIR}/artisan" serve --host=127.0.0.1 --port="${SERVE_PORT}" > "${ART_DIR}/server.log" 2>&1 &
  SERVER_PID=$!
  echo "${SERVER_PID}" > "${ART_DIR}/server.pid"
fi

if ! wait_health "http://127.0.0.1:${SERVE_PORT}/api/healthz"; then
  log "Server healthz failed"
  tail -n 120 "${ART_DIR}/server.log" || true
  exit 1
fi

log "Run pr30 verify"
(cd "${ROOT_DIR}" && SERVE_PORT="${SERVE_PORT}" ART_DIR="${ART_DIR}" bash "${BACKEND_DIR}/scripts/pr30_verify.sh")

ATTEMPT_ID=""
if [[ -f "${ART_DIR}/attempt_id.txt" ]]; then
  ATTEMPT_ID="$(cat "${ART_DIR}/attempt_id.txt" | tr -d '\r' || true)"
fi

log "Write summary"
cat <<TXT > "${ART_DIR}/summary.txt"
PR30 Acceptance Summary
- verify: backend/scripts/pr30_verify.sh
- serve_port: ${SERVE_PORT}
- db: sqlite (${DB_DATABASE})
- attempt_id: ${ATTEMPT_ID}
- rate_limit: ${ART_DIR}/rate_limit.txt
Artifacts:
- backend/artifacts/pr30/healthz.json
- backend/artifacts/pr30/questions.json
- backend/artifacts/pr30/attempt_start.json
- backend/artifacts/pr30/submit.json
- backend/artifacts/pr30/submit_resp.json
- backend/artifacts/pr30/report.json
- backend/artifacts/pr30/rate_limit.txt
- backend/artifacts/pr30/summary.txt
TXT

log "Sanitize artifacts"
(cd "${ROOT_DIR}" && bash "${BACKEND_DIR}/scripts/sanitize_artifacts.sh" 30)

log "Cleanup"
if [[ "${PORT_IN_USE}" -eq 0 ]]; then
  if [[ -f "${ART_DIR}/server.pid" ]]; then
    PID="$(cat "${ART_DIR}/server.pid" || true)"
    if [[ -n "${PID}" ]] && ps -p "${PID}" >/dev/null 2>&1; then
      kill "${PID}" >/dev/null 2>&1 || true
    fi
  fi

  cleanup_port "${SERVE_PORT}"
  cleanup_port 18000
  rm -f "${DB_DATABASE}"

  if lsof -nP -iTCP:"${SERVE_PORT}" -sTCP:LISTEN >/dev/null 2>&1; then
    log "Port ${SERVE_PORT} still in use"
    exit 1
  fi
fi
