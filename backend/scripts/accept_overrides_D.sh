#!/usr/bin/env bash
set -euo pipefail

SCRIPT_DIR="$(cd "$(dirname "${BASH_SOURCE[0]}")" && pwd)"
BACKEND_DIR="$(cd "$SCRIPT_DIR/.." && pwd)"
ARTIFACTS="${ARTIFACTS:-${BACKEND_DIR}/artifacts/verify_mbti}"
ANON_ID="${ANON_ID:-}"
if [[ -z "${ANON_ID}" && -f "${ARTIFACTS}/anon_id.txt" ]]; then
  ANON_ID="$(tr -d '\r\n' < "${ARTIFACTS}/anon_id.txt")"
fi
OWNER_HDR=()
if [[ -n "${ANON_ID}" ]]; then
  OWNER_HDR=(-H "X-Anon-Id: ${ANON_ID}")
fi

# -----------------------------
# Auth (fm_token) for gated endpoints
# -----------------------------
CURL_AUTH=()
if [[ -n "${FM_TOKEN:-}" ]]; then
  CURL_AUTH=(-H "Authorization: Bearer ${FM_TOKEN}")
fi

# =========================
# Config
# =========================
BASE="${BASE:-http://127.0.0.1:8000}"
ATT="${1:-${ATT:-${ATTEMPT_ID:-}}}"

if [[ -z "${ATT}" ]]; then
  echo "Usage: $0 <ATTEMPT_ID>"
  echo "Or export ATT=<id> then run: $0"
  exit 1
fi

need_cmd() { command -v "$1" >/dev/null 2>&1 || { echo "❌ missing command: $1"; exit 1; }; }
need_cmd curl
need_cmd php

REPO_DIR="$(cd "$BACKEND_DIR/.." && pwd)"
CONTENT_ROOT="${CONTENT_ROOT:-$REPO_DIR/content_packages}"

PKG_REL="${MBTI_CONTENT_PACKAGE:-default/CN_MAINLAND/zh-CN/MBTI-CN-v0.3}"
PKG_DIR="${PKG_DIR:-$CONTENT_ROOT/$PKG_REL}"

RESOLVED_OVR=""
RESOLVE_OUT="$(cd "$BACKEND_DIR" && php artisan fap:resolve-pack MBTI CN_MAINLAND zh-CN MBTI-CN-v0.3 -vvv 2>/dev/null || true)"
BASE_DIR="$(printf '%s' "$RESOLVE_OUT" | tr -d '\r' | awk -F= '/^base_dir=/{print $2; exit}')"
if [[ -n "${BASE_DIR}" ]]; then
  RESOLVED_OVR="${BASE_DIR%/}/report_overrides.json"
fi

OVR_FILE="${OVR_FILE:-${RESOLVED_OVR:-$PKG_DIR/report_overrides.json}}"

# MODE:
#   - full: verbose output (default)
#   - key : only key PASS/FAIL lines
#   - json: print a single JSON line only (CI-friendly)
MODE="${MODE:-full}"

# OUT_DIR: if set, TMP_REPORT defaults to OUT_DIR/report.json (artifact-friendly)
OUT_DIR="${OUT_DIR:-}"
if [[ -n "$OUT_DIR" ]]; then
  mkdir -p "$OUT_DIR"
  TMP_REPORT="${TMP_REPORT:-$OUT_DIR/report.json}"
else
  TMP_REPORT="${TMP_REPORT:-/tmp/report.json}"
fi

SAVE_REPORTS="${SAVE_REPORTS:-0}"

if [[ ! -f "$OVR_FILE" ]]; then
  echo "❌ overrides file not found: $OVR_FILE"
  echo "Tip: export MBTI_CONTENT_PACKAGE=default/CN_MAINLAND/zh-CN/MBTI-CN-v0.3"
  echo "     or export PKG_DIR=... or export OVR_FILE=..."
  exit 1
fi

if [[ -z "${EXPIRES_AT:-}" ]]; then
  if date -u -d "+7 days" "+%Y-%m-%dT%H:%M:%SZ" >/dev/null 2>&1; then
    EXPIRES_AT="$(date -u -d "+7 days" "+%Y-%m-%dT%H:%M:%SZ")"
  else
    EXPIRES_AT="$(date -u -v +7d "+%Y-%m-%dT%H:%M:%SZ")"
  fi
fi

log_full() { [[ "$MODE" == "full" ]] && echo "$@"; }
log_key()  { [[ "$MODE" != "json" ]] && echo "$@"; }
emit_json(){
  [[ "$MODE" == "json" ]] || return 0
  BASE="$BASE" ATT="$ATT" OVR_FILE="$OVR_FILE" TMP_REPORT="$TMP_REPORT" OUT_DIR="${OUT_DIR:-}" \
  php -r '
$payload = [
  "ok" => true,
  "base" => getenv("BASE") ?: "",
  "attempt_id" => getenv("ATT") ?: "",
  "ovr_file" => getenv("OVR_FILE") ?: "",
  "tmp_report" => getenv("TMP_REPORT") ?: "",
  "out_dir" => getenv("OUT_DIR") ?: "",
  "d0" => "pass",
  "d1" => "pass",
  "d2" => "pass",
  "d3" => "pass",
];
echo json_encode($payload, JSON_UNESCAPED_UNICODE) . PHP_EOL;
'
}

# =========================
# Backup/Restore (single backup, always restore)
# =========================
backup_path="$OVR_FILE.bak.$(date +%s).$$.$RANDOM"
cp -a "$OVR_FILE" "$backup_path"
log_key "🧷 backup: $backup_path"

restore() {
  if [[ -f "$backup_path" ]]; then
    mv -f "$backup_path" "$OVR_FILE"
    log_key "↩️  restored overrides from: $backup_path"
  fi
}
trap restore EXIT

