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
TMP_REPORT="${TMP_REPORT:-/tmp/report.json}"

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
  backup_path="$OVR_FILE.bak.$(date +%s)"
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

  echo "$mark_line"
}

# jq helpers (exit 0 = pass, exit 1 = fail)
jq_absent() {
  # usage: jq_absent '<filter>'
  local filter="$1"
  if jq -e "$filter" "$TMP_REPORT" >/dev/null 2>&1; then
    return 1
  fi
  return 0
}

jq_present() {
  # usage: jq_present '<filter>'
  local filter="$1"
  jq -e "$filter" "$TMP_REPORT" >/dev/null 2>&1
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
  echo ""
  echo "===================="
  echo "D-1: overrides empty"
  echo "===================="

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
  echo ""
  echo "===================="
  echo "D-2: wrong match/no hit"
  echo "===================="

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
  echo ""
  echo "===================="
  echo "D-3: multi-hit ordering"
  echo "===================="

  backup
  # Add a conflicting rule that patches SAME highlight title with higher priority
  tmp=/tmp/report_overrides.conflict.$$.json
  jq '
    .rules |= (if type=="array" then . else [] end)
    | .rules += [
      {
        "id": "hl_patch_action_01_conflict",
        "target": "highlights",
        "priority": 70,
        "match": { "item": ["hl.action.generated_01"] },
        "mode": "patch",
        "replace_fields": ["title"],
        "patch": { "title": "（OVR验收）冲突规则生效-PRIO70" }
      }
    ]
  ' "$OVR_FILE" > "$tmp"
  mv "$tmp" "$OVR_FILE"

  local mark_line
  mark_line="$(call_refresh "D3_CONFLICT")"
  echo "✅ report generated"

  # Assertion: final title should be the conflict one (assuming higher priority wins / applied later)
  if jq_present '.report.highlights[]? | select(.id=="hl.action.generated_01") | .title == "（OVR验收）冲突规则生效-PRIO70"' ; then
    echo "✅ D3 OK: higher priority conflict rule wins"
  else
    echo "❌ D3 FAIL: conflict rule did not win"
    echo "   got:"
    jq -r '.report.highlights[]? | select(.id=="hl.action.generated_01") | {id,title}' "$TMP_REPORT" || true
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

echo ""
echo "✅ ALL DONE: D-1 / D-2 / D-3 passed"
