#!/usr/bin/env bash
set -euo pipefail

export CI=true
export FAP_NONINTERACTIVE=1
export COMPOSER_NO_INTERACTION=1
export GIT_TERMINAL_PROMPT=0
export NO_COLOR=1

SERVE_PORT="${SERVE_PORT:-1824}"
API_BASE="http://127.0.0.1:${SERVE_PORT}"
URL_PREFIX="${SEO_TESTS_URL_PREFIX:-https://fermatmind.com/tests/}"
URL_PREFIX="${URL_PREFIX%/}/"
export SEO_TESTS_URL_PREFIX="${URL_PREFIX}"

SCRIPT_DIR="$(cd "$(dirname "${BASH_SOURCE[0]}")" && pwd)"
ROOT_DIR="$(cd "$SCRIPT_DIR/../.." && pwd)"
BACKEND_DIR="$ROOT_DIR/backend"
ART_DIR="$ROOT_DIR/backend/artifacts/pr24"

mkdir -p "$ART_DIR"
LOG_FILE="$ART_DIR/verify.log"
: > "$LOG_FILE"

log() {
  echo "[$(date +'%Y-%m-%d %H:%M:%S')] $*" | tee -a "$LOG_FILE"
}

cleanup_port() {
  local port="$1"
  local pids
  pids="$(lsof -ti tcp:"$port" 2>/dev/null || true)"
  if [[ -n "$pids" ]]; then
    kill -9 $pids || true
  fi
}

log "Cleaning ports ${SERVE_PORT} and 18000"
cleanup_port "$SERVE_PORT"
cleanup_port 18000

log "Clearing application cache"
(
  cd "$BACKEND_DIR"
  php artisan cache:clear >/dev/null 2>&1 || true
)

SERVER_PID=""
cleanup() {
  if [[ -n "$SERVER_PID" ]]; then
    kill "$SERVER_PID" >/dev/null 2>&1 || true
  fi
  cleanup_port "$SERVE_PORT"
  cleanup_port 18000
}
trap cleanup EXIT

log "Starting server on port ${SERVE_PORT}"
(
  cd "$BACKEND_DIR"
  php artisan serve --host=127.0.0.1 --port="$SERVE_PORT"
) > "$ART_DIR/server.log" 2>&1 &
SERVER_PID=$!

echo "$SERVER_PID" > "$ART_DIR/server.pid"

log "Waiting for health"
health_code=""
health_body="$ART_DIR/health_body.txt"
for _i in $(seq 1 40); do
  health_code="$(curl -s -o "$health_body" -w "%{http_code}" "$API_BASE/" || true)"
  if [[ "$health_code" == "200" ]]; then
    break
  fi
  sleep 0.5
done
if [[ "$health_code" != "200" ]]; then
  log "Health check failed: ${health_code}"
  cat "$health_body" | tee -a "$LOG_FILE" || true
  tail -n 120 "$ART_DIR/server.log" | tee -a "$LOG_FILE" || true
  exit 1
fi

log "Seeding scales_registry"
cat <<'PHP' > /tmp/pr24_seed_scales.php
<?php
$repo = getenv('REPO_DIR') ?: getcwd();
require $repo . '/backend/vendor/autoload.php';
$app = require $repo . '/backend/bootstrap/app.php';
$kernel = $app->make(Illuminate\Contracts\Console\Kernel::class);
$kernel->bootstrap();

$rows = [];
for ($i = 1; $i <= 50; $i++) {
    $primary = sprintf('pr24-primary-%02d', $i);
    $alias = sprintf('pr24-alias-%02d', $i);
    $rows[] = [
        'code' => sprintf('PR24_%02d', $i),
        'org_id' => 0,
        'primary_slug' => $primary,
        'slugs_json' => json_encode([$alias]),
        'driver_type' => 'MBTI',
        'default_pack_id' => null,
        'default_region' => null,
        'default_locale' => null,
        'default_dir_version' => null,
        'capabilities_json' => null,
        'view_policy_json' => null,
        'commercial_json' => null,
        'seo_schema_json' => null,
        'is_public' => 1,
        'is_active' => 1,
        'created_at' => \Illuminate\Support\Carbon::now(),
        'updated_at' => \Illuminate\Support\Carbon::now(),
    ];
}
\Illuminate\Support\Facades\DB::table('scales_registry')->insert($rows);
echo "inserted=" . count($rows) . "\n";
PHP

REPO_DIR="$ROOT_DIR" php /tmp/pr24_seed_scales.php | tee -a "$LOG_FILE"

headers_200="$ART_DIR/headers_200.txt"
body_200="$ART_DIR/sitemap.xml"

log "GET /sitemap.xml"
status_200="$(curl -sS -D "$headers_200" -o "$body_200" -w "%{http_code}" "$API_BASE/sitemap.xml" || true)"
if [[ "$status_200" != "200" ]]; then
  log "Expected 200, got ${status_200}"
  cat "$body_200" | tee -a "$LOG_FILE" || true
  tail -n 120 "$ART_DIR/server.log" | tee -a "$LOG_FILE" || true
  exit 1
fi

content_type="$(grep -i -E '^Content-Type:' "$headers_200" | head -n 1 | sed -E 's/^[Cc]ontent-[Tt]ype:[[:space:]]*//')"
if [[ "$content_type" != *"application/xml"* ]]; then
  log "Content-Type missing application/xml: $content_type"
  exit 1
fi

cache_control="$(grep -i -E '^Cache-Control:' "$headers_200" | head -n 1 | sed -E 's/^[Cc]ache-[Cc]ontrol:[[:space:]]*//')"
expected_cache="public, max-age=3600, s-maxage=86400, stale-while-revalidate=604800"
for token in public max-age=3600 s-maxage=86400 stale-while-revalidate=604800; do
  if [[ "$cache_control" != *"$token"* ]]; then
    log "Cache-Control missing token ${token}: $cache_control"
    exit 1
  fi
done

etag="$(grep -i -E '^ETag:' "$headers_200" | head -n 1 | sed -E 's/^[Ee][Tt][Aa][Gg]:[[:space:]]*//')"
if [[ -z "$etag" ]]; then
  log "ETag missing"
  exit 1
fi

if grep -i -E '^Set-Cookie:' "$headers_200" >/dev/null 2>&1; then
  log "Set-Cookie header detected"
  exit 1
fi

loc_count="$(grep -F "<loc>${URL_PREFIX}pr24-" "$body_200" | wc -l | tr -d ' ' || true)"
if [[ "$loc_count" != "100" ]]; then
  log "Expected 100 loc entries for prefix ${URL_PREFIX}, got $loc_count"
  exit 1
fi

lastmod_count="$(grep -E '<lastmod>[0-9]{4}-[0-9]{2}-[0-9]{2}</lastmod>' "$body_200" | wc -l | tr -d ' ')"
if [[ "$lastmod_count" != "100" ]]; then
  log "Expected 100 lastmod entries, got $lastmod_count"
  exit 1
fi

changefreq_count="$(grep -E '<changefreq>weekly</changefreq>' "$body_200" | wc -l | tr -d ' ')"
if [[ "$changefreq_count" != "100" ]]; then
  log "Expected 100 changefreq entries, got $changefreq_count"
  exit 1
fi

priority_count="$(grep -E '<priority>0.7</priority>' "$body_200" | wc -l | tr -d ' ')"
if [[ "$priority_count" != "100" ]]; then
  log "Expected 100 priority entries, got $priority_count"
  exit 1
fi

headers_304="$ART_DIR/headers_304.txt"
body_304="$ART_DIR/body_304.txt"
log "GET /sitemap.xml (If-None-Match)"
status_304="$(curl -sS -D "$headers_304" -o "$body_304" -w "%{http_code}" -H "If-None-Match: $etag" "$API_BASE/sitemap.xml" || true)"
if [[ "$status_304" != "304" ]]; then
  log "Expected 304, got ${status_304}"
  cat "$body_304" | tee -a "$LOG_FILE" || true
  exit 1
fi

etag_304="$(grep -i -E '^ETag:' "$headers_304" | head -n 1 | sed -E 's/^[Ee][Tt][Aa][Gg]:[[:space:]]*//')"
if [[ "$etag_304" != "$etag" ]]; then
  log "ETag mismatch: ${etag_304}"
  exit 1
fi

cache_304="$(grep -i -E '^Cache-Control:' "$headers_304" | head -n 1 | sed -E 's/^[Cc]ache-[Cc]ontrol:[[:space:]]*//')"
for token in public max-age=3600 s-maxage=86400 stale-while-revalidate=604800; do
  if [[ "$cache_304" != *"$token"* ]]; then
    log "Cache-Control missing token on 304 ${token}: $cache_304"
    exit 1
  fi
done

if [[ -s "$body_304" ]]; then
  log "Expected empty body for 304"
  exit 1
fi

if grep -i -E '^Set-Cookie:' "$headers_304" >/dev/null 2>&1; then
  log "Set-Cookie header detected on 304"
  exit 1
fi

log "Sitemap verification completed"

cleanup_port "$SERVE_PORT"
cleanup_port 18000

if lsof -nP -iTCP:"$SERVE_PORT" -sTCP:LISTEN >/dev/null 2>&1; then
  log "Port ${SERVE_PORT} still in use"
  exit 1
fi
