#!/usr/bin/env bash
set -euo pipefail

# -----------------------------
# Config / Defaults
# -----------------------------
API="${API:-http://127.0.0.1:8000}"
SCALE_CODE="${SCALE_CODE:-MBTI}"
SCALE_VERSION="${SCALE_VERSION:-v0.2}"
ANSWER_CODE="${ANSWER_CODE:-C}"
REGION="${REGION:-CN_MAINLAND}"
LOCALE="${LOCALE:-zh-CN}"

# 你原本用 pack prefix 断言；保留
EXPECT_PACK_PREFIX="${EXPECT_PACK_PREFIX:-MBTI.cn-mainland.zh-CN.}"

# 3连验收相关参数
VERIFY_MODE="${VERIFY_MODE:-local}"                         # local|server|ci（此脚本是 HTTP E2E，ci 也可以用，只要 CI 起了服务）
STRICT="${STRICT:-1}"                         # 1=禁止出现 deprecated/GLOBAL/en 等信号
MIN_HL="${MIN_HL:-3}"                         # highlights 数量下限
MAX_HL="${MAX_HL:-4}"                         # highlights 数量上限

# Prefer RUN_DIR; fallback to WORKDIR; default to backend/artifacts/verify_mbti
RUN_DIR="${RUN_DIR:-}"
WORKDIR="${WORKDIR:-}"
SCRIPT_DIR="$(cd "$(dirname "${BASH_SOURCE[0]}")" && pwd)"
BACKEND_DIR="$(cd "$SCRIPT_DIR/.." && pwd)"

if [[ -z "$RUN_DIR" ]]; then
  if [[ -n "$WORKDIR" ]]; then
    RUN_DIR="$WORKDIR"
  else
    RUN_DIR="$BACKEND_DIR/artifacts/verify_mbti"
  fi
fi

LOG_DIR="$RUN_DIR/logs"
mkdir -p "$LOG_DIR"

HEALTH_JSON="$RUN_DIR/health.json"
QUESTIONS_JSON="$RUN_DIR/questions.json"
PAYLOAD_JSON="$RUN_DIR/payload.json"
ATTEMPT_RESP_JSON="$RUN_DIR/attempt.json"
REPORT_JSON="$RUN_DIR/report.json"
SHARE_JSON="$RUN_DIR/share.json"
ATTEMPT_ID_TXT="$RUN_DIR/attempt_id.txt"
SUMMARY_TXT="$RUN_DIR/summary.txt"

OVR_LOG="$LOG_DIR/overrides_accept_D.log"

echo "[ARTIFACTS] $RUN_DIR"

# -----------------------------
# Preconditions
# -----------------------------
need_cmd() {
  local c="$1"
  command -v "$c" >/dev/null 2>&1 || { echo "[FAIL] missing command: $c" >&2; exit 2; }
}
need_cmd curl
need_cmd python3
need_cmd jq

# -----------------------------
# Exit trap
# -----------------------------
cleanup_on_exit() {
  local exit_code=$?

  if [[ $exit_code -ne 0 ]]; then
    echo "[FAIL] verify_mbti exited with code=$exit_code" >&2
    echo "[FAIL] artifacts kept at: $RUN_DIR" >&2

    if [[ -f "$ATTEMPT_RESP_JSON" ]]; then
      echo "---- attempt response (first 800 bytes) ----" >&2
      head -c 800 "$ATTEMPT_RESP_JSON" 2>/dev/null || true
      echo >&2
    fi
    if [[ -f "$REPORT_JSON" ]]; then
      echo "---- report (first 400 bytes) ----" >&2
      head -c 400 "$REPORT_JSON" 2>/dev/null || true
      echo >&2
    fi
    if [[ -f "$SHARE_JSON" ]]; then
      echo "---- share (first 400 bytes) ----" >&2
      head -c 400 "$SHARE_JSON" 2>/dev/null || true
      echo >&2
    fi
    if [[ -f "$OVR_LOG" ]]; then
      echo "---- overrides log (tail 120 lines) ----" >&2
      tail -n 120 "$OVR_LOG" 2>/dev/null || true
      echo >&2
    fi
  fi

  trap - EXIT
  exit $exit_code
}
trap cleanup_on_exit EXIT

