#!/usr/bin/env bash
set -euo pipefail

export CI=true
export FAP_NONINTERACTIVE=1
export COMPOSER_NO_INTERACTION=1
export GIT_TERMINAL_PROMPT=0
export NO_COLOR=1

export DB_CONNECTION=sqlite
export DB_DATABASE="${DB_DATABASE:-/tmp/pr24.sqlite}"
export CACHE_DRIVER=array
export SERVE_PORT="${SERVE_PORT:-1824}"

SCRIPT_DIR="$(cd "$(dirname "${BASH_SOURCE[0]}")" && pwd)"
ROOT_DIR="$(cd "$SCRIPT_DIR/../.." && pwd)"
BACKEND_DIR="$ROOT_DIR/backend"
ART_DIR="$ROOT_DIR/backend/artifacts/pr24"

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

# ✅ 新增：确保 package:discover 有合法 cache path
log "prepare laravel cache dirs"
bash "$BACKEND_DIR/scripts/ci/prepare_laravel_cache_dirs.sh"

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

log "pr24 verify"
(
  cd "$ROOT_DIR"
  bash "$BACKEND_DIR/scripts/pr24_verify_sitemap.sh"
)

log "summary"
slug_count="$(grep -E '<loc>' "$ART_DIR/sitemap.xml" | wc -l | tr -d ' ')"
etag="$(grep -i -E '^ETag:' "$ART_DIR/headers_200.txt" | head -n 1 | sed -E 's/^[Ee][Tt][Aa][Gg]:[[:space:]]*//')"
cache_control="$(grep -i -E '^Cache-Control:' "$ART_DIR/headers_200.txt" | head -n 1 | sed -E 's/^[Cc]ache-[Cc]ontrol:[[:space:]]*//')"
status_304="$(awk 'NR==1 {print $2}' "$ART_DIR/headers_304.txt" 2>/dev/null || true)"

cat <<TXT > "$ART_DIR/summary.txt"
PR24 acceptance summary
- status: ok
- serve_port: ${SERVE_PORT}
- db: sqlite
- slug_count: ${slug_count}
- etag: ${etag}
- cache_control: ${cache_control}
- if_none_match_304: ${status_304}
- artifacts:
  - backend/artifacts/pr24/sitemap.xml
  - backend/artifacts/pr24/headers_200.txt
  - backend/artifacts/pr24/headers_304.txt
  - backend/artifacts/pr24/server.log
  - backend/artifacts/pr24/summary.txt
TXT

log "sanitize artifacts"
(
  cd "$ROOT_DIR"
  bash "$BACKEND_DIR/scripts/sanitize_artifacts.sh" 24
)

log "cleanup"
if [[ -f "$ART_DIR/server.pid" ]]; then
  kill "$(cat "$ART_DIR/server.pid")" >/dev/null 2>&1 || true
fi
cleanup_port "$SERVE_PORT"
cleanup_port 18000
rm -f "$DB_DATABASE"

if lsof -nP -iTCP:"$SERVE_PORT" -sTCP:LISTEN >/dev/null 2>&1; then
  log "Port ${SERVE_PORT} still in use"
  exit 1
fi
