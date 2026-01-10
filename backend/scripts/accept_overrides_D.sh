#!/usr/bin/env bash
set -euo pipefail

# =========================
# Config (match your habits)
# =========================
BASE="${BASE:-http://127.0.0.1:8000}"
ATT="${1:-${ATT:-}}"

if [[ -z "${ATT}" ]]; then
  echo "Usage: $0 <ATTEMPT_ID>"
  echo "Or export ATT=<id> then run: $0"
  exit 1
fi

# Try to locate your content package dir; override by env if needed
PKG_DIR="${PKG_DIR:-/Users/rainie/Desktop/GitHub/fap-api/content_packages/default/CN_MAINLAND/zh-CN/MBTI-CN-v0.2.1-TEST}"
OVR_FILE="${OVR_FILE:-$PKG_DIR/report_overrides.json}"

# Output control for integration/CI
# MODE:
#   - full: original verbose output (default)
#   - key : only key PASS/FAIL lines
#   - json: print a single JSON line only (best for CI/verify_mbti parsing)
MODE="${MODE:-full}"

# If OUT_DIR is set, TMP_REPORT defaults to OUT_DIR/report.json (artifact-friendly)
OUT_DIR="${OUT_DIR:-}"
if [[ -n "$OUT_DIR" ]]; then
  mkdir -p "$OUT_DIR"
  TMP_REPORT="${TMP_REPORT:-$OUT_DIR/report.json}"
else
  TMP_REPORT="${TMP_REPORT:-/tmp/report.json}"
fi

# Save each refresh report as OUT_DIR/report.<LABEL>.json when enabled
SAVE_REPORTS="${SAVE_REPORTS:-0}"

if [[ ! -f "$OVR_FILE" ]]; then
  echo "❌ overrides file not found: $OVR_FILE"
  echo "Tip: export PKG_DIR=... or export OVR_FILE=..."
  exit 1
fi

need_cmd() { command -v "$1" >/dev/null 2>&1 || { echo "❌ missing command: $1"; exit 1; }; }
need_cmd curl
need_cmd jq

has_marklog=0
has_fromline=0
command -v marklog >/dev/null 2>&1 && has_marklog=1 || true
command -v fromline >/dev/null 2>&1 && has_fromline=1 || true

backup_path=""
backup() {
  backup_path="$OVR_FILE.bak.$(date +%s).$$.$RANDOM"
  cp -a "$OVR_FILE" "$backup_path"
  echo "🧷 backup: $backup_path"
}

restore() {
  if [[ -n "${backup_path}" && -f "${backup_path}" ]]; then
    mv -f "$backup_path" "$OVR_FILE"
    echo "↩️  restored overrides from: $backup_path"
  else
    echo "⚠️  no backup to restore"
  fi
}

# Output helpers
log_full() { [[ "$MODE" == "full" ]] && echo "$@"; }
log_key()  { [[ "$MODE" != "json" ]] && echo "$@"; }
emit_json(){ [[ "$MODE" == "json" ]] && printf '%s\n' "$1" || true; }

call_refresh() {
  local label="$1"
  local mark_line=""

  if [[ $has_marklog -eq 1 ]]; then
    mark_line="$(marklog "$label")" || true
  fi

    curl -fsS -H 'Accept: application/json' \
    "$BASE/api/v0.2/attempts/$ATT/report?refresh=1" \
    > "$TMP_REPORT"

  # basic sanity
  jq -e '.ok==true and (.report.versions.engine!=null)' "$TMP_REPORT" >/dev/null

  # optional: save each refresh output for artifacts
  if [[ "${SAVE_REPORTS}" == "1" && -n "${OUT_DIR}" ]]; then
    cp -a "$TMP_REPORT" "$OUT_DIR/report.${label}.json" 2>/dev/null || true
  fi

  echo "$mark_line"
}

# jq helpers (exit 0 = pass, exit 1 = fail)
jq_absent() {
  # usage: jq_absent [jq_opts...] '<filter>'
  # e.g. jq_absent --arg tid "$target_id" '.report.highlights[]? | select(.id==$tid)'
  if jq -e "$@" "$TMP_REPORT" >/dev/null 2>&1; then
    return 1
  fi
  return 0
}

