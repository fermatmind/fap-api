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
export COMPOSER_CACHE_DIR="${COMPOSER_CACHE_DIR:-/tmp/composer-cache}"

export APP_ENV="${APP_ENV:-testing}"
export CACHE_STORE="${CACHE_STORE:-array}"
export QUEUE_CONNECTION="${QUEUE_CONNECTION:-sync}"

export FAP_PACKS_DRIVER="${FAP_PACKS_DRIVER:-local}"
export FAP_PACKS_ROOT="${FAP_PACKS_ROOT:-$(cd "$(dirname "$0")/../.." && pwd)/content_packages}"
export FAP_DEFAULT_REGION="${FAP_DEFAULT_REGION:-CN_MAINLAND}"
export FAP_DEFAULT_LOCALE="${FAP_DEFAULT_LOCALE:-zh-CN}"
export FAP_DEFAULT_PACK_ID="${FAP_DEFAULT_PACK_ID:-MBTI.cn-mainland.zh-CN.v0.3}"
export FAP_DEFAULT_DIR_VERSION="${FAP_DEFAULT_DIR_VERSION:-MBTI-CN-v0.3}"

export DB_CONNECTION=sqlite
export DB_DATABASE=/tmp/pr32.sqlite

SERVE_PORT="${SERVE_PORT:-1832}"

SCRIPT_DIR="$(cd "$(dirname "${BASH_SOURCE[0]}")" && pwd)"
ROOT_DIR="$(cd "${SCRIPT_DIR}/../.." && pwd)"
BACKEND_DIR="${ROOT_DIR}/backend"
ART_DIR="${BACKEND_DIR}/artifacts/pr32"
LOG_DIR="${ART_DIR}/logs"

mkdir -p "${ART_DIR}" "${LOG_DIR}"
mkdir -p "${COMPOSER_CACHE_DIR}" || true

cleanup_port() {
  local port="$1"
  lsof -nP -iTCP:"${port}" -sTCP:LISTEN || true
  local pids
  pids="$(lsof -ti tcp:"${port}" 2>/dev/null || true)"
  if [[ -n "${pids}" ]]; then
    echo "${pids}" | xargs kill -9 || true
  fi
  lsof -nP -iTCP:"${port}" -sTCP:LISTEN || true
}

log() {
  echo "[$(date +'%Y-%m-%d %H:%M:%S')] $*"
}

log "Cleaning ports ${SERVE_PORT} and 18000"
cleanup_port "${SERVE_PORT}"
cleanup_port 18000

log "Composer install"
(cd "${BACKEND_DIR}" && composer install --no-interaction --no-progress)

log "Migrate fresh"
(cd "${BACKEND_DIR}" && php artisan migrate:fresh --force)

log "Seed database"
(cd "${BACKEND_DIR}" && php artisan db:seed --force)
(cd "${BACKEND_DIR}" && php artisan db:seed --class="Database\\Seeders\\ScaleRegistrySeeder" --force)

log "Run pr32 verify"
(cd "${ROOT_DIR}" && SERVE_PORT="${SERVE_PORT}" ART_DIR="${ART_DIR}" bash "${BACKEND_DIR}/scripts/pr32_verify.sh")

MBTI_ATTEMPT_ID=""
BIG5_ATTEMPT_ID=""
if [[ -f "${ART_DIR}/mbti_attempt_id.txt" ]]; then
  MBTI_ATTEMPT_ID="$(cat "${ART_DIR}/mbti_attempt_id.txt" | tr -d '\r' || true)"
fi
if [[ -f "${ART_DIR}/big5_attempt_id.txt" ]]; then
  BIG5_ATTEMPT_ID="$(cat "${ART_DIR}/big5_attempt_id.txt" | tr -d '\r' || true)"
fi

log "Write summary"
cat <<TXT > "${ART_DIR}/summary.txt"
PR32 Acceptance Summary
- verify: backend/scripts/pr32_verify.sh
- serve_port: ${SERVE_PORT}
- smoke_urls:
  - /api/v0.3/scales/MBTI/questions
  - /api/v0.3/scales/BIG5/questions
- mbti_attempt_id: ${MBTI_ATTEMPT_ID}
- big5_attempt_id: ${BIG5_ATTEMPT_ID}
- schema_changes:
  - scales_registry: add assessment_driver (string, nullable)
Artifacts:
- backend/artifacts/pr32/pack_consistency.txt
- backend/artifacts/pr32/mbti_questions.json
- backend/artifacts/pr32/mbti_attempt_start.json
- backend/artifacts/pr32/mbti_submit_resp.json
- backend/artifacts/pr32/big5_questions.json
- backend/artifacts/pr32/big5_attempt_start.json
- backend/artifacts/pr32/big5_submit_resp.json
- backend/artifacts/pr32/summary.txt
TXT

log "Sanitize artifacts"
(cd "${ROOT_DIR}" && bash "${BACKEND_DIR}/scripts/sanitize_artifacts.sh" 32)

log "Cleanup"
if [[ -f "${ART_DIR}/server.pid" ]]; then
  PID="$(cat "${ART_DIR}/server.pid" || true)"
  if [[ -n "${PID}" ]] && ps -p "${PID}" >/dev/null 2>&1; then
    kill "${PID}" >/dev/null 2>&1 || true
  fi
fi

cleanup_port "${SERVE_PORT}"
cleanup_port 18000
rm -f "${DB_DATABASE}"

log "Shellcheck"
bash -n "${BACKEND_DIR}/scripts/pr32_accept.sh"
bash -n "${BACKEND_DIR}/scripts/pr32_verify.sh"
