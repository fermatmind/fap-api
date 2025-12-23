#!/usr/bin/env bash
set -euo pipefail

API="${API:-http://127.0.0.1:8000}"
SCALE_CODE="${SCALE_CODE:-MBTI}"
SCALE_VERSION="${SCALE_VERSION:-v0.2}"
ANSWER_CODE="${ANSWER_CODE:-C}"
REGION="${REGION:-CN_MAINLAND}"
LOCALE="${LOCALE:-zh-CN}"
EXPECT_PACK_PREFIX="${EXPECT_PACK_PREFIX:-MBTI.cn-mainland.zh-CN.}"

# Prefer RUN_DIR; fallback to WORKDIR; default generate a unique directory
RUN_DIR="${RUN_DIR:-}"
WORKDIR="${WORKDIR:-}"
if [[ -z "$RUN_DIR" ]]; then
  if [[ -n "$WORKDIR" ]]; then
    RUN_DIR="$WORKDIR"
  else
    RUN_DIR="/tmp/mbti_verify_$(date +%s)"
  fi
fi
mkdir -p "$RUN_DIR"

HEALTH_JSON="$RUN_DIR/health.json"
QUESTIONS_JSON="$RUN_DIR/questions.json"
PAYLOAD_JSON="$RUN_DIR/payload.json"
ATTEMPT_RESP_JSON="$RUN_DIR/attempt.json"
REPORT_JSON="$RUN_DIR/report.json"
SHARE_JSON="$RUN_DIR/share.json"
ATTEMPT_ID_TXT="$RUN_DIR/attempt_id.txt"

SCRIPT_DIR="$(cd "$(dirname "${BASH_SOURCE[0]}")" && pwd)"

echo "[ARTIFACTS] $RUN_DIR"

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
  fi

  # 防止 EXIT trap 里再次 exit 触发二次 trap
  trap - EXIT
  exit $exit_code
}
trap cleanup_on_exit EXIT

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

echo "[1/6] health: $API"
fetch_json "$API/api/v0.2/health" "$HEALTH_JSON"
python3 - <<PY
import json
j=json.load(open("$HEALTH_JSON","r",encoding="utf-8"))
assert j.get("ok") is True, j
print("[OK] health:", j.get("service"), j.get("version"), j.get("time"))
PY

echo "[2/6] fetch questions"
fetch_json "$API/api/v0.2/scales/$SCALE_CODE/questions" "$QUESTIONS_JSON"
python3 - <<PY
import json
j=json.load(open("$QUESTIONS_JSON","r",encoding="utf-8"))
assert j.get("ok") is True, j
cnt=len(j.get("items",[]))
assert cnt>0, "no items"
print("[OK] questions count=", cnt)
PY

echo "[3/6] build payload ($ANSWER_CODE for all)"
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
  echo "[4/6] reuse attempt: $ATTEMPT_ID"
else
  echo "[4/6] create attempt"
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

echo "[5/6] fetch report & share"
fetch_json "$API/api/v0.2/attempts/$ATTEMPT_ID/report" "$REPORT_JSON"
fetch_json "$API/api/v0.2/attempts/$ATTEMPT_ID/share"  "$SHARE_JSON"
echo "[OK] report=$REPORT_JSON"
echo "[OK] share=$SHARE_JSON"

echo "[6/6] assert"
python3 "$SCRIPT_DIR/assert_report.py" \
  --report "$REPORT_JSON" \
  --share "$SHARE_JSON" \
  --expect-pack-prefix "$EXPECT_PACK_PREFIX" \
  --expect-locale "$LOCALE"

echo "[DONE] verify_mbti OK ✅"
echo "[ARTIFACTS] $RUN_DIR"