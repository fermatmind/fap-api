#!/usr/bin/env bash
set -euo pipefail

export CI=true
export FAP_NONINTERACTIVE=1
export COMPOSER_NO_INTERACTION=1
export GIT_TERMINAL_PROMPT=0
export NO_COLOR=1

export FAP_DEFAULT_PACK_ID="${FAP_DEFAULT_PACK_ID:-MBTI.cn-mainland.zh-CN.v0.3}"
export FAP_DEFAULT_DIR_VERSION="${FAP_DEFAULT_DIR_VERSION:-MBTI-CN-v0.3}"
export FAP_DEFAULT_REGION="${FAP_DEFAULT_REGION:-CN_MAINLAND}"
export FAP_DEFAULT_LOCALE="${FAP_DEFAULT_LOCALE:-zh-CN}"

SERVE_PORT="${SERVE_PORT:-1822}"
API_BASE="http://127.0.0.1:${SERVE_PORT}"

SCRIPT_DIR="$(cd "$(dirname "${BASH_SOURCE[0]}")" && pwd)"
ROOT_DIR="$(cd "$SCRIPT_DIR/../.." && pwd)"
BACKEND_DIR="$ROOT_DIR/backend"
ART_DIR="$ROOT_DIR/backend/artifacts/pr22"

# ✅ content packs root + explicit MBTI content package (new layout)
export FAP_PACKS_ROOT="${FAP_PACKS_ROOT:-$ROOT_DIR/content_packages}"
export MBTI_CONTENT_PACKAGE="${MBTI_CONTENT_PACKAGE:-default/${FAP_DEFAULT_REGION}/${FAP_DEFAULT_LOCALE}/${FAP_DEFAULT_DIR_VERSION}}"

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
for _i in $(seq 1 40); do
  health_code="$(curl -s -o /dev/null -w "%{http_code}" "$API_BASE/api/healthz" || true)"
  if [[ "$health_code" == "200" ]]; then
    break
  fi
  sleep 0.5
done
if [[ "$health_code" != "200" ]]; then
  log "Health check failed: ${health_code}"
  tail -n 120 "$ART_DIR/server.log" | tee -a "$LOG_FILE" || true
  exit 1
fi

log "Checking pack/seed/config consistency"
cat <<'PHP' > /tmp/pr22_pack_check.php
<?php
$repo = getenv('REPO_DIR') ?: getcwd();
require $repo . '/backend/vendor/autoload.php';
$app = require $repo . '/backend/bootstrap/app.php';
$kernel = $app->make(Illuminate\Contracts\Console\Kernel::class);
$kernel->bootstrap();

$cfgPackId     = (string) config('content_packs.default_pack_id');
$cfgDirVersion = (string) config('content_packs.default_dir_version');
$cfgRegion     = (string) config('content_packs.default_region');
$cfgLocale     = (string) config('content_packs.default_locale');

$row = \Illuminate\Support\Facades\DB::table('scales_registry')
    ->where('org_id', 0)->where('code', 'MBTI')->first();

$dbPackId     = (string) ($row->default_pack_id ?? '');
$dbDirVersion = (string) ($row->default_dir_version ?? '');

echo "config_default_pack_id={$cfgPackId}\n";
echo "db_default_pack_id={$dbPackId}\n";

function fail($msg) {
    fwrite(STDERR, $msg . "\n");
    exit(1);
}

if ($cfgPackId === '' || $dbPackId === '') {
    fail('missing pack_id in config or db');
}
if ($cfgPackId !== $dbPackId) {
    fail('config default_pack_id != scales_registry default_pack_id');
}

$dirVersion = $dbDirVersion !== '' ? $dbDirVersion : $cfgDirVersion;
if ($dirVersion === '') {
    fail('missing default_dir_version');
}

$root = rtrim((string) config('content_packs.root'), '/');
if ($root === '') {
    fail('missing content_packs.root');
}

$region = $cfgRegion !== '' ? $cfgRegion : (getenv('FAP_DEFAULT_REGION') ?: 'CN_MAINLAND');
$locale = $cfgLocale !== '' ? $cfgLocale : (getenv('FAP_DEFAULT_LOCALE') ?: 'zh-CN');

$mbtiContentPackage = trim((string) getenv('MBTI_CONTENT_PACKAGE'));
if ($mbtiContentPackage === '') {
    $mbtiContentPackage = "default/{$region}/{$locale}/{$dirVersion}";
}

$packDir = $root . '/' . ltrim($mbtiContentPackage, '/');

echo "packs_root={$root}\n";
echo "mbti_content_package={$mbtiContentPackage}\n";

if (!is_dir($packDir)) {
    fail('pack dir missing: ' . $packDir);
}

$manifestPath   = $packDir . '/manifest.json';
$versionPath    = $packDir . '/version.json';
$questionsPath  = $packDir . '/questions.json';

foreach ([$manifestPath, $versionPath, $questionsPath] as $p) {
    if (!is_file($p)) {
        fail('missing file: ' . $p);
    }
    $raw = file_get_contents($p);
    $decoded = json_decode($raw, true);
    if (!is_array($decoded)) {
        fail('invalid json: ' . $p);
    }
}

