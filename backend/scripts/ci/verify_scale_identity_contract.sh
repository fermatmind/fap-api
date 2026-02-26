#!/usr/bin/env bash
set -euo pipefail

SCRIPT_DIR="$(cd "$(dirname "${BASH_SOURCE[0]}")" && pwd)"
BACKEND_DIR="$(cd "${SCRIPT_DIR}/../.." && pwd)"

API="${API:-http://127.0.0.1:1827}"
REGION="${REGION:-CN_MAINLAND}"
LOCALE="${LOCALE:-zh-CN}"
LEGACY_EXPECTED_STATUS="${LEGACY_EXPECTED_STATUS:-410}"
V2_EXPECTED_STATUS="${V2_EXPECTED_STATUS:-200}"
LEGACY_EXPECTED_ERROR_CODE="${LEGACY_EXPECTED_ERROR_CODE:-SCALE_CODE_LEGACY_NOT_ACCEPTED}"

need_cmd() { command -v "$1" >/dev/null 2>&1 || { echo "[CI][contract][FAIL] missing cmd: $1" >&2; exit 2; }; }
fail() { echo "[CI][contract][FAIL] $*" >&2; exit 1; }

extract_summary_metric() {
  local summary="$1"
  local key="$2"
  php -r '
$summary = (string) ($argv[1] ?? "");
$key = (string) ($argv[2] ?? "");
$parts = preg_split("/\s+/", trim($summary)) ?: [];
$map = [];
foreach ($parts as $part) {
    if (!is_string($part) || strpos($part, "=") === false) {
        continue;
    }
    [$k, $v] = explode("=", $part, 2);
    $map[$k] = $v;
}
echo (string) ($map[$key] ?? "");
' "$summary" "$key"
}

probe_questions_endpoint() {
  local scale_code="$1"
  local expected_status="$2"
  local expected_kind="$3"
  local expected_replacement="${4:-}"
  local out_file
  out_file="$(mktemp)"

  local http_code
  http_code="$(curl -sS -o "$out_file" -w "%{http_code}" \
    "$API/api/v0.3/scales/${scale_code}/questions?region=${REGION}&locale=${LOCALE}" || true)"

  if [[ "$http_code" != "$expected_status" ]]; then
    echo "[CI][contract][FAIL] questions probe http=${http_code} expected=${expected_status} scale_code=${scale_code}" >&2
    echo "---- body (first 1000 bytes) ----" >&2
    head -c 1000 "$out_file" >&2 || true
    echo >&2
    rm -f "$out_file"
    exit 10
  fi

  if [[ "$expected_status" == "200" ]]; then
    local item_count
    if ! item_count="$(php -r '
$path = (string) ($argv[1] ?? "");
$json = @file_get_contents($path);
$doc = is_string($json) ? json_decode($json, true) : null;
if (!is_array($doc) || !($doc["ok"] ?? false)) {
    fwrite(STDERR, "ok flag is false or payload invalid\n");
    exit(11);
}
$items = $doc["questions"]["items"] ?? null;
if (!is_array($items)) {
    fwrite(STDERR, "questions.items missing\n");
    exit(12);
}
echo (string) count($items);
' "$out_file")"; then
      echo "[CI][contract][FAIL] invalid questions payload scale_code=${scale_code}" >&2
      head -c 1000 "$out_file" >&2 || true
      echo >&2
      rm -f "$out_file"
      exit 11
    fi

    echo "[CI][contract] questions probe ok scale_code=${scale_code} status=${expected_status} items=${item_count}"
    rm -f "$out_file"

    return
  fi

  if [[ "$expected_status" == "410" && "$expected_kind" == "legacy_reject" ]]; then
    if ! php -r '
$path = (string) ($argv[1] ?? "");
$requested = strtoupper(trim((string) ($argv[2] ?? "")));
$replacement = strtoupper(trim((string) ($argv[3] ?? "")));
$expectedErrorCode = trim((string) ($argv[4] ?? ""));
$json = @file_get_contents($path);
$doc = is_string($json) ? json_decode($json, true) : null;
if (!is_array($doc)) {
    fwrite(STDERR, "invalid json body\n");
    exit(12);
}
if (trim((string) ($doc["error_code"] ?? "")) !== $expectedErrorCode) {
    fwrite(STDERR, "unexpected error_code\n");
    exit(13);
}
$details = is_array($doc["details"] ?? null) ? $doc["details"] : [];
$requestedCode = strtoupper(trim((string) ($details["requested_scale_code"] ?? "")));
$legacyCode = strtoupper(trim((string) ($details["scale_code_legacy"] ?? "")));
$replacementCode = strtoupper(trim((string) ($details["replacement_scale_code_v2"] ?? "")));
if ($requestedCode !== $requested) {
    fwrite(STDERR, "details.requested_scale_code mismatch\n");
    exit(14);
}
if ($legacyCode !== $requested) {
    fwrite(STDERR, "details.scale_code_legacy mismatch\n");
    exit(15);
}
if ($replacement !== "" && $replacementCode !== $replacement) {
    fwrite(STDERR, "details.replacement_scale_code_v2 mismatch\n");
    exit(16);
}
' "$out_file" "$scale_code" "$expected_replacement" "$LEGACY_EXPECTED_ERROR_CODE"; then
      echo "[CI][contract][FAIL] invalid legacy rejection payload scale_code=${scale_code}" >&2
      head -c 1000 "$out_file" >&2 || true
      echo >&2
      rm -f "$out_file"
      exit 12
    fi

    echo "[CI][contract] questions probe ok scale_code=${scale_code} status=${expected_status} replacement=${expected_replacement}"
    rm -f "$out_file"

    return
  fi

  echo "[CI][contract] questions probe ok scale_code=${scale_code} status=${expected_status}"
  rm -f "$out_file"
}

run_mirror_verify_pass() {
  local label="$1"
  local log_file="$2"

  if ! php artisan ops:content-path-mirror --sync --verify-hash >"$log_file" 2>&1; then
    echo "[CI][contract][FAIL] content-path-mirror failed (${label})" >&2
    tail -n 200 "$log_file" >&2 || true
    exit 20
  fi

  local summary
  summary="$(grep -E '^content_path_mirror ' "$log_file" | tail -n 1 || true)"
  if [[ -z "$summary" ]]; then
    echo "[CI][contract][FAIL] mirror summary missing (${label})" >&2
    tail -n 200 "$log_file" >&2 || true
    exit 21
  fi

  MIRROR_COPIED="$(extract_summary_metric "$summary" "sync_copied_files")"
  MIRROR_UPDATED="$(extract_summary_metric "$summary" "sync_updated_files")"
  MIRROR_MISMATCH="$(extract_summary_metric "$summary" "verify_mismatch_files")"
  MIRROR_TARGET_MISSING="$(extract_summary_metric "$summary" "verify_target_missing_files")"

  [[ -n "$MIRROR_COPIED" ]] || fail "mirror summary missing sync_copied_files (${label})"
  [[ -n "$MIRROR_UPDATED" ]] || fail "mirror summary missing sync_updated_files (${label})"
  [[ -n "$MIRROR_MISMATCH" ]] || fail "mirror summary missing verify_mismatch_files (${label})"
  [[ -n "$MIRROR_TARGET_MISSING" ]] || fail "mirror summary missing verify_target_missing_files (${label})"

  if [[ "$MIRROR_MISMATCH" != "0" ]]; then
    fail "mirror verify_mismatch_files=${MIRROR_MISMATCH} (${label})"
  fi
  if [[ "$MIRROR_TARGET_MISSING" != "0" ]]; then
    fail "mirror verify_target_missing_files=${MIRROR_TARGET_MISSING} (${label})"
  fi

  echo "[CI][contract] mirror ${label} copied=${MIRROR_COPIED} updated=${MIRROR_UPDATED} mismatch=${MIRROR_MISMATCH} target_missing=${MIRROR_TARGET_MISSING}"
}

need_cmd curl
need_cmd php
need_cmd grep
need_cmd tail
need_cmd mktemp

cd "$BACKEND_DIR"

echo "[CI][contract] six-scale legacy/v2 questions probes API=${API} region=${REGION} locale=${LOCALE} legacy_expected_status=${LEGACY_EXPECTED_STATUS} v2_expected_status=${V2_EXPECTED_STATUS}"
while IFS='|' read -r legacy_code v2_code; do
  [[ -n "$legacy_code" ]] || continue
  [[ -n "$v2_code" ]] || continue
  probe_questions_endpoint "$legacy_code" "$LEGACY_EXPECTED_STATUS" "legacy_reject" "$v2_code"
  probe_questions_endpoint "$v2_code" "$V2_EXPECTED_STATUS" "v2_success" ""
done <<'EOF'
MBTI|MBTI_PERSONALITY_TEST_16_TYPES
BIG5_OCEAN|BIG_FIVE_OCEAN_MODEL
CLINICAL_COMBO_68|CLINICAL_DEPRESSION_ANXIETY_PRO
SDS_20|DEPRESSION_SCREENING_STANDARD
IQ_RAVEN|IQ_INTELLIGENCE_QUOTIENT
EQ_60|EQ_EMOTIONAL_INTELLIGENCE
EOF

echo "[CI][contract] mirror verify pass #1"
MIRROR_LOG_1="$(mktemp)"
MIRROR_LOG_2="$(mktemp)"
run_mirror_verify_pass "first" "$MIRROR_LOG_1"
FIRST_COPIED="$MIRROR_COPIED"
FIRST_UPDATED="$MIRROR_UPDATED"

echo "[CI][contract] mirror verify pass #2 (idempotency)"
run_mirror_verify_pass "second" "$MIRROR_LOG_2"
SECOND_COPIED="$MIRROR_COPIED"
SECOND_UPDATED="$MIRROR_UPDATED"

if [[ "$SECOND_COPIED" != "0" || "$SECOND_UPDATED" != "0" ]]; then
  echo "[CI][contract][FAIL] mirror not idempotent on second pass copied=${SECOND_COPIED} updated=${SECOND_UPDATED}" >&2
  echo "[CI][contract] first pass copied=${FIRST_COPIED} updated=${FIRST_UPDATED}" >&2
  exit 22
fi

rm -f "$MIRROR_LOG_1" "$MIRROR_LOG_2"
echo "[CI][contract] mirror idempotency passed"

echo "[CI][contract] strict scale identity mode audit"
set +e
MODE_AUDIT_OUTPUT="$(php artisan ops:scale-identity-mode-audit --json=1 --strict=1 2>&1)"
MODE_AUDIT_EXIT=$?
set -e

if [[ "$MODE_AUDIT_EXIT" -ne 0 ]]; then
  echo "[CI][contract][FAIL] strict mode audit command failed exit=${MODE_AUDIT_EXIT}" >&2
  echo "$MODE_AUDIT_OUTPUT" >&2
  exit 29
fi

MODE_AUDIT_JSON="$(printf '%s\n' "$MODE_AUDIT_OUTPUT" | tail -n 1)"
if ! php -r '
$json = (string) ($argv[1] ?? "");
$payload = json_decode($json, true);
if (!is_array($payload)) {
    fwrite(STDERR, "invalid mode audit json\n");
    exit(1);
}
if (!($payload["pass"] ?? false)) {
    fwrite(STDERR, "mode audit pass=false\n");
    exit(2);
}
' "$MODE_AUDIT_JSON"; then
  echo "[CI][contract][FAIL] strict mode audit payload validation failed" >&2
  echo "$MODE_AUDIT_OUTPUT" >&2
  exit 29
fi

echo "[CI][contract] strict mode audit passed"

echo "[CI][contract] strict scale identity gate"
: "${FAP_GATE_IDENTITY_RESOLVE_MISMATCH_RATE_MAX:=0}"
: "${FAP_GATE_DUAL_WRITE_MISMATCH_RATE_MAX:=0}"
: "${FAP_GATE_CONTENT_PATH_FALLBACK_RATE_MAX:=0}"
: "${FAP_GATE_LEGACY_CODE_HIT_RATE_MAX:=0}"
: "${FAP_GATE_DEMO_SCALE_HIT_RATE_MAX:=0}"
export FAP_GATE_IDENTITY_RESOLVE_MISMATCH_RATE_MAX
export FAP_GATE_DUAL_WRITE_MISMATCH_RATE_MAX
export FAP_GATE_CONTENT_PATH_FALLBACK_RATE_MAX
export FAP_GATE_LEGACY_CODE_HIT_RATE_MAX
export FAP_GATE_DEMO_SCALE_HIT_RATE_MAX

set +e
GATE_OUTPUT="$(php artisan ops:scale-identity-gate --json=1 --strict=1 2>&1)"
GATE_EXIT=$?
set -e

if [[ "$GATE_EXIT" -ne 0 ]]; then
  echo "[CI][contract][FAIL] strict gate command failed exit=${GATE_EXIT}" >&2
  echo "$GATE_OUTPUT" >&2
  exit 30
fi

GATE_JSON="$(printf '%s\n' "$GATE_OUTPUT" | tail -n 1)"
if ! php -r '
$json = (string) ($argv[1] ?? "");
$payload = json_decode($json, true);
if (!is_array($payload)) {
    fwrite(STDERR, "invalid gate json\n");
    exit(31);
}
if (!($payload["pass"] ?? false)) {
    fwrite(STDERR, "gate pass=false\n");
    exit(32);
}
' "$GATE_JSON"; then
  echo "[CI][contract][FAIL] strict gate payload validation failed" >&2
  echo "$GATE_OUTPUT" >&2
  exit 31
fi

echo "[CI][contract] strict gate passed"
echo "[CI][contract] scale identity contract verification completed"