# -----------------------------
# Helpers
# -----------------------------
fetch_json() {
  local url="$1"
  local out="$2"

  local http
  http="$(curl -sS -L -o "$out" -w "%{http_code}" "$url" || true)"

  if [[ -z "${http:-}" ]]; then
    echo "[CURL][FAIL] no http code (curl error). url=$url" >&2
    return 2
  fi

  if [[ "$http" != "200" && "$http" != "201" ]]; then
    echo "[CURL][FAIL] HTTP=$http url=$url" >&2
    echo "---- body (first 800 bytes) ----" >&2
    head -c 800 "$out" 2>/dev/null || true
    echo >&2
    return 3
  fi

  if [[ ! -s "$out" ]]; then
    echo "[CURL][FAIL] empty body. url=$url" >&2
    return 4
  fi

  return 0
}

fail() { echo "[FAIL] $*" >&2; exit 1; }

assert_file_not_contains() {
  local file="$1" needle="$2" ctx="${3:-}"
  [[ -f "$file" ]] || return 0
  if grep -Fq -- "$needle" "$file"; then
    fail "${ctx:+$ctx: }forbidden string found: $needle (file=$file)"
  fi
}

assert_file_contains() {
  local file="$1" needle="$2" ctx="${3:-}"
  [[ -f "$file" ]] || fail "${ctx:+$ctx: }file not found: $file"
  grep -Fq -- "$needle" "$file" || fail "${ctx:+$ctx: }expected string missing: $needle (file=$file)"
}

strict_negative_signals() {
  local file="$1" ctx="$2"
  [[ "${STRICT}" == "1" ]] || return 0

  # 你要防的两类：deprecated链路 & GLOBAL/en
  assert_file_not_contains "$file" "content_packages/_deprecated" "$ctx"
  assert_file_not_contains "$file" "GLOBAL/en" "$ctx"
  assert_file_not_contains "$file" "fallback to GLOBAL" "$ctx"
}

# jq: find highlights array path (兼容不同 report schema)
pick_highlights_path() {
  local f="$1"
  local candidates=(
    '.highlights.items'
    '.highlights'
    '.report.highlights.items'
    '.report.highlights'
    '.data.highlights.items'
    '.data.highlights'
  )
  local p
  for p in "${candidates[@]}"; do
    if jq -e "$p | . != null" "$f" >/dev/null 2>&1; then
      # ensure it's array
      if jq -e "$p | type == \"array\"" "$f" >/dev/null 2>&1; then
        echo "$p"
        return 0
      fi
    fi
  done
  return 1
}

# -----------------------------
# Phase 0: health & questions & payload & attempt & report
# -----------------------------
echo "[1/8] health: $API"
fetch_json "$API/api/v0.2/health" "$HEALTH_JSON"
python3 - <<PY
import json
j=json.load(open("$HEALTH_JSON","r",encoding="utf-8"))
assert j.get("ok") is True, j
print("[OK] health:", j.get("service"), j.get("version"), j.get("time"))
PY

echo "[2/8] fetch questions"
fetch_json "$API/api/v0.2/scales/$SCALE_CODE/questions" "$QUESTIONS_JSON"
python3 - <<PY
import json
j=json.load(open("$QUESTIONS_JSON","r",encoding="utf-8"))
assert j.get("ok") is True, j
cnt=len(j.get("items",[]))
assert cnt>0, "no items"
print("[OK] questions count=", cnt)
PY

echo "[3/8] build payload ($ANSWER_CODE for all)"
python3 - <<PY
import json,uuid
j=json.load(open("$QUESTIONS_JSON","r",encoding="utf-8"))
items=j.get("items",[])
answers=[{"question_id":q["question_id"],"code":"$ANSWER_CODE"} for q in items]
payload={
  "anon_id":"local_verify_"+uuid.uuid4().hex[:8],
  "scale_code":"$SCALE_CODE",
  "scale_version":"$SCALE_VERSION",
  "answers":answers,
  "client_platform":"cli",
  "client_version":"verify-1",
  "channel":"direct",
  "referrer":"cli",
  "region":"$REGION",
  "locale":"$LOCALE"
}
open("$PAYLOAD_JSON","w",encoding="utf-8").write(json.dumps(payload,ensure_ascii=False))
print("[OK] payload written:", "$PAYLOAD_JSON", "answers=", len(answers))
PY

