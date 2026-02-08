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
ART_DIR="${BACKEND_DIR}/artifacts/pr64"
SERVE_PORT="${SERVE_PORT:-1864}"
DB_PATH="/tmp/pr64.sqlite"

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

rm -f "${DB_PATH}"
touch "${DB_PATH}"

bash -n "${BACKEND_DIR}/scripts/pr64_accept.sh"
bash -n "${BACKEND_DIR}/scripts/pr64_verify.sh"

cd "${BACKEND_DIR}"
composer install --no-interaction --no-progress
php artisan migrate:fresh --force
php artisan fap:scales:seed-default
php artisan fap:scales:sync-slugs
php artisan route:list | grep -E "api/v0\.3/attempts/(start|submit|\{id\}/result|\{id\}/report)" > "${ART_DIR}/route_attempts.txt"
cd "${REPO_DIR}"

ART_DIR="${ART_DIR}" SERVE_PORT="${SERVE_PORT}" bash "${BACKEND_DIR}/scripts/pr64_verify.sh"

{
  echo "PR64 Acceptance Summary"
  echo "- pass_items:"
  echo "  - sqlite_migrate_fresh: PASS"
  echo "  - scales_seed_sync: PASS"
  echo "  - pr64_verify: PASS"
  echo "- key_outputs:"
  echo "  - serve_port: ${SERVE_PORT}"
  echo "  - verify_done: ${ART_DIR}/verify_done.txt"
  echo "  - phpunit_log: ${ART_DIR}/phpunit.log"
  echo "  - pack_seed_config_log: ${ART_DIR}/pack_seed_config.log"
  echo "- route_methods:"
  sed "s/^/  - /" "${ART_DIR}/route_attempts.txt"
} > "${ART_DIR}/summary.txt"

bash "${BACKEND_DIR}/scripts/sanitize_artifacts.sh" 64

if grep -R -n -E "FAP_ADMIN_TOKEN=|Authorization: Bearer|BEGIN PRIVATE KEY|password=|DB_PASSWORD=|/Users/|/home/|/private/" "${ART_DIR}" >/dev/null; then
  grep -R -n -E "FAP_ADMIN_TOKEN=|Authorization: Bearer|BEGIN PRIVATE KEY|password=|DB_PASSWORD=|/Users/|/home/|/private/" "${ART_DIR}" > "${ART_DIR}/sanitize_failures.txt" || true
  cat "${ART_DIR}/sanitize_failures.txt" >&2 || true
  echo "[PR64][ACCEPT][FAIL] artifact sanitization check failed" >&2
  exit 1
fi

bash -n "${BACKEND_DIR}/scripts/pr64_accept.sh"
bash -n "${BACKEND_DIR}/scripts/pr64_verify.sh"

echo "[PR64][ACCEPT] pass"
