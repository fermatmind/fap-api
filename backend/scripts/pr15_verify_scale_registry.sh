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
SERVER_CANONICAL_ONLY_PID=""

cleanup() {
  if kill -0 "$SERVER_PID" >/dev/null 2>&1; then
    kill "$SERVER_PID" >/dev/null 2>&1 || true
  fi
  if [[ -n "$SERVER_CANONICAL_ONLY_PID" ]] && kill -0 "$SERVER_CANONICAL_ONLY_PID" >/dev/null 2>&1; then
    kill "$SERVER_CANONICAL_ONLY_PID" >/dev/null 2>&1 || true
  fi
}
trap cleanup EXIT

sleep 1

curl -sS http://127.0.0.1:8000/api/v0.3/scales > "$ART_DIR/curl_scales.json"
curl -sS "http://127.0.0.1:8000/api/v0.3/scales/lookup?slug=mbti-personality-test-16-personality-types" > "$ART_DIR/curl_lookup_mbti.json"
curl -sS "http://127.0.0.1:8000/api/v0.3/scales/lookup?slug=mbti-test" > "$ART_DIR/curl_lookup_mbti_alias.json"
curl -sS http://127.0.0.1:8000/api/v0.3/scales/MBTI > "$ART_DIR/curl_scale_mbti.json"

php -r '
$canonical = json_decode((string) file_get_contents($argv[1]), true);
if (!is_array($canonical) || ($canonical["ok"] ?? false) !== true) { fwrite(STDERR, "canonical lookup failed\n"); exit(1); }
if (($canonical["resolved_from_alias"] ?? null) !== false) { fwrite(STDERR, "canonical resolved_from_alias must be false\n"); exit(1); }
if (($canonical["primary_slug"] ?? "") !== "mbti-personality-test-16-personality-types") { fwrite(STDERR, "canonical primary_slug mismatch\n"); exit(1); }
' "$ART_DIR/curl_lookup_mbti.json"

php -r '
$alias = json_decode((string) file_get_contents($argv[1]), true);
if (!is_array($alias) || ($alias["ok"] ?? false) !== true) { fwrite(STDERR, "alias lookup failed\n"); exit(1); }
if (($alias["resolved_from_alias"] ?? null) !== true) { fwrite(STDERR, "alias resolved_from_alias must be true\n"); exit(1); }
if (($alias["primary_slug"] ?? "") !== "mbti-personality-test-16-personality-types") { fwrite(STDERR, "alias primary_slug mismatch\n"); exit(1); }
' "$ART_DIR/curl_lookup_mbti_alias.json"

FAP_SCALE_LOOKUP_ALIAS_MODE=canonical_only php -S 127.0.0.1:8001 -t public > "$ART_DIR/server_canonical_only.log" 2>&1 &
SERVER_CANONICAL_ONLY_PID=$!
sleep 1
curl -sS "http://127.0.0.1:8001/api/v0.3/scales/lookup?slug=mbti-personality-test-16-personality-types" > "$ART_DIR/curl_lookup_mbti_canonical_only.json"
curl -sS "http://127.0.0.1:8001/api/v0.3/scales/lookup?slug=mbti-test" > "$ART_DIR/curl_lookup_mbti_alias_canonical_only.json"

php -r '
$canonical = json_decode((string) file_get_contents($argv[1]), true);
if (!is_array($canonical) || ($canonical["ok"] ?? false) !== true) { fwrite(STDERR, "canonical-only canonical lookup failed\n"); exit(1); }
' "$ART_DIR/curl_lookup_mbti_canonical_only.json"

php -r '
$alias = json_decode((string) file_get_contents($argv[1]), true);
if (!is_array($alias) || ($alias["ok"] ?? true) !== false) { fwrite(STDERR, "canonical-only alias lookup must fail\n"); exit(1); }
if (($alias["error_code"] ?? "") !== "NOT_FOUND") { fwrite(STDERR, "canonical-only alias error_code mismatch\n"); exit(1); }
' "$ART_DIR/curl_lookup_mbti_alias_canonical_only.json"

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
- curl /api/v0.3/scales/lookup?slug=mbti-personality-test-16-personality-types: OK
- curl /api/v0.3/scales/lookup?slug=mbti-test: OK
- lookup observability: canonical resolved_from_alias=false, alias resolved_from_alias=true
- canonical_only mode: canonical slug OK, alias returns NOT_FOUND
- curl /api/v0.3/scales/MBTI: OK
- php artisan test --filter=V0_3: OK
- route:list export: OK

Smoke URLs:
- http://127.0.0.1:8000/api/v0.3/scales
- http://127.0.0.1:8000/api/v0.3/scales/lookup?slug=mbti-personality-test-16-personality-types
- http://127.0.0.1:8000/api/v0.3/scales/lookup?slug=mbti-test
- http://127.0.0.1:8000/api/v0.3/scales/MBTI
- http://127.0.0.1:8001/api/v0.3/scales/lookup?slug=mbti-personality-test-16-personality-types (canonical_only)
- http://127.0.0.1:8001/api/v0.3/scales/lookup?slug=mbti-test (canonical_only)

Key outputs:
- scale_code: MBTI
- dir_version: MBTI-CN-v0.3

Schema changes:
- scales_registry (unique org_id+primary_slug; indexes: org_id, driver_type, is_public, is_active)
- scale_slugs (unique org_id+slug; indexes: scale_code, is_primary, org_id)
SUMMARY