$manifest = json_decode(file_get_contents($manifestPath), true);
$manifestPack = (string) ($manifest['pack_id'] ?? '');
if ($manifestPack === '') {
    fail('manifest pack_id missing');
}
if ($manifestPack !== $cfgPackId) {
    fail('manifest pack_id != config default_pack_id');
}

echo "pack_dir={$packDir}\n";
PHP

REPO_DIR="$ROOT_DIR" php /tmp/pr22_pack_check.php | tee -a "$LOG_FILE"

curl_json() {
  local url="$1"
  local out="$2"
  local headers="$3"
  shift 3
  local code
  code="$(curl -sS -D "$headers" -o "$out" -w "%{http_code}" "$@" "$url" || true)"
  printf '%s' "$code"
}

assert_json() {
  local path="$1"
  if [[ ! -f "$path" || ! -s "$path" ]]; then
    log "json missing or empty: $path"
    tail -n 120 "$ART_DIR/server.log" | tee -a "$LOG_FILE" || true
    exit 1
  fi
  if ! php -r 'json_decode(file_get_contents($argv[1]), true); if (json_last_error() !== JSON_ERROR_NONE) { exit(1);} ' "$path"; then
    log "invalid json: $path"
    exit 1
  fi
}

log "GET /api/v0.4/boot (CN_MAINLAND)"
cn_headers="$ART_DIR/headers_cn.txt"
cn_body="$ART_DIR/boot_cn.json"
cn_code=$(curl_json "$API_BASE/api/v0.4/boot" "$cn_body" "$cn_headers" \
  -H "Accept: application/json" \
  -H "X-Region: CN_MAINLAND" \
  -H "Accept-Language: zh-CN")
if [[ "$cn_code" != "200" ]]; then
  log "boot CN failed: HTTP=$cn_code"
  tail -n 120 "$ART_DIR/server.log" | tee -a "$LOG_FILE" || true
  exit 1
fi
assert_json "$cn_body"

grep -Eiq '^Cache-Control: .*max-age=300' "$cn_headers"
grep -Eiq '^Cache-Control: .*public' "$cn_headers"
grep -Eiq '^Vary: .*X-Region.*Accept-Language' "$cn_headers"
grep -Eiq '^ETag: ' "$cn_headers"

cn_etag="$(grep -Ei '^ETag:' "$cn_headers" | head -n 1 | sed -E 's/^ETag:[[:space:]]*//I')"
printf '%s' "$cn_etag" > "$ART_DIR/etag_cn.txt"

log "GET /api/v0.4/boot (US)"
us_headers="$ART_DIR/headers_us.txt"
us_body="$ART_DIR/boot_us.json"
us_code=$(curl_json "$API_BASE/api/v0.4/boot" "$us_body" "$us_headers" \
  -H "Accept: application/json" \
  -H "X-Region: US" \
  -H "Accept-Language: en-US")
if [[ "$us_code" != "200" ]]; then
  log "boot US failed: HTTP=$us_code"
  tail -n 120 "$ART_DIR/server.log" | tee -a "$LOG_FILE" || true
  exit 1
fi
assert_json "$us_body"

grep -Eiq '^Cache-Control: .*max-age=300' "$us_headers"
grep -Eiq '^Cache-Control: .*public' "$us_headers"
grep -Eiq '^Vary: .*X-Region.*Accept-Language' "$us_headers"
grep -Eiq '^ETag: ' "$us_headers"

us_etag="$(grep -Ei '^ETag:' "$us_headers" | head -n 1 | sed -E 's/^ETag:[[:space:]]*//I')"
printf '%s' "$us_etag" > "$ART_DIR/etag_us.txt"

log "ETag 304 validation"
code_304="$(curl -sS -D "$ART_DIR/headers_304.txt" -o /dev/null -w "%{http_code}" \
  -H "Accept: application/json" \
  -H "X-Region: CN_MAINLAND" \
  -H "Accept-Language: zh-CN" \
  -H "If-None-Match: ${cn_etag}" \
  "$API_BASE/api/v0.4/boot" || true)"
if [[ "$code_304" != "304" ]]; then
  log "ETag 304 failed: HTTP=$code_304"
  exit 1
fi

grep -Eiq '^Cache-Control: .*max-age=300' "$ART_DIR/headers_304.txt"
grep -Eiq '^Cache-Control: .*public' "$ART_DIR/headers_304.txt"
grep -Eiq '^Vary: .*X-Region.*Accept-Language' "$ART_DIR/headers_304.txt"

log "Validate boot payloads"
cat <<'PHP' > /tmp/pr22_boot_check.php
<?php
$repo = getenv('REPO_DIR') ?: getcwd();
require $repo . '/backend/vendor/autoload.php';
$app = require $repo . '/backend/bootstrap/app.php';
$kernel = $app->make(Illuminate\Contracts\Console\Kernel::class);
$kernel->bootstrap();

$file = getenv('BOOT_FILE');
$expectRegion = getenv('EXPECT_REGION');
$expectLocale = getenv('EXPECT_LOCALE');
if (!$file || !file_exists($file)) {
    fwrite(STDERR, "missing boot file\n");
    exit(1);
}
$data = json_decode(file_get_contents($file), true);
if (!is_array($data) || !($data['ok'] ?? false)) {
    fwrite(STDERR, "boot json invalid\n");
    exit(1);
}
if ($expectRegion && ($data['region'] ?? '') !== $expectRegion) {
    fwrite(STDERR, "region mismatch\n");
    exit(1);
}
if ($expectLocale && ($data['locale'] ?? '') !== $expectLocale) {
    fwrite(STDERR, "locale mismatch\n");
    exit(1);
}
if (!is_array($data['payment_methods'] ?? null)) {
    fwrite(STDERR, "payment_methods missing\n");
    exit(1);
}
if ($expectRegion === 'US') {
    $base = (string) config('cdn_map.map.US.assets_base_url');
    if ($base !== '' && ($data['cdn']['assets_base_url'] ?? '') !== $base) {
        fwrite(STDERR, "cdn base mismatch\n");
        exit(1);
    }
}
PHP

REPO_DIR="$ROOT_DIR" BOOT_FILE="$cn_body" EXPECT_REGION="CN_MAINLAND" EXPECT_LOCALE="zh-CN" php /tmp/pr22_boot_check.php
REPO_DIR="$ROOT_DIR" BOOT_FILE="$us_body" EXPECT_REGION="US" EXPECT_LOCALE="en-US" php /tmp/pr22_boot_check.php

log "Ensure IQ_RAVEN scale seeded"
cat <<'PHP' > /tmp/pr22_iq_raven_seed_check.php
<?php
$repo = getenv('REPO_DIR') ?: getcwd();
require $repo . '/backend/vendor/autoload.php';
$app = require $repo . '/backend/bootstrap/app.php';
$kernel = $app->make(Illuminate\Contracts\Console\Kernel::class);
$kernel->bootstrap();
$count = (int) \Illuminate\Support\Facades\DB::table('scales_registry')
    ->where('org_id', 0)->where('code', 'IQ_RAVEN')->count();
echo $count;
PHP
iq_count="$(REPO_DIR=\"$ROOT_DIR\" php /tmp/pr22_iq_raven_seed_check.php 2>/dev/null || echo "0")"
if [[ "$iq_count" == "0" ]]; then
  log "Seeding IQ_RAVEN demo scale"
  (
    cd "$BACKEND_DIR"
    php artisan db:seed --class=Pr16IqRavenDemoSeeder
  ) >> "$LOG_FILE" 2>&1
fi

log "GET /api/v0.3/scales/IQ_RAVEN/questions (US)"
questions_body="$ART_DIR/curl_questions_iq_raven.json"
questions_code=$(curl -sS -o "$questions_body" -w "%{http_code}" \
  -H "Accept: application/json" \
  -H "X-Region: US" \
  -H "Accept-Language: en-US" \
  "$API_BASE/api/v0.3/scales/IQ_RAVEN/questions" || true)
if [[ "$questions_code" != "200" ]]; then
  log "IQ_RAVEN questions failed: HTTP=$questions_code"
  tail -n 120 "$ART_DIR/server.log" | tee -a "$LOG_FILE" || true
  exit 1
fi
assert_json "$questions_body"

cat <<'PHP' > /tmp/pr22_iq_raven_check.php
<?php
$repo = getenv('REPO_DIR') ?: getcwd();
require $repo . '/backend/vendor/autoload.php';
$app = require $repo . '/backend/bootstrap/app.php';
$kernel = $app->make(Illuminate\Contracts\Console\Kernel::class);
$kernel->bootstrap();

$file = getenv('QUESTIONS_FILE');
$data = json_decode(file_get_contents($file), true);
$items = $data['questions']['items'] ?? [];
$first = $items[0]['assets']['image'] ?? '';
if (!is_string($first) || $first === '') {
    fwrite(STDERR, "missing first asset url\n");
    exit(1);
}
$base = (string) config('cdn_map.map.US.assets_base_url');
if ($base === '') {
    fwrite(STDERR, "cdn base missing\n");
    exit(1);
}
$prefix = rtrim($base, '/') . '/default/IQ-RAVEN-CN-v0.3.0-DEMO/';
if (!str_starts_with($first, $prefix)) {
    fwrite(STDERR, "asset prefix mismatch\n");
    exit(1);
}

$count = is_array($items) ? count($items) : 0;
echo "questions_count={$count}\n";
PHP
REPO_DIR="$ROOT_DIR" QUESTIONS_FILE="$questions_body" php /tmp/pr22_iq_raven_check.php | tee -a "$LOG_FILE"

log "Verify complete"

cleanup

if lsof -nP -iTCP:"$SERVE_PORT" -sTCP:LISTEN >/dev/null 2>&1; then
  log "Port ${SERVE_PORT} still in use"
  exit 1
fi
