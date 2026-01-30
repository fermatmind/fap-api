#!/usr/bin/env bash
set -euo pipefail

export CI=true
export FAP_NONINTERACTIVE=1
export COMPOSER_NO_INTERACTION=1
export GIT_TERMINAL_PROMPT=0
export NO_COLOR=1

export DB_CONNECTION=sqlite
export DB_DATABASE="${DB_DATABASE:-/tmp/pr23.sqlite}"
export SERVE_PORT="${SERVE_PORT:-1823}"

SCRIPT_DIR="$(cd "$(dirname "${BASH_SOURCE[0]}")" && pwd)"
ROOT_DIR="$(cd "$SCRIPT_DIR/../.." && pwd)"
BACKEND_DIR="$ROOT_DIR/backend"
ART_DIR="$ROOT_DIR/backend/artifacts/pr23"

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

log "tests: V0_3"
(
  cd "$BACKEND_DIR"
  php artisan test --filter=V0_3
)

log "pr23 verify"
(
  cd "$ROOT_DIR"
  bash "$BACKEND_DIR/scripts/pr23_verify.sh"
)

log "summary"
cat <<TXT > "$ART_DIR/summary.txt"
PR23 acceptance summary
- status: ok
- serve_port: ${SERVE_PORT}
- db: sqlite
- smoke_url: http://127.0.0.1:${SERVE_PORT}/api/v0.3/boot
- scripts:
  - backend/scripts/pr23_verify.sh
- tables:
  - feature_flags
  - experiment_assignments
  - events.experiments_json
- artifacts:
  - backend/artifacts/pr23/curl_boot_a.json
  - backend/artifacts/pr23/curl_boot_b.json
  - backend/artifacts/pr23/curl_boot_a_repeat.json
  - backend/artifacts/pr23/curl_event.json
  - backend/artifacts/pr23/db_assertions.json
  - backend/artifacts/pr23/experiments_agg.json
  - backend/artifacts/pr23/server.log
  - backend/artifacts/pr23/verify.log
TXT

log "sanitize artifacts"
(
  cd "$ROOT_DIR"
  bash "$BACKEND_DIR/scripts/sanitize_artifacts.sh" 23
)

log "cleanup"
cleanup_port "$SERVE_PORT"
cleanup_port 18000
rm -f "$DB_DATABASE"

if lsof -nP -iTCP:"$SERVE_PORT" -sTCP:LISTEN >/dev/null 2>&1; then
  log "Port ${SERVE_PORT} still in use"
  exit 1
fi
