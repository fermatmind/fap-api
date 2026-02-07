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

PR_NUM="38"
SERVE_PORT="1838"
ART_DIR="backend/artifacts/pr38"
REPO_DIR="$(cd "$(dirname "${BASH_SOURCE[0]}")/../.." && pwd)"
BACKEND_DIR="${REPO_DIR}/backend"
OUT_DIR="${REPO_DIR}/${ART_DIR}"

mkdir -p "${OUT_DIR}"

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
health_ok=0
for i in $(seq 1 40); do
  code="$(curl -sS -o /dev/null -w "%{http_code}" "${API_BASE}/api/v0.2/health" || true)"
  if [ "${code}" = "200" ]; then
    curl -sS "${API_BASE}/api/v0.2/health" > "${OUT_DIR}/health.json" || true
    health_ok=1
    break
  fi
  sleep 1
done

if [ "${health_ok}" != "1" ]; then
  php artisan route:list --path=api/v0.2/health > "${OUT_DIR}/health_route.txt" || true
  cat > "${OUT_DIR}/health.json" <<'JSON'
{"ok":false,"note":"serve unavailable in sandbox; see health_route.txt"}
JSON
fi

php artisan test --filter "ContentLoaderMtimeCacheTest|AttemptReportOwnershipTest" | tee "${OUT_DIR}/phpunit.log"
