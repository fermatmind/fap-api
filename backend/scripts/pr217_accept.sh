#!/usr/bin/env bash
set -euo pipefail

export CI=true
export FAP_NONINTERACTIVE=1
export COMPOSER_NO_INTERACTION=1
export GIT_TERMINAL_PROMPT=0
export NO_COLOR=1

SCRIPT_DIR="$(cd "$(dirname "${BASH_SOURCE[0]}")" && pwd)"
BACKEND_DIR="$(cd "${SCRIPT_DIR}/.." && pwd)"
REPO_DIR="$(cd "${BACKEND_DIR}/.." && pwd)"

SERVE_PORT="${SERVE_PORT:-18217}"
ART_DIR="${ART_DIR:-${BACKEND_DIR}/artifacts/pr217}"
mkdir -p "${ART_DIR}"

SERVE_LOG_RAW="/tmp/pr217_server_raw.log"
SERVE_LOG="${ART_DIR}/server.log"

redact() {
  sed -E \
    -e 's#(/Users|/home)/[^ ]+#/REDACTED#g' \
    -e 's#Authorization: Bearer [^[:space:]]+#Authorization: Bearer REDACTED#g' \
    -e 's#(FAP_ADMIN_TOKEN|DB_PASSWORD|password)=([^[:space:]]+)#\1=REDACTED#g'
}

sanitize_server_log() {
  if [ -f "${SERVE_LOG_RAW}" ]; then
    redact < "${SERVE_LOG_RAW}" > "${SERVE_LOG}"
  fi
}

fail() {
  local code=$?
  set +e
  sanitize_server_log
  {
    echo "[FAIL] pr217_accept failed"
    echo "reason=server_or_verify_failed"
  } | tee "${ART_DIR}/summary.txt"

  if [ -f "${SERVE_LOG}" ]; then
    echo "--- server.log (tail) ---"
    tail -n 200 "${SERVE_LOG}"
  fi
  if [ -f "${BACKEND_DIR}/storage/logs/laravel.log" ]; then
    echo "--- laravel.log (tail) ---"
    tail -n 200 "${BACKEND_DIR}/storage/logs/laravel.log" | redact
  fi
  exit "${code}"
}
trap fail ERR

# kill ports
for p in "${SERVE_PORT}" 18000; do
  lsof -ti tcp:${p} | xargs -r kill -9 || true
done

DB_FILE="/tmp/pr217.sqlite"
rm -f "${DB_FILE}"
touch "${DB_FILE}"
chmod 666 "${DB_FILE}"

cd "${BACKEND_DIR}"
composer install --no-interaction --prefer-dist --no-progress

# env
cp -n .env.example .env || true
php artisan key:generate --force || true
php artisan config:clear || true

# migrate + seed
export APP_ENV=testing
export DB_CONNECTION=sqlite
export DB_DATABASE="${DB_FILE}"
export QUEUE_CONNECTION=sync

php artisan migrate --force
php artisan db:seed --class="Database\\Seeders\\CiScalesRegistrySeeder" --force --no-interaction || true
php artisan fap:scales:sync-slugs || true

# start server
: > "${SERVE_LOG_RAW}"
php artisan serve --host=127.0.0.1 --port="${SERVE_PORT}" >"${SERVE_LOG_RAW}" 2>&1 &
PID=$!
echo "${PID}" > "${ART_DIR}/server.pid"

cleanup() {
  kill "${PID}" >/dev/null 2>&1 || true
  lsof -ti tcp:${SERVE_PORT} | xargs -r kill -9 || true
  sanitize_server_log
}
trap cleanup EXIT

# wait health
API="http://127.0.0.1:${SERVE_PORT}"
for i in $(seq 1 60); do
  curl -fsS "${API}/api/healthz" >/dev/null 2>&1 && break
  sleep 0.2
done
curl -fsS "${API}/api/healthz" > "${ART_DIR}/health.json"

# run verify
bash "${SCRIPT_DIR}/pr217_verify.sh"

sanitize_server_log

echo "[OK] pr217 accept passed" | tee "${ART_DIR}/summary.txt"
