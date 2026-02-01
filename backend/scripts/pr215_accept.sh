#!/usr/bin/env bash
set -euo pipefail

export CI=true
export FAP_NONINTERACTIVE=1
export COMPOSER_NO_INTERACTION=1
export GIT_TERMINAL_PROMPT=0
export NO_COLOR=1

REPO_DIR="$(cd "$(dirname "${BASH_SOURCE[0]}")/../.." && pwd)"
BACKEND_DIR="${REPO_DIR}/backend"
ART_DIR="${ART_DIR:-${REPO_DIR}/backend/artifacts/pr215}"
SERVE_PORT="${SERVE_PORT:-1815}"

mkdir -p "${ART_DIR}"
rm -f "${ART_DIR}/summary.txt" || true

for p in "${SERVE_PORT}" 18000; do
  lsof -ti tcp:${p} | xargs -r kill -9 || true
  lsof -nP -iTCP:${p} -sTCP:LISTEN || true
  lsof -ti tcp:${p} | xargs -r kill -9 || true
  lsof -nP -iTCP:${p} -sTCP:LISTEN || true
  :
done

cd "${BACKEND_DIR}"

composer install --no-interaction --prefer-dist --no-progress

if [[ ! -f .env ]]; then
  if [[ -f .env.example ]]; then
    cp .env.example .env
  else
    touch .env
  fi
fi
php artisan key:generate --force || true

mkdir -p storage/framework/{cache,views,sessions} bootstrap/cache storage/app/private
chmod -R ug+rwX storage bootstrap/cache || true

export DB_CONNECTION=sqlite
export DB_DATABASE="${DB_DATABASE:-/tmp/pr215.sqlite}"
rm -f "${DB_DATABASE}" || true
touch "${DB_DATABASE}"
chmod 666 "${DB_DATABASE}" || true

php artisan config:clear || true
php artisan migrate --force --no-interaction
php artisan db:seed --class="Database\\Seeders\\CiScalesRegistrySeeder" --force --no-interaction || true
php artisan fap:scales:sync-slugs || true

SERVE_PORT="${SERVE_PORT}" ART_DIR="${ART_DIR}" bash "${REPO_DIR}/backend/scripts/pr215_verify.sh"

{
  echo "PR215 accept summary"
  echo "SERVE_PORT=${SERVE_PORT}"
  echo "DB_DATABASE=${DB_DATABASE}"
  echo "OK: $(date -u '+%Y-%m-%dT%H:%M:%SZ')"
  echo "Artifacts: ${ART_DIR}"
} > "${ART_DIR}/summary.txt"

rm -f "${DB_DATABASE}" || true
