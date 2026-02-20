#!/usr/bin/env bash
set -euo pipefail

export CI=true
export FAP_NONINTERACTIVE=1
export COMPOSER_NO_INTERACTION=1
export GIT_TERMINAL_PROMPT=0
export NO_COLOR=1
export PAGER=cat
export GIT_PAGER=cat
export TERM=dumb
export XDEBUG_MODE=off
export LANG=en_US.UTF-8

REPO_DIR="$(cd "$(dirname "${BASH_SOURCE[0]}")/../.." && pwd)"
BACKEND_DIR="${REPO_DIR}/backend"
ART_DIR="${ART_DIR:-${BACKEND_DIR}/artifacts/pr65}"
SERVE_PORT="${SERVE_PORT:-1865}"
HOST="127.0.0.1"
API_BASE="http://${HOST}:${SERVE_PORT}"

mkdir -p "${ART_DIR}"
exec > "${ART_DIR}/verify.log" 2>&1

fail() {
  echo "[PR65][VERIFY][FAIL] $*" >&2
  exit 1
}

cleanup_port() {
  local port="$1"
  lsof -nP -iTCP:"${port}" -sTCP:LISTEN || true
  local pid_list
  pid_list="$(lsof -ti tcp:"${port}" || true)"
  if [[ -n "${pid_list}" ]]; then
    echo "${pid_list}" | xargs kill -9 || true
  fi
  lsof -nP -iTCP:"${port}" -sTCP:LISTEN || true
}

wait_health() {
  local url="$1"
  local body_file="${ART_DIR}/healthz.body"
  local http_code=""
  for _ in $(seq 1 80); do
    http_code="$(curl -sS -o "${body_file}" -w "%{http_code}" "${url}" || true)"
    if [[ "${http_code}" == "200" ]]; then
      return 0
    fi
    sleep 0.25
  done
  echo "health_check_failed http=${http_code}" >&2
  cat "${body_file}" >&2 || true
  tail -n 120 "${ART_DIR}/server.log" >&2 || true
  return 1
}

cleanup() {
  if [[ -n "${SERVE_PID:-}" ]] && ps -p "${SERVE_PID}" >/dev/null 2>&1; then
    kill "${SERVE_PID}" >/dev/null 2>&1 || true
  fi

  if [[ -f "${ART_DIR}/server.pid" ]]; then
    local pid
    pid="$(cat "${ART_DIR}/server.pid" || true)"
    if [[ -n "${pid}" ]] && ps -p "${pid}" >/dev/null 2>&1; then
      kill "${pid}" >/dev/null 2>&1 || true
    fi
  fi

  cleanup_port "${SERVE_PORT}"
  cleanup_port 18000
}
trap cleanup EXIT

cleanup_port "${SERVE_PORT}"
cleanup_port 18000

cd "${BACKEND_DIR}"
php artisan serve --host="${HOST}" --port="${SERVE_PORT}" > "${ART_DIR}/server.log" 2>&1 &
SERVE_PID="$!"
echo "${SERVE_PID}" > "${ART_DIR}/server.pid"
cd "${REPO_DIR}"

wait_health "${API_BASE}/api/healthz" || fail "healthz failed"
curl -sS "${API_BASE}/api/healthz" > "${ART_DIR}/healthz.json"

cd "${BACKEND_DIR}"
php -r '
require "vendor/autoload.php";
$app = require "bootstrap/app.php";
$app->make(Illuminate\Contracts\Console\Kernel::class)->bootstrap();

$cfgPack = trim((string) config("content_packs.default_pack_id", ""));
$cfgDir = trim((string) config("content_packs.default_dir_version", ""));
$cfgScalePackRaw = trim((string) config("scales_registry.default_pack_id", ""));
$cfgScaleDirRaw = trim((string) config("scales_registry.default_dir_version", ""));

$cfgScalePack = $cfgScalePackRaw !== "" ? $cfgScalePackRaw : $cfgPack;
$cfgScaleDir = $cfgScaleDirRaw !== "" ? $cfgScaleDirRaw : $cfgDir;

if ($cfgPack === "" || $cfgDir === "") {
    fwrite(STDERR, "content_packs_defaults_missing\n");
    exit(1);
}
if ($cfgScalePack === "" || $cfgScaleDir === "") {
    fwrite(STDERR, "scales_registry_defaults_missing\n");
    exit(1);
}
if ($cfgPack !== $cfgScalePack || $cfgDir !== $cfgScaleDir) {
    fwrite(STDERR, "config_pack_mismatch\n");
    exit(1);
}

$index = app(App\Services\Content\ContentPacksIndex::class);
$found = $index->find($cfgPack, $cfgDir);
if (!($found["ok"] ?? false)) {
    fwrite(STDERR, "pack_not_found\n");
    exit(1);
}
$item = $found["item"] ?? [];
$manifestPath = (string) ($item["manifest_path"] ?? "");
$questionsPath = (string) ($item["questions_path"] ?? "");
$packDir = $manifestPath !== "" ? dirname($manifestPath) : "";
$versionPath = $packDir !== "" ? $packDir . DIRECTORY_SEPARATOR . "version.json" : "";

foreach ([$manifestPath, $questionsPath, $versionPath] as $path) {
    if ($path === "" || !is_file($path)) {
        fwrite(STDERR, "pack_file_missing:" . $path . "\n");
        exit(1);
    }
    $decoded = json_decode((string) file_get_contents($path), true);
    if (!is_array($decoded)) {
        fwrite(STDERR, "pack_json_invalid:" . $path . "\n");
        exit(1);
    }
}

if (!Illuminate\Support\Facades\Schema::hasTable("scales_registry")) {
    fwrite(STDERR, "missing_scales_registry_table\n");
    exit(1);
}
$row = Illuminate\Support\Facades\DB::table("scales_registry")
    ->where("org_id", 0)
    ->where("code", "MBTI")
    ->first();
if (!$row) {
    fwrite(STDERR, "missing_scales_registry_mbti\n");
    exit(1);
}
$rowPack = trim((string) ($row->default_pack_id ?? ""));
$rowDir = trim((string) ($row->default_dir_version ?? ""));
if ($rowPack !== $cfgPack || $rowDir !== $cfgDir) {
    fwrite(STDERR, "seed_pack_config_mismatch\n");
    exit(1);
}

echo "config_content_packs_default_pack_id=" . $cfgPack . PHP_EOL;
echo "config_content_packs_default_dir_version=" . $cfgDir . PHP_EOL;
echo "config_scales_registry_default_pack_id_raw=" . $cfgScalePackRaw . PHP_EOL;
echo "config_scales_registry_default_dir_version_raw=" . $cfgScaleDirRaw . PHP_EOL;
echo "config_scales_registry_default_pack_id_effective=" . $cfgScalePack . PHP_EOL;
echo "config_scales_registry_default_dir_version_effective=" . $cfgScaleDir . PHP_EOL;
echo "seed_row_default_pack_id=" . $rowPack . PHP_EOL;
echo "seed_row_default_dir_version=" . $rowDir . PHP_EOL;
echo "manifest_path=" . $manifestPath . PHP_EOL;
echo "questions_path=" . $questionsPath . PHP_EOL;
echo "version_path=" . $versionPath . PHP_EOL;
' > "${ART_DIR}/pack_seed_config.log" 2>&1 || {
  cat "${ART_DIR}/pack_seed_config.log" >&2 || true
  fail "pack/seed/config consistency check failed"
}
cd "${REPO_DIR}"

SMOKE_BODY="${ART_DIR}/billing_missing_timestamp.json"
SMOKE_STATUS="$(curl -sS -o "${SMOKE_BODY}" -w "%{http_code}" \
  -X POST -H "Content-Type: application/json" -H "Accept: application/json" \
  --data '{"provider_event_id":"evt_pr65_smoke","order_no":"ord_pr65_smoke"}' \
  "${API_BASE}/api/v0.3/webhooks/payment/billing" || true)"
echo "${SMOKE_STATUS}" > "${ART_DIR}/billing_missing_timestamp.status"
if [[ "${SMOKE_STATUS}" != "404" ]]; then
  echo "billing_missing_timestamp_failed http=${SMOKE_STATUS}" >&2
  cat "${SMOKE_BODY}" >&2 || true
  tail -n 120 "${ART_DIR}/server.log" >&2 || true
  fail "billing missing timestamp smoke failed"
fi

cd "${BACKEND_DIR}"
php artisan test --filter BillingWebhookReplayToleranceTest 2>&1 | tee "${ART_DIR}/phpunit_billing_replay.log"
php artisan test --filter PaymentEventUniquenessAcrossProvidersTest 2>&1 | tee "${ART_DIR}/phpunit_provider_uniqueness.log"
cd "${REPO_DIR}"

if [[ -n "${SERVE_PID:-}" ]] && ps -p "${SERVE_PID}" >/dev/null 2>&1; then
  kill "${SERVE_PID}" >/dev/null 2>&1 || true
fi
cleanup_port "${SERVE_PORT}"

echo "verify=pass" > "${ART_DIR}/verify_done.txt"
