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

PR_NUM="36"
SERVE_PORT="1836"
ART_DIR="backend/artifacts/pr36"
REPO_DIR="$(cd "$(dirname "${BASH_SOURCE[0]}")/../.." && pwd)"
BACKEND_DIR="${REPO_DIR}/backend"

mkdir -p "${REPO_DIR}/${ART_DIR}"
OUT_DIR="${REPO_DIR}/${ART_DIR}"

echo "[PR${PR_NUM}] verify starting" | tee "${OUT_DIR}/verify.log"

cd "${BACKEND_DIR}"
php artisan serve --host=127.0.0.1 --port="${SERVE_PORT}" >"${OUT_DIR}/server.log" 2>&1 &
SRV_PID=$!
echo "${SRV_PID}" > "${OUT_DIR}/server.pid"

cleanup() {
  kill "${SRV_PID}" >/dev/null 2>&1 || true
  pid_list="$(lsof -ti tcp:${SERVE_PORT} || true)"
  [ -n "${pid_list}" ] && echo "${pid_list}" | xargs kill -9 || true
}
trap cleanup EXIT

API_BASE="http://127.0.0.1:${SERVE_PORT}"

for i in $(seq 1 40); do
  code="$(curl -sS -o /dev/null -w "%{http_code}" "${API_BASE}/api/v0.2/health" || true)"
  [ "${code}" = "200" ] && break
  sleep 1
done

curl -sS "${API_BASE}/api/v0.2/health" > "${OUT_DIR}/health.json"

echo "[PR${PR_NUM}] run unit test: GenericLikertDriverTest" | tee -a "${OUT_DIR}/verify.log"
php artisan test --filter "GenericLikertDriverTest" | tee "${OUT_DIR}/phpunit.log"
