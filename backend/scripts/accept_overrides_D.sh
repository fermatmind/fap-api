#!/usr/bin/env bash
set -euo pipefail

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
need_cmd jq

SCRIPT_DIR="$(cd "$(dirname "${BASH_SOURCE[0]}")" && pwd)"
BACKEND_DIR="$(cd "$SCRIPT_DIR/.." && pwd)"
REPO_DIR="$(cd "$BACKEND_DIR/.." && pwd)"
CONTENT_ROOT="${CONTENT_ROOT:-$REPO_DIR/content_packages}"

PKG_REL="${MBTI_CONTENT_PACKAGE:-default/CN_MAINLAND/zh-CN/MBTI-CN-v0.2.1-TEST}"
PKG_DIR="${PKG_DIR:-$CONTENT_ROOT/$PKG_REL}"
OVR_FILE="${OVR_FILE:-$PKG_DIR/report_overrides.json}"

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
  echo "Tip: export MBTI_CONTENT_PACKAGE=default/CN_MAINLAND/zh-CN/MBTI-CN-v0.2.1-TEST"
  echo "     or export PKG_DIR=... or export OVR_FILE=..."
  exit 1
fi

log_full() { [[ "$MODE" == "full" ]] && echo "$@"; }
log_key()  { [[ "$MODE" != "json" ]] && echo "$@"; }
emit_json(){ [[ "$MODE" == "json" ]] && printf '%s\n' "$1" || true; }

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
  local url="$BASE/api/v0.2/attempts/$ATT/report?refresh=1"
  local http=""

  http="$(curl -sS -L -o "$TMP_REPORT" -w "%{http_code}" \
    -H 'Accept: application/json' \
    "$url" || true)"

  if [[ "$http" != "200" ]]; then
    echo "❌ call_refresh HTTP=$http url=$url" >&2
    echo "---- body (first 400 bytes) ----" >&2
    head -c 400 "$TMP_REPORT" 2>/dev/null || true
    echo >&2
    exit 2
  fi

  jq -e '.ok==true and (.report.versions.engine!=null)' "$TMP_REPORT" >/dev/null

  if [[ "${SAVE_REPORTS}" == "1" && -n "${OUT_DIR}" ]]; then
    cp -a "$TMP_REPORT" "$OUT_DIR/report.${label}.json" 2>/dev/null || true
  fi
}

jq_absent() { jq -e "$@" "$TMP_REPORT" >/dev/null 2>&1 && return 1 || return 0; }
jq_present(){ jq -e "$@" "$TMP_REPORT" >/dev/null 2>&1; }

# Write a test overrides doc that contains BOTH keys: overrides + rules
# so no matter which key your engine reads, it will work.
write_doc_with_list() {
  local list_json="$1"
  cat > "$OVR_FILE" <<JSON
{"schema":"fap.report.overrides.v1","engine":"v1","overrides":$list_json,"rules":$list_json}
JSON
}

# Pick a stable traits card id from the current report (for D3 conflict)
pick_traits_card_id() {
  jq -r '.report.sections.traits.cards[]?.id' "$TMP_REPORT" 2>/dev/null | head -n 1
}
get_traits_card_title_by_id() {
  local cid="$1"
  jq -r --arg cid "$cid" '.report.sections.traits.cards[]? | select(.id==$cid) | (.title // "")' "$TMP_REPORT" 2>/dev/null
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

  write_doc_with_list "$(jq -cn --arg tid "$test_id" '
    [
      {
        id:"zz_d0_append",
        target:"cards",
        priority:10,
        match:{section:["traits"]},
        mode:"append",
        items:[{id:$tid, section:"traits", title:"（OVR验收）D0 append ok", desc:"D0 baseline proof"}]
      }
    ]
  ')"

  call_refresh "D0_BASELINE"
  log_key "✅ report generated"

  if jq_present --arg tid "$test_id" '.report.sections.traits.cards[]? | select(.id==$tid)' ; then
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

  if jq_absent --arg tid "$test_id" '.report.sections.traits.cards[]? | select(.id==$tid)' ; then
    log_key "✅ D1 OK: test card absent under empty overrides"
  else
    log_key "❌ D1 FAIL: test card still present under empty overrides"
    exit 1
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

  write_doc_with_list "$(jq -cn --arg tid "$test_id" '
    [
      {
        id:"zz_d2_no_hit",
        target:"cards",
        priority:10,
        match:{section:["__no_such_section__"]},
        mode:"append",
        items:[{id:$tid, section:"traits", title:"（OVR验收）D2 should NOT hit", desc:""}]
      }
    ]
  ')"

  call_refresh "D2_NO_MATCH"
  log_key "✅ report generated"

  if jq_absent --arg tid "$test_id" '.report.sections.traits.cards[]? | select(.id==$tid)' ; then
    log_key "✅ D2 OK: no-hit append did not apply"
  else
    log_key "❌ D2 FAIL: no-hit append applied unexpectedly"
    exit 1
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
  write_doc_with_list "$(jq -cn --arg cid "$cid" '
    [
      {
        id:"zz_d3_conflict_low",
        target:"cards",
        priority:60,
        match:{item:[$cid]},
        mode:"patch",
        replace_fields:["title"],
        patch:{title:"（OVR验收）冲突规则-PRIO60"}
      },
      {
        id:"zz_d3_conflict_high",
        target:"cards",
        priority:70,
        match:{item:[$cid]},
        mode:"patch",
        replace_fields:["title"],
        patch:{title:"（OVR验收）冲突规则生效-PRIO70"}
      }
    ]
  ')"

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

run_D0
run_D1
run_D2
run_D3

log_key ""
log_key "✅ ALL DONE: D-1 / D-2 / D-3 passed"

emit_json "$(jq -cn \
  --arg ok "true" \
  --arg base "$BASE" \
  --arg att "$ATT" \
  --arg ovr_file "$OVR_FILE" \
  --arg tmp_report "$TMP_REPORT" \
  --arg out_dir "${OUT_DIR:-}" \
  '{ok:($ok=="true"), base:$base, attempt_id:$att, ovr_file:$ovr_file, tmp_report:$tmp_report, out_dir:$out_dir, d0:"pass", d1:"pass", d2:"pass", d3:"pass"}'
)"

exit 0