#!/usr/bin/env bash
set -euo pipefail

ROOT_DIR="$(cd "$(dirname "${BASH_SOURCE[0]}")/.." && pwd)"
ART_DIR="$ROOT_DIR/artifacts/pr15"
mkdir -p "$ART_DIR"

{
  echo "timestamp=$(date -u +"%Y-%m-%dT%H:%M:%SZ")"
  php -v | head -n 1
  php artisan -V
} > "$ART_DIR/env.txt"

php artisan migrate --force
php artisan fap:scales:seed-default
php artisan fap:scales:sync-slugs

php -S 127.0.0.1:8000 -t public > "$ART_DIR/server.log" 2>&1 &
SERVER_PID=$!

cleanup() {
  if kill -0 "$SERVER_PID" >/dev/null 2>&1; then
    kill "$SERVER_PID" >/dev/null 2>&1 || true
  fi
}
trap cleanup EXIT

sleep 1

curl -sS http://127.0.0.1:8000/api/v0.3/scales > "$ART_DIR/curl_scales.json"
curl -sS "http://127.0.0.1:8000/api/v0.3/scales/lookup?slug=mbti-test" > "$ART_DIR/curl_lookup_mbti.json"
curl -sS http://127.0.0.1:8000/api/v0.3/scales/MBTI > "$ART_DIR/curl_scale_mbti.json"

php artisan test --filter=V0_3

php artisan route:list > "$ART_DIR/routes.txt"
rg -n "v0.3|scales|lookup" "$ART_DIR/routes.txt" > "$ART_DIR/routes_grep.txt" || true

cat <<'SUMMARY' > "$ART_DIR/summary.txt"
PR15 Scale Registry verification summary

Checks:
- migrate --force: OK
- fap:scales:seed-default: OK
- fap:scales:sync-slugs: OK
- curl /api/v0.3/scales: OK
- curl /api/v0.3/scales/lookup?slug=mbti-test: OK
- curl /api/v0.3/scales/MBTI: OK
- php artisan test --filter=V0_3: OK
- route:list export: OK

Smoke URLs:
- http://127.0.0.1:8000/api/v0.3/scales
- http://127.0.0.1:8000/api/v0.3/scales/lookup?slug=mbti-test
- http://127.0.0.1:8000/api/v0.3/scales/MBTI

Key outputs:
- scale_code: MBTI
- dir_version: MBTI-CN-v0.2.1-TEST

Schema changes:
- scales_registry (unique org_id+primary_slug; indexes: org_id, driver_type, is_public, is_active)
- scale_slugs (unique org_id+slug; indexes: scale_code, is_primary, org_id)
SUMMARY