jq_present() {
  # usage: jq_present [jq_opts...] '<filter>'
  # e.g. jq_present --arg tid "$target_id" '.report.highlights[]? | select(.id==$tid)'
  jq -e "$@" "$TMP_REPORT" >/dev/null 2>&1
}

log_snip_C_order() {
  local mark_line="$1"
  [[ -z "$mark_line" ]] && return 0
  [[ $has_fromline -eq 0 ]] && return 0

  echo "---- logs (C-order snippet) ----"
  fromline "$mark_line" \
    | egrep -n '\[CARDS\] selected \(base\)|\[RE\] explain \{"ctx":"(highlights|reads):overrides"|\[OVR\] applied' \
    | head -n 220 || true
  echo "--------------------------------"
}

# =========================
# D-1: overrides empty -> report still generates, and no overrides effects
# =========================
run_D1() {
  log_full ""
  log_full "===================="
  log_full "D-1: overrides empty"
  log_full "===================="

  backup
  # Write a valid empty overrides doc
  cat > "$OVR_FILE" <<'JSON'
{"schema":"fap.report.overrides.v1","engine":"v1","rules":[]}
JSON

  local mark_line
  mark_line="$(call_refresh "D1_EMPTY_OVR")"
  echo "✅ report generated"

  # Assertion: traits_extra_01 should NOT exist (append rule gone)
  if jq_absent '.report.sections.traits.cards[]? | select(.id=="traits_extra_01")' ; then
    echo "✅ D1 OK: traits_extra_01 absent (no cards override applied)"
  else
    echo "❌ D1 FAIL: traits_extra_01 still present"
    restore
    exit 1
  fi

  # Assertion: highlight title should NOT contain OVR marker (if your overrides used that marker)
  # (soft check: only fail if it DOES contain marker)
  if jq_present '.report.highlights[]? | select(.id=="hl.action.generated_01") | .title | test("（OVR验收）")' ; then
    echo "❌ D1 FAIL: highlight still shows OVR marker under empty overrides"
    restore
    exit 1
  else
    echo "✅ D1 OK: highlight not showing OVR marker (as expected)"
  fi

  log_snip_C_order "$mark_line"
  restore
}

# =========================
# D-2: wrong match -> no hit; report still generates; highlight not overridden
# =========================
run_D2() {
  log_full ""
  log_full "===================="
  log_full "D-2: wrong match/no hit"
  log_full "===================="

  backup
  # Modify rule hl_patch_action_01 to match a non-existing highlight id.
  # If your overrides file doesn't have that rule id, this will still keep doc valid.
  tmp=/tmp/report_overrides.no_match.$$.json
  jq '
    .rules |= (if type=="array" then map(
      if (.id? // "")=="hl_patch_action_01" then
        (.match.item=["__no_such_highlight_id__"])
      else . end
    ) else . end)
  ' "$OVR_FILE" > "$tmp"
  mv "$tmp" "$OVR_FILE"

  local mark_line
  mark_line="$(call_refresh "D2_NO_MATCH")"
  echo "✅ report generated"

  # Assertion: hl.action.generated_01 title should NOT contain OVR marker
  if jq_present '.report.highlights[]? | select(.id=="hl.action.generated_01") | .title | test("（OVR验收）")' ; then
    echo "❌ D2 FAIL: highlight still overridden even though match is wrong"
    restore
    exit 1
  else
    echo "✅ D2 OK: highlight not overridden (no match)"
  fi

  log_snip_C_order "$mark_line"
  restore
}

# =========================
# D-3: multiple rules hit -> ordering (priority) determines final output
# =========================
run_D3() {
  log_full ""
  log_full "===================="
  log_full "D-3: multi-hit ordering"
  log_full "===================="

  backup

  # 1) 先生成一次 report，用来挑一个稳定存在且非 generated 的 highlight id（优先 blindspot）
  local mark_line_probe
  mark_line_probe="$(call_refresh "D3_PROBE")"
  echo "✅ report generated (type_code=$(jq -r '.type_code // ""' "$TMP_REPORT" 2>/dev/null))"

  local type_code
  type_code="$(jq -r '.type_code // empty' "$TMP_REPORT" 2>/dev/null)"
  if [[ -z "$type_code" ]]; then
    echo "❌ D3 FAIL: cannot read type_code from report" >&2
    restore
    exit 1
  fi

  # 选择一个 blindspot（稳定存在、通常是 selected:*，避免 generated:*）
  local target_id
  target_id="$(jq -r '.report.highlights[]? | select(.kind=="blindspot") | .id' "$TMP_REPORT" 2>/dev/null | head -n 1)"
  if [[ -z "$target_id" ]]; then
    echo "❌ D3 FAIL: cannot find blindspot highlight id to patch" >&2
    restore
    exit 1
  fi

  # 2) 写入两条冲突规则：同一个 item，同一个字段(title)，不同 priority（70 应赢）
  tmp=/tmp/report_overrides.conflict.$$.json
  jq --arg tid "$target_id" '
    .rules |= (if type=="array" then . else [] end)
    | .rules += [
      {
        "id": "hl_patch_blindspot_conflict_low",
        "target": "highlights",
        "priority": 60,
        "match": { "item": [$tid] },
        "mode": "patch",
        "replace_fields": ["title"],
        "patch": { "title": "（OVR验收）冲突规则-PRIO60" }
      },
      {
        "id": "hl_patch_blindspot_conflict_high",
        "target": "highlights",
        "priority": 70,
        "match": { "item": [$tid] },
        "mode": "patch",
        "replace_fields": ["title"],
        "patch": { "title": "（OVR验收）冲突规则生效-PRIO70" }
      }
    ]
  ' "$OVR_FILE" > "$tmp"
  mv "$tmp" "$OVR_FILE"

  local mark_line
  mark_line="$(call_refresh "D3_CONFLICT")"
  echo "✅ report generated (type_code=$type_code)"

  # 3) 断言：PRIO70 必须赢
  if jq_present --arg tid "$target_id" '.report.highlights[]? | select(.id==$tid) | .title == "（OVR验收）冲突规则生效-PRIO70"' ; then
    echo "✅ D3 OK: higher priority conflict rule wins"
  else
    echo "❌ D3 FAIL: conflict-high rule did not win"
    echo "   got:"
    jq -r --arg tid "$target_id" '.report.highlights[]? | select(.id==$tid) | {id,kind,title,explain,tags}' "$TMP_REPORT" || true
    echo "   note: expected title=（OVR验收）冲突规则生效-PRIO70"
    restore
    exit 1
  fi

  log_snip_C_order "$mark_line"
  restore
}

# =========================
# Main
# =========================
echo "BASE=$BASE"
echo "ATT=$ATT"
echo "OVR_FILE=$OVR_FILE"
echo "TMP_REPORT=$TMP_REPORT"
echo "marklog=$( [[ $has_marklog -eq 1 ]] && echo yes || echo no ) / fromline=$( [[ $has_fromline -eq 1 ]] && echo yes || echo no )"

run_D1
run_D2
run_D3

log_key ""
log_key "✅ ALL DONE: D-1 / D-2 / D-3 passed"

# Always emit machine-readable summary when MODE=json
emit_json "$(jq -cn \
  --arg ok "true" \
  --arg base "$BASE" \
  --arg att "$ATT" \
  --arg ovr_file "$OVR_FILE" \
  --arg tmp_report "$TMP_REPORT" \
  --arg out_dir "${OUT_DIR:-}" \
  '{ok:($ok=="true"), base:$base, attempt_id:$att, ovr_file:$ovr_file, tmp_report:$tmp_report, out_dir:$out_dir, d1:"pass", d2:"pass", d3:"pass"}'
)"

# ✅ Critical: make success exit code deterministic (CI/verify_mbti friendly)
exit 0