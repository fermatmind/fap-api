#!/usr/bin/env bash
set -euo pipefail

API_BASE="${API_BASE:-http://127.0.0.1:1835}"
TARGET_SCALE="${TARGET_SCALE:-BIG5_OCEAN}"
FALLBACK_SCALE="${FALLBACK_SCALE:-MBTI}"
REQUESTS="${REQUESTS:-12}"
TIMEOUT_SECONDS="${TIMEOUT_SECONDS:-8}"
ATTEMPT_ID="${ATTEMPT_ID:-}"
ANON_ID="${ANON_ID:-}"

TMP_DIR="$(mktemp -d)"
METRICS_FILE="${TMP_DIR}/metrics.txt"
cleanup() {
  rm -rf "${TMP_DIR}" >/dev/null 2>&1 || true
}
trap cleanup EXIT

compute_p95_ms() {
  local times_file="$1"
  php -r '
$path = $argv[1] ?? "";
if ($path === "" || !is_file($path)) { echo "0.000"; exit(0); }
$vals = array_values(array_filter(array_map("trim", file($path)), static fn ($v) => $v !== ""));
if (count($vals) === 0) { echo "0.000"; exit(0); }
$nums = array_map(static fn ($v) => (float) $v, $vals);
sort($nums, SORT_NUMERIC);
$n = count($nums);
$idx = (int) ceil($n * 0.95) - 1;
if ($idx < 0) { $idx = 0; }
if ($idx >= $n) { $idx = $n - 1; }
printf("%.3f", $nums[$idx] * 1000.0);
' "$times_file"
}

record_metric() {
  local label="$1"
  local requests="$2"
  local success="$3"
  local errors="$4"
  local error_rate="$5"
  local p95_ms="$6"
  local url="$7"
  local status="$8"
  local note="$9"
  echo "${label}|${requests}|${success}|${errors}|${error_rate}|${p95_ms}|${url}|${status}|${note}" >> "${METRICS_FILE}"
}

bench_endpoint() {
  local label="$1"
  local url="$2"
  local headers="${3:-}"

  local times_file="${TMP_DIR}/${label}.times"
  : > "${times_file}"

  local success=0
  local errors=0

  for _ in $(seq 1 "$REQUESTS"); do
    local raw=""
    if [[ -n "$headers" ]]; then
      raw="$(curl -sS -o /dev/null -w '%{http_code} %{time_total}' --max-time "$TIMEOUT_SECONDS" -H "$headers" "$url" || echo '000 0')"
    else
      raw="$(curl -sS -o /dev/null -w '%{http_code} %{time_total}' --max-time "$TIMEOUT_SECONDS" "$url" || echo '000 0')"
    fi

    local code="${raw%% *}"
    local spent="${raw##* }"
    if [[ "$code" =~ ^2[0-9][0-9]$ ]]; then
      success=$((success + 1))
      echo "$spent" >> "${times_file}"
    else
      errors=$((errors + 1))
    fi
  done

  local error_rate
  error_rate="$(php -r 'printf("%.6f", ((int)$argv[2]) / max(1, (int)$argv[1]));' "$REQUESTS" "$errors")"
  local p95_ms
  p95_ms="$(compute_p95_ms "$times_file")"
  local status="ok"
  local note=""
  if [[ "$success" -eq 0 ]]; then
    status="failed"
    note="all_requests_failed"
  fi

  record_metric "$label" "$REQUESTS" "$success" "$errors" "$error_rate" "$p95_ms" "$url" "$status" "$note"
}

record_skipped() {
  local label="$1"
  local reason="$2"
  record_metric "$label" "0" "0" "0" "0.000000" "0.000" "" "skipped" "$reason"
}

questions_url="${API_BASE}/api/v0.3/scales/${TARGET_SCALE}/questions"
probe_code="$(curl -sS -o /dev/null -w '%{http_code}' --max-time "$TIMEOUT_SECONDS" "$questions_url" || echo '000')"
scale_used="$TARGET_SCALE"
if [[ ! "$probe_code" =~ ^2[0-9][0-9]$ ]]; then
  scale_used="$FALLBACK_SCALE"
  questions_url="${API_BASE}/api/v0.3/scales/${FALLBACK_SCALE}/questions"
fi

bench_endpoint "questions" "$questions_url"

if [[ -n "$ATTEMPT_ID" && -n "$ANON_ID" ]]; then
  bench_endpoint "report_full" "${API_BASE}/api/v0.3/attempts/${ATTEMPT_ID}/report" "X-Anon-Id: ${ANON_ID}"
else
  record_skipped "report_full" "missing_attempt_context"
fi

record_skipped "submit" "lightweight_gate"
record_skipped "report_free" "lightweight_gate"

php -r '
$path = $argv[1] ?? "";
$targetScale = $argv[2] ?? "BIG5_OCEAN";
$scaleUsed = $argv[3] ?? $targetScale;
$apiBase = $argv[4] ?? "";
$lines = ($path !== "" && is_file($path)) ? file($path, FILE_IGNORE_NEW_LINES | FILE_SKIP_EMPTY_LINES) : [];
$metrics = [];
$ok = true;
foreach ($lines as $line) {
    $parts = explode("|", $line, 9);
    if (count($parts) < 9) {
        continue;
    }
    [$label, $requests, $success, $errors, $errorRate, $p95ms, $url, $status, $note] = $parts;
    $metrics[$label] = [
        "requests" => (int) $requests,
        "success" => (int) $success,
        "errors" => (int) $errors,
        "error_rate" => (float) $errorRate,
        "p95_ms" => (float) $p95ms,
        "url" => $url,
        "status" => $status,
        "note" => $note,
    ];
    if ($status === "failed") {
        $ok = false;
    }
}
$out = [
    "ok" => $ok,
    "api_base" => $apiBase,
    "target_scale" => $targetScale,
    "scale_used_for_questions" => $scaleUsed,
    "metrics" => $metrics,
];
echo json_encode($out, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES) . PHP_EOL;
' "$METRICS_FILE" "$TARGET_SCALE" "$scale_used" "$API_BASE"
