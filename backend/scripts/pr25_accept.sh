#!/usr/bin/env bash
set -euo pipefail

export CI=true
export FAP_NONINTERACTIVE=1
export COMPOSER_NO_INTERACTION=1
export GIT_TERMINAL_PROMPT=0
export NO_COLOR=1

export FAP_DEFAULT_PACK_ID="${FAP_DEFAULT_PACK_ID:-MBTI.cn-mainland.zh-CN.v0.2.1-TEST}"
export FAP_DEFAULT_DIR_VERSION="${FAP_DEFAULT_DIR_VERSION:-MBTI-CN-v0.2.1-TEST}"
export FAP_DEFAULT_REGION="${FAP_DEFAULT_REGION:-CN_MAINLAND}"
export FAP_DEFAULT_LOCALE="${FAP_DEFAULT_LOCALE:-zh-CN}"

export DB_CONNECTION=sqlite
export DB_DATABASE="${DB_DATABASE:-/tmp/pr25.sqlite}"
export SERVE_PORT="${SERVE_PORT:-1825}"

SCRIPT_DIR="$(cd "$(dirname "${BASH_SOURCE[0]}")" && pwd)"
ROOT_DIR="$(cd "${SCRIPT_DIR}/../.." && pwd)"
BACKEND_DIR="${ROOT_DIR}/backend"
ART_DIR="${BACKEND_DIR}/artifacts/pr25"

mkdir -p "${ART_DIR}"

cleanup_port() {
  local port="$1"
  local pids
  pids="$(lsof -ti tcp:"${port}" 2>/dev/null || true)"
  if [[ -n "${pids}" ]]; then
    kill -9 ${pids} || true
  fi
}

log() {
  echo "[$(date +'%Y-%m-%d %H:%M:%S')] $*"
}

log "Cleaning ports ${SERVE_PORT} and 18000"
cleanup_port "${SERVE_PORT}"
cleanup_port 18000

rm -f "${DB_DATABASE}"

log "composer install"
(
  cd "${BACKEND_DIR}"
  composer install --no-interaction --no-progress
)

log "migrate"
(
  cd "${BACKEND_DIR}"
  php artisan migrate --force
)

log "seed scales"
(
  cd "${BACKEND_DIR}"
  php artisan fap:scales:seed-default
  php artisan fap:scales:sync-slugs
)

log "pr25 verify"
(
  cd "${ROOT_DIR}"
  bash "${BACKEND_DIR}/scripts/pr25_verify.sh"
)

log "summary"
cat <<TXT > "${ART_DIR}/summary.txt"
PR25 acceptance summary
- status: ok
- serve_port: ${SERVE_PORT}
- db: sqlite (${DB_DATABASE})
- smoke_url: http://127.0.0.1:${SERVE_PORT}/api/v0.4/boot
- scripts:
  - backend/scripts/pr25_verify.sh
  - backend/scripts/ci_verify_mbti.sh
- tables:
  - assessments
  - assessment_assignments
  - organization_members.role (owner/admin/member/viewer)
- artifacts:
  - backend/artifacts/pr25/verify.log
  - backend/artifacts/pr25/server.log
  - backend/artifacts/pr25/progress.json
  - backend/artifacts/pr25/summary.json
  - backend/artifacts/pr25/summary.txt
TXT

log "sanitize artifacts"
(
  cd "${ROOT_DIR}"
  bash "${BACKEND_DIR}/scripts/sanitize_artifacts.sh" 25
)

log "cleanup"
if [[ -f "${ART_DIR}/server.pid" ]]; then
  SERVER_PID="$(cat "${ART_DIR}/server.pid")"
  if [[ -n "${SERVER_PID}" ]] && ps -p "${SERVER_PID}" >/dev/null 2>&1; then
    kill "${SERVER_PID}" >/dev/null 2>&1 || true
  fi
fi

cleanup_port "${SERVE_PORT}"
cleanup_port 18000
rm -f "${DB_DATABASE}"

if lsof -nP -iTCP:"${SERVE_PORT}" -sTCP:LISTEN >/dev/null 2>&1; then
  log "Port ${SERVE_PORT} still in use"
  exit 1
fi
