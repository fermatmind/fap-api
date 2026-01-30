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
export DB_DATABASE="${DB_DATABASE:-/tmp/pr22.sqlite}"
export SERVE_PORT="${SERVE_PORT:-1822}"

SCRIPT_DIR="$(cd "$(dirname "${BASH_SOURCE[0]}")" && pwd)"
ROOT_DIR="$(cd "$SCRIPT_DIR/../.." && pwd)"
BACKEND_DIR="$ROOT_DIR/backend"
ART_DIR="$ROOT_DIR/backend/artifacts/pr22"

mkdir -p "$ART_DIR"

cleanup_port() {
  local port="$1"
  local pids
  pids="$(lsof -ti tcp:"$port" 2>/dev/null || true)"
  if [[ -n "$pids" ]]; then
    kill -9 $pids || true
  fi
}

log() {
  echo "[$(date +'%Y-%m-%d %H:%M:%S')] $*"
}

log "Cleaning ports ${SERVE_PORT} and 18000"
cleanup_port "$SERVE_PORT"
cleanup_port 18000

rm -f "$DB_DATABASE"

log "composer install"
(
  cd "$BACKEND_DIR"
  composer install --no-interaction --no-progress
)

log "migrate"
(
  cd "$BACKEND_DIR"
  php artisan migrate --force
)

log "seed default scales"
(
  cd "$BACKEND_DIR"
  php artisan fap:scales:seed-default
  php artisan fap:scales:sync-slugs
  php artisan db:seed --class=Pr16IqRavenDemoSeeder
)

log "verify boot v0.4"
(
  cd "$ROOT_DIR"
  bash "$BACKEND_DIR/scripts/pr22_verify_boot_v0_4.sh"
)

log "summary"
cat <<TXT > "$ART_DIR/summary.txt"
PR22 acceptance summary
- status: ok
- serve_port: ${SERVE_PORT}
- db: sqlite (${DB_DATABASE})
- scripts:
  - backend/scripts/pr22_verify_boot_v0_4.sh
  - backend/scripts/ci_verify_mbti.sh
- config files:
  - backend/config/regions.php
  - backend/config/payments.php
  - backend/config/cdn_map.php
- artifacts:
  - backend/artifacts/pr22/boot_cn.json
  - backend/artifacts/pr22/boot_us.json
  - backend/artifacts/pr22/headers_cn.txt
  - backend/artifacts/pr22/headers_us.txt
  - backend/artifacts/pr22/verify.log
  - backend/artifacts/pr22/server.log
TXT

log "sanitize artifacts"
(
  cd "$ROOT_DIR"
  bash "$BACKEND_DIR/scripts/sanitize_artifacts.sh" 22
)

log "cleanup"
cleanup_port "$SERVE_PORT"
cleanup_port 18000
rm -f "$DB_DATABASE"

if lsof -nP -iTCP:"$SERVE_PORT" -sTCP:LISTEN >/dev/null 2>&1; then
  log "Port ${SERVE_PORT} still in use"
  exit 1
fi
