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

SERVE_PORT="${SERVE_PORT:-1827}"
ART_DIR="${ART_DIR:-${BACKEND_DIR}/artifacts/pr27}"
LOG_DIR="${ART_DIR}/logs"
DB_FILE="${DB_DATABASE:-/tmp/pr27.sqlite}"

export APP_ENV=testing
export DB_CONNECTION=sqlite
export DB_DATABASE="${DB_FILE}"
export QUEUE_CONNECTION=sync

cleanup_port() {
  local p="$1"
  lsof -ti tcp:"${p}" | xargs -r kill -9 || true
}

mkdir -p "${LOG_DIR}"

cleanup_port "${SERVE_PORT}"
cleanup_port 18000

rm -f "${DB_FILE}"

cd "${BACKEND_DIR}"

if [[ ! -f ".env" ]]; then
  cp -a .env.example .env
fi

composer install --no-interaction --no-progress

php artisan key:generate --force >/dev/null 2>&1 || true

php artisan migrate --force > "${ART_DIR}/migrate.log"

php artisan db:seed --class=ScaleRegistrySeeder > "${ART_DIR}/seed_scale_registry.log"
php artisan db:seed --class=Pr19CommerceSeeder > "${ART_DIR}/seed_commerce.log"

ART_DIR="${ART_DIR}" SERVE_PORT="${SERVE_PORT}" bash "${BACKEND_DIR}/scripts/pr27_verify.sh"

ATTEMPT_ID="$(cat "${ART_DIR}/attempt_id.txt" 2>/dev/null || true)"
RESULT_ID="$(cat "${ART_DIR}/result_id.txt" 2>/dev/null || true)"
TYPE_CODE="$(cat "${ART_DIR}/type_code.txt" 2>/dev/null || true)"
PACK_ID="$(grep -E '^config_pack_id=' "${ART_DIR}/config_check.txt" 2>/dev/null | sed -E 's/^config_pack_id=//' || true)"
DIR_VERSION="$(grep -E '^config_dir_version=' "${ART_DIR}/config_check.txt" 2>/dev/null | sed -E 's/^config_dir_version=//' || true)"

{
  echo "PR27 Summary"
  echo "- timestamp: $(date -u +%Y-%m-%dT%H:%M:%SZ)"
  echo "- pack_id: ${PACK_ID}"
  echo "- dir_version: ${DIR_VERSION}"
  echo "- attempt_id: ${ATTEMPT_ID}"
  echo "- result_id: ${RESULT_ID}"
  echo "- type_code: ${TYPE_CODE}"
  echo "- smoke_url: http://127.0.0.1:${SERVE_PORT}/api/v0.2/healthz"
  echo "- migrations:"
  grep -E 'Migrating:|Migrated:' "${ART_DIR}/migrate.log" | sed -E 's/^[^:]+: /  - /' || true
} > "${ART_DIR}/summary.txt"

cd "${REPO_DIR}"
bash "backend/scripts/sanitize_artifacts.sh" 27

if [[ -f "${ART_DIR}/server.pid" ]]; then
  kill "$(cat "${ART_DIR}/server.pid")" >/dev/null 2>&1 || true
fi
cleanup_port "${SERVE_PORT}"
rm -f "${DB_FILE}"