# 4) create attempt OR reuse attempt
if [[ -n "${ATTEMPT_ID:-}" ]]; then
  echo "[4/8] reuse attempt: $ATTEMPT_ID"
else
  echo "[4/8] create attempt"
  http="$(curl -sS -L -o "$ATTEMPT_RESP_JSON" -w "%{http_code}" \
    -X POST "$API/api/v0.2/attempts" \
    -H 'Content-Type: application/json' \
    -d @"$PAYLOAD_JSON" || true)"

  if [[ "$http" != "200" && "$http" != "201" ]]; then
    echo "[CURL][FAIL] create attempt HTTP=$http" >&2
    echo "---- body (first 800 bytes) ----" >&2
    head -c 800 "$ATTEMPT_RESP_JSON" 2>/dev/null || true
    echo >&2
    exit 5
  fi

  ATTEMPT_ID="$(python3 - <<PY
import json
j=json.load(open("$ATTEMPT_RESP_JSON","r",encoding="utf-8"))
assert j.get("ok") is True, j
aid=j.get("attempt_id")
assert aid, "attempt_id missing"
print(aid)
PY
)"
fi

echo "$ATTEMPT_ID" > "$ATTEMPT_ID_TXT"
echo "[OK] attempt_id=$ATTEMPT_ID"

echo "[5/8] fetch report & share"
fetch_json "$API/api/v0.2/attempts/$ATTEMPT_ID/report" "$REPORT_JSON"
fetch_json "$API/api/v0.2/attempts/$ATTEMPT_ID/share"  "$SHARE_JSON"
echo "[OK] report=$REPORT_JSON"
echo "[OK] share=$SHARE_JSON"

# -----------------------------
# Phase A: Content verification
# -----------------------------
echo "[6/8] content verify (no fallback)"
# 1) 你原有的 pack prefix / locale 断言（继续用你已存在的 assert_report.py）
python3 "$SCRIPT_DIR/assert_report.py" \
  --report "$REPORT_JSON" \
  --share "$SHARE_JSON" \
  --expect-pack-prefix "$EXPECT_PACK_PREFIX" \
  --expect-locale "$LOCALE"

# -------------------------
# REGION positive assertion
# -------------------------
# Expect REGION like: CN_MAINLAND  -> report dir uses: CN-MAINLAND
_expect_region="${REGION:-CN_MAINLAND}"
_expect_region_dir="${_expect_region//_/-}"                 # CN_MAINLAND -> CN-MAINLAND
_expect_region_slug="$(echo "$_expect_region" | tr '[:upper:]' '[:lower:]' | tr '_' '-')"  # cn-mainland

_actual_dir="$(jq -r '.report.versions.content_package_dir // ""' "$REPORT_JSON")"
_actual_pack_id="$(jq -r '.report.versions.content_pack_id // ""' "$REPORT_JSON")"

if [[ -z "$_actual_dir" ]]; then
  echo "[ASSERT][FAIL] report.versions.content_package_dir missing"
  exit 2
fi

# dir must include CN-MAINLAND (or the expected region dir)
if [[ "$_actual_dir" != *"/${_expect_region_dir}/"* && "$_actual_dir" != *"${_expect_region_dir}/"* ]]; then
  echo "[ASSERT][FAIL] content_package_dir unexpected: ${_actual_dir} (expect contains ${_expect_region_dir})"
  exit 2
fi

# pack_id must include cn-mainland
if [[ -z "$_actual_pack_id" ]]; then
  echo "[ASSERT][FAIL] report.versions.content_pack_id missing"
  exit 2
fi

if [[ "$_actual_pack_id" != *".${_expect_region_slug}."* && "$_actual_pack_id" != *"${_expect_region_slug}"* ]]; then
  echo "[ASSERT][FAIL] content_pack_id unexpected: ${_actual_pack_id} (expect contains ${_expect_region_slug})"
  exit 2
fi

# 2) STRICT 负断言：禁止 deprecated/GLOBAL/en 信号出现在 report/share（足够抓到很多 silent fallback）
strict_negative_signals "$REPORT_JSON" "Content(report)"
strict_negative_signals "$SHARE_JSON" "Content(share)"

echo "[OK] content verification passed"

# -----------------------------
# Phase B: Rules verification (highlights contract)
# -----------------------------
echo "[7/8] rules verify (highlights contract)"
HL_PATH="$(pick_highlights_path "$REPORT_JSON" || true)"
[[ -n "${HL_PATH:-}" ]] || fail "Rules: cannot find highlights array path in report.json"

# items 数量在 [MIN_HL, MAX_HL]
HL_N="$(jq -r "$HL_PATH | length" "$REPORT_JSON")"
[[ "$HL_N" =~ ^[0-9]+$ ]] || fail "Rules: invalid highlights length"
if (( HL_N < MIN_HL || HL_N > MAX_HL )); then
  fail "Rules: highlights count out of range: got=$HL_N expect=[$MIN_HL,$MAX_HL] path=$HL_PATH"
fi

# kinds 必须包含 blindspot & action
jq -e "$HL_PATH | map(.kind) | index(\"blindspot\") != null" "$REPORT_JSON" >/dev/null \
  || fail "Rules: highlights.kind must include 'blindspot'"
jq -e "$HL_PATH | map(.kind) | index(\"action\") != null" "$REPORT_JSON" >/dev/null \
  || fail "Rules: highlights.kind must include 'action'"

# 防双前缀：任何 id 不得包含 hl.blindspot.hl.
jq -e "$HL_PATH | map(.id // \"\") | any(contains(\"hl.blindspot.hl.\")) | not" "$REPORT_JSON" >/dev/null \
  || fail "Rules: found forbidden id prefix 'hl.blindspot.hl.'"

# 禁止 borderline（id 或 tags 中出现 borderline 都算）
jq -e "$HL_PATH
  | map((.id // \"\") + \" \" + ((.tags // []) | join(\",\")))
  | any(test(\"borderline\"; \"i\")) | not" "$REPORT_JSON" >/dev/null \
  || fail "Rules: found forbidden 'borderline' in highlight id/tags"

echo "[OK] rules verification passed (path=$HL_PATH count=$HL_N)"

# -----------------------------
# Phase C: Overrides verification (call accept_overrides_D.sh)
# -----------------------------
echo "[8/8] overrides verify (accept_overrides_D.sh)"
ACCEPT_OVR="$SCRIPT_DIR/accept_overrides_D.sh"

if [[ -f "$ACCEPT_OVR" ]]; then
  # ✅ 严格模式：accept_overrides_D.sh 非 0 直接 FAIL
  # 同时把 stdout/stderr 全部落盘到 artifacts，方便回溯
  BASE="$API" bash "$ACCEPT_OVR" "$ATTEMPT_ID" >"$OVR_LOG" 2>&1 || {
    echo "---- overrides log (tail 200 lines) ----" >&2
    tail -n 200 "$OVR_LOG" >&2 || true
    fail "Overrides: accept_overrides_D.sh failed (see $OVR_LOG)"
  }

  # ✅ 额外硬断言：必须包含 ALL DONE（防止脚本误 exit 0 但没跑完）
  assert_file_contains "$OVR_LOG" "✅ ALL DONE: D-1 / D-2 / D-3 passed" "Overrides"

  # ✅ STRICT 负断言：禁止 deprecated/GLOBAL/en 信号
  strict_negative_signals "$OVR_LOG" "Overrides(log)"

  echo "[OK] overrides verification passed (log=$OVR_LOG)"
else
  echo "[SKIP] accept_overrides_D.sh not found; overrides phase skipped"
fi

# -----------------------------
# Summary
# -----------------------------
cat >"$SUMMARY_TXT" <<EOF
verify_mbti summary
  VERIFY_MODE=$VERIFY_MODE
    API=$API
  REGION=$REGION
  LOCALE=$LOCALE
  EXPECT_PACK_PREFIX=$EXPECT_PACK_PREFIX
  STRICT=$STRICT
  highlights_path=$HL_PATH
  highlights_count=$HL_N
  artifacts_dir=$RUN_DIR
  files:
    report=$REPORT_JSON
    share=$SHARE_JSON
    overrides_log=${OVR_LOG}
EOF

echo "[DONE] verify_mbti OK ✅"
echo "[SUMMARY] $SUMMARY_TXT"
echo "[ARTIFACTS] $RUN_DIR"