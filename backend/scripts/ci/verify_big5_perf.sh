#!/usr/bin/env bash
set -euo pipefail

SCRIPT_DIR="$(cd "$(dirname "${BASH_SOURCE[0]}")" && pwd)"
BACKEND_DIR="$(cd "${SCRIPT_DIR}/../.." && pwd)"
REPO_DIR="$(cd "${BACKEND_DIR}/.." && pwd)"

HOST="${HOST:-127.0.0.1}"
PORT="${PORT:-1835}"
API_BASE="http://${HOST}:${PORT}"
SERVE_LOG="${BACKEND_DIR}/artifacts/verify_big5_perf_serve.log"
PERF_JSON_FILE="${BACKEND_DIR}/artifacts/verify_big5_perf.json"
ATTEMPT_ID_FILE="${BACKEND_DIR}/artifacts/verify_mbti/attempt_id.txt"
ANON_ID_FILE="${BACKEND_DIR}/artifacts/verify_mbti/anon_id.txt"

export APP_ENV="${APP_ENV:-testing}"
export DB_CONNECTION="${DB_CONNECTION:-sqlite}"
export DB_DATABASE="${DB_DATABASE:-/tmp/fap-ci.sqlite}"
export FAP_PACKS_DRIVER="${FAP_PACKS_DRIVER:-local}"
export FAP_PACKS_ROOT="${FAP_PACKS_ROOT:-${REPO_DIR}/content_packages}"

mkdir -p "${BACKEND_DIR}/artifacts"

serve_pid=""
cleanup() {
  if [[ -n "${serve_pid}" ]]; then
    kill "${serve_pid}" >/dev/null 2>&1 || true
  fi
}
trap cleanup EXIT

cd "${BACKEND_DIR}"

php artisan serve --host="${HOST}" --port="${PORT}" > "${SERVE_LOG}" 2>&1 &
serve_pid="$!"

for _ in $(seq 1 60); do
  if curl -fsS --max-time 2 "${API_BASE}/api/healthz" >/dev/null 2>&1; then
    break
  fi
  sleep 0.5
done
if ! curl -fsS --max-time 2 "${API_BASE}/api/healthz" >/dev/null 2>&1; then
  echo "[CI][perf][FAIL] api not ready: ${API_BASE}" >&2
  exit 41
fi

PERF_CONFIG_JSON="$(php -r '
$cfg = require getcwd() . "/config/big5_perf.php";
echo json_encode($cfg, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
')"

REQUESTS="$(php -r '$cfg=json_decode($argv[1],true); echo (int)($cfg["smoke"]["requests_per_endpoint"] ?? 12);' "${PERF_CONFIG_JSON}")"
TIMEOUT_SECONDS="$(php -r '$cfg=json_decode($argv[1],true); echo (int)($cfg["smoke"]["timeout_seconds"] ?? 8);' "${PERF_CONFIG_JSON}")"
TARGET_SCALE="$(php -r '$cfg=json_decode($argv[1],true); echo (string)($cfg["smoke"]["target_scale"] ?? "BIG5_OCEAN");' "${PERF_CONFIG_JSON}")"
FALLBACK_SCALE="$(php -r '$cfg=json_decode($argv[1],true); echo (string)($cfg["smoke"]["fallback_scale"] ?? "MBTI");' "${PERF_CONFIG_JSON}")"

ATTEMPT_ID=""
ANON_ID=""
if [[ -f "${ATTEMPT_ID_FILE}" ]]; then
  ATTEMPT_ID="$(tr -d '[:space:]' < "${ATTEMPT_ID_FILE}")"
fi
if [[ -f "${ANON_ID_FILE}" ]]; then
  ANON_ID="$(tr -d '[:space:]' < "${ANON_ID_FILE}")"
fi

SMOKE_JSON="$(
  API_BASE="${API_BASE}" \
  TARGET_SCALE="${TARGET_SCALE}" \
  FALLBACK_SCALE="${FALLBACK_SCALE}" \
  REQUESTS="${REQUESTS}" \
  TIMEOUT_SECONDS="${TIMEOUT_SECONDS}" \
  ATTEMPT_ID="${ATTEMPT_ID}" \
  ANON_ID="${ANON_ID}" \
  bash "${BACKEND_DIR}/scripts/loadtest/big5_smoke.sh" | tail -n 1
)"
printf '%s\n' "${SMOKE_JSON}" > "${PERF_JSON_FILE}"

php -r '
$cfg = json_decode($argv[1], true);
$run = json_decode($argv[2], true);
if (!is_array($cfg) || !is_array($run)) {
    fwrite(STDERR, "[CI][perf][FAIL] invalid config or smoke json\n");
    exit(42);
}
$metrics = $run["metrics"] ?? null;
if (!is_array($metrics)) {
    fwrite(STDERR, "[CI][perf][FAIL] missing metrics in smoke output\n");
    exit(43);
}
$errorRateMax = (float)($cfg["error_rate_max"] ?? 0.02);
$budget = (array)($cfg["budget_ms"] ?? []);
$required = array_values((array)($cfg["smoke"]["required_metrics"] ?? []));
$budgetMap = [
    "questions" => "questions_p95_ms",
    "submit" => "submit_p95_ms",
    "report_free" => "report_free_p95_ms",
    "report_full" => "report_full_p95_ms",
];
foreach ($required as $metric) {
    if (!array_key_exists($metric, $metrics)) {
        fwrite(STDERR, "[CI][perf][FAIL] required metric missing: {$metric}\n");
        exit(44);
    }
    $row = (array)$metrics[$metric];
    $status = (string)($row["status"] ?? "");
    if ($status === "skipped") {
        fwrite(STDERR, "[CI][perf][FAIL] required metric skipped: {$metric}\n");
        exit(45);
    }
    if ($status === "failed") {
        fwrite(STDERR, "[CI][perf][FAIL] required metric failed: {$metric}\n");
        exit(46);
    }
    $errorRate = (float)($row["error_rate"] ?? 1.0);
    if ($errorRate > $errorRateMax) {
        fwrite(STDERR, "[CI][perf][FAIL] {$metric} error_rate={$errorRate} exceeds {$errorRateMax}\n");
        exit(47);
    }
    $budgetKey = $budgetMap[$metric] ?? null;
    if ($budgetKey === null || !array_key_exists($budgetKey, $budget)) {
        fwrite(STDERR, "[CI][perf][FAIL] missing budget key for {$metric}\n");
        exit(48);
    }
    $p95 = (float)($row["p95_ms"] ?? 999999.0);
    $limit = (float)$budget[$budgetKey];
    if ($p95 > $limit) {
        fwrite(STDERR, "[CI][perf][FAIL] {$metric} p95_ms={$p95} exceeds {$limit}\n");
        exit(49);
    }
}
echo "[CI][perf] required metrics passed=" . count($required) . "\n";
' "${PERF_CONFIG_JSON}" "${SMOKE_JSON}"

echo "[CI][perf] output=${PERF_JSON_FILE}"