# =========================
# Helpers
# =========================
call_refresh() {
  local label="$1"
  local refresh_url="$BASE/api/v0.3/attempts/$ATT/report?refresh=1"
  local poll_url="$BASE/api/v0.3/attempts/$ATT/report"
  local http=""
  local curl_args=()
  local max_tries="${REFRESH_MAX_TRIES:-30}"
  local attempt=0
  local ready=0

  if [[ ${#CURL_AUTH[@]} -gt 0 ]]; then
    curl_args+=("${CURL_AUTH[@]}")
  fi
  if [[ ${#OWNER_HDR[@]} -gt 0 ]]; then
    curl_args+=("${OWNER_HDR[@]}")
  fi

  while (( attempt < max_tries )); do
    attempt=$((attempt + 1))
    local url="$poll_url"
    if (( attempt == 1 )); then
      url="$refresh_url"
    fi
    http="$(curl -sS -L -o "$TMP_REPORT" -w "%{http_code}" \
      "${curl_args[@]}" \
      -H 'Accept: application/json' \
      "$url" || true)"

    if [[ "$http" != "200" && "$http" != "202" ]]; then
      echo "❌ call_refresh HTTP=$http url=$url" >&2
      echo "---- body (first 400 bytes) ----" >&2
      head -c 400 "$TMP_REPORT" 2>/dev/null || true
      echo >&2
      exit 2
    fi

    if FILE="$TMP_REPORT" php -r '
$f=getenv("FILE");
$j=json_decode(file_get_contents($f), true);
if (!is_array($j) || ($j["ok"] ?? false) !== true) { exit(11); }
$report = $j["report"] ?? null;
$traitsCards = $report["sections"]["traits"]["cards"] ?? null;
if (is_array($traitsCards) && $traitsCards !== []) { exit(0); }
$engine = $report["versions"]["engine"] ?? null;
if (is_string($engine) && trim($engine) !== "") { exit(0); }
$generating = (bool)($j["generating"] ?? ($j["meta"]["generating"] ?? false));
if ($generating || !is_array($report) || $report === []) { exit(10); }
exit(11);
'; then
      ready=1
      break
    fi

    (
      cd "$BACKEND_DIR"
      php artisan queue:work database --queue=attempts --once --sleep=0 --tries=1 --timeout=30 --no-interaction >/dev/null 2>&1 || true
      php artisan queue:work database --queue=reports --once --sleep=0 --tries=1 --timeout=30 --no-interaction >/dev/null 2>&1 || true
    )

    local retry_after=0
    retry_after="$(FILE="$TMP_REPORT" php -r '
$j=json_decode(file_get_contents(getenv("FILE")), true);
if (!is_array($j)) { echo 0; exit; }
$v = (int) ($j["retry_after_seconds"] ?? ($j["meta"]["retry_after_seconds"] ?? 0));
echo $v > 0 ? $v : 0;
')"
    if [[ ! "$retry_after" =~ ^[0-9]+$ ]]; then
      retry_after=0
    fi
    if (( retry_after > 0 )); then
      sleep "$retry_after"
    else
      sleep 1
    fi
  done

  if [[ "$ready" != "1" ]]; then
    echo "❌ call_refresh timed out waiting report ready (label=${label})" >&2
    echo "---- body (first 400 bytes) ----" >&2
    head -c 400 "$TMP_REPORT" 2>/dev/null || true
    echo >&2
    exit 2
  fi

  if [[ "${SAVE_REPORTS}" == "1" && -n "${OUT_DIR}" ]]; then
    cp -a "$TMP_REPORT" "$OUT_DIR/report.${label}.json" 2>/dev/null || true
  fi
}

report_has_traits_card_id() {
  local id="$1"
  FILE="$TMP_REPORT" ID="$id" php -r '
$f=getenv("FILE");
$id=getenv("ID");
$j=json_decode(file_get_contents($f), true);
$cards=$j["report"]["sections"]["traits"]["cards"] ?? [];
if (!is_array($cards)) { exit(1); }
foreach ($cards as $c) {
  if (($c["id"] ?? "") === $id) { exit(0); }
}
exit(1);
'
}

# Write a test overrides doc that contains BOTH keys: overrides + rules
# so no matter which key your engine reads, it will work.
write_doc_with_list() {
  local list_json="$1"
  LIST_JSON="$list_json" OVR_FILE="$OVR_FILE" php -r '
$list=json_decode(getenv("LIST_JSON"), true);
if (!is_array($list)) {
  fwrite(STDERR, "invalid overrides list json" . PHP_EOL);
  exit(2);
}
$doc=[
  "schema" => "fap.report.overrides.v1",
  "engine" => "v1",
  "overrides" => $list,
  "rules" => $list,
];
file_put_contents(getenv("OVR_FILE"), json_encode($doc, JSON_UNESCAPED_UNICODE|JSON_PRETTY_PRINT));
'
}

# Pick a stable traits card id from the current report (for D3 conflict)
pick_traits_card_id() {
  FILE="$TMP_REPORT" php -r '
$f=getenv("FILE");
$j=json_decode(file_get_contents($f), true);
$cards=$j["report"]["sections"]["traits"]["cards"] ?? [];
if (!is_array($cards)) { exit(1); }
foreach ($cards as $c) {
  $id=$c["id"] ?? "";
  if ($id !== "") { echo $id; exit(0); }
}
exit(1);
'
}

get_traits_card_title_by_id() {
  local cid="$1"
  FILE="$TMP_REPORT" CID="$cid" php -r '
$f=getenv("FILE");
$cid=getenv("CID");
$j=json_decode(file_get_contents($f), true);
$cards=$j["report"]["sections"]["traits"]["cards"] ?? [];
if (!is_array($cards)) { exit(0); }
foreach ($cards as $c) {
  if (($c["id"] ?? "") === $cid) { echo (string)($c["title"] ?? ""); exit(0); }
}
exit(0);
'
}

# =========================
# D-0: baseline (prove overrides loader works) via APPEND a test card
# =========================
run_D0() {
  log_key ""
  log_key "===================="
  log_key "D-0: baseline (prove overrides loader works)"
  log_key "===================="

  local test_id="zz_ci_test_card_01"

  local list_json
  list_json="$(cat <<JSON
[
  {
    "id": "zz_d0_append",
    "target": "cards",
    "priority": 10,
    "match": {"section": ["traits"]},
    "mode": "append",
    "items": [
      {"id": "$test_id", "section": "traits", "title": "（OVR验收）D0 append ok", "desc": "D0 baseline proof"}
    ],
    "expires_at": "$EXPIRES_AT"
  }
]
JSON
)"

  write_doc_with_list "$list_json"

  call_refresh "D0_BASELINE"
  log_key "✅ report generated"

  if report_has_traits_card_id "$test_id"; then
    log_key "✅ D0 OK: test card appended => overrides loader works"
  else
    log_key "❌ D0 FAIL: test card NOT appended => engine did not load overrides doc"
    exit 1
  fi
}

# =========================
# D-1: overrides empty -> test card must be absent
# =========================
run_D1() {
  log_key ""
  log_key "===================="
  log_key "D-1: overrides empty"
  log_key "===================="

  local test_id="zz_ci_test_card_01"

  write_doc_with_list "[]"
  call_refresh "D1_EMPTY"
  log_key "✅ report generated"

  if report_has_traits_card_id "$test_id"; then
    log_key "❌ D1 FAIL: test card still present under empty overrides"
    exit 1
  else
    log_key "✅ D1 OK: test card absent under empty overrides"
  fi
}

# =========================
# D-2: wrong match/no hit -> test append must NOT hit
# =========================
run_D2() {
  log_key ""
  log_key "===================="
  log_key "D-2: wrong match/no hit"
  log_key "===================="

  local test_id="zz_ci_test_card_02"

  local list_json
  list_json="$(cat <<JSON
[
  {
    "id": "zz_d2_no_hit",
    "target": "cards",
    "priority": 10,
    "match": {"section": ["__no_such_section__"]},
    "mode": "append",
    "items": [
      {"id": "$test_id", "section": "traits", "title": "（OVR验收）D2 should NOT hit", "desc": ""}
    ],
    "expires_at": "$EXPIRES_AT"
  }
]
JSON
)"

  write_doc_with_list "$list_json"
  call_refresh "D2_NO_MATCH"
  log_key "✅ report generated"

  if report_has_traits_card_id "$test_id"; then
    log_key "❌ D2 FAIL: no-hit append applied unexpectedly"
    exit 1
  else
    log_key "✅ D2 OK: no-hit append did not apply"
  fi
}

# =========================
# D-3: multi-hit ordering -> patch same traits card title, higher priority must win
# =========================
run_D3() {
  log_key ""
  log_key "===================="
  log_key "D-3: multi-hit ordering (cards patch)"
  log_key "===================="

  # First refresh with empty doc to get a real card id
  write_doc_with_list "[]"
  call_refresh "D3_PROBE"

  local cid
  cid="$(pick_traits_card_id || true)"
  if [[ -z "$cid" ]]; then
    log_key "❌ D3 FAIL: cannot pick a traits card id"
    exit 1
  fi
  log_key "✅ D3 picked card id=$cid"

  # Now write conflicting patch rules on the same card title
  local list_json
  list_json="$(cat <<JSON
[
  {
    "id": "zz_d3_conflict_low",
    "target": "cards",
    "priority": 60,
    "match": {"item": ["$cid"]},
    "mode": "patch",
    "replace_fields": ["title"],
    "patch": {"title": "（OVR验收）冲突规则-PRIO60"},
    "expires_at": "$EXPIRES_AT"
  },
  {
    "id": "zz_d3_conflict_high",
    "target": "cards",
    "priority": 70,
    "match": {"item": ["$cid"]},
    "mode": "patch",
    "replace_fields": ["title"],
    "patch": {"title": "（OVR验收）冲突规则生效-PRIO70"},
    "expires_at": "$EXPIRES_AT"
  }
]
JSON
)"

  write_doc_with_list "$list_json"
  call_refresh "D3_CONFLICT"
  log_key "✅ report generated"

  local got
  got="$(get_traits_card_title_by_id "$cid")"

  if [[ "$got" == "（OVR验收）冲突规则生效-PRIO70" ]]; then
    log_key "✅ D3 OK: higher priority conflict rule wins"
  else
    log_key "❌ D3 FAIL: conflict-high rule did not win"
    log_key "   got_title=$got"
    log_key "   expected=（OVR验收）冲突规则生效-PRIO70"
    exit 1
  fi
}

# =========================
# Main
# =========================
log_key "BASE=$BASE"
log_key "ATT=$ATT"
log_key "PKG_DIR=$PKG_DIR"
log_key "OVR_FILE=$OVR_FILE"
log_key "TMP_REPORT=$TMP_REPORT"
log_key "EXPIRES_AT=$EXPIRES_AT"

run_D0
run_D1
run_D2
run_D3

log_key ""
log_key "✅ ALL DONE: D-1 / D-2 / D-3 passed"

emit_json

exit 0
