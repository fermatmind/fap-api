#!/usr/bin/env bash
set -euo pipefail

BASE_URL="${BASE_URL:-http://127.0.0.1:8000}"
API="$BASE_URL/api/v0.3"

accept_hdr="Accept: application/json"
json_hdr="Content-Type: application/json"

pretty() { python3 -m json.tool; }

get_json() {
  local url="$1"
  curl -fsS -H "$accept_hdr" "$url"
}

post_json() {
  local url="$1"
  local data="$2"
  curl -fsS -H "$accept_hdr" -H "$json_hdr" -X POST "$url" -d "$data"
}

extract_field() {
  # 从 stdin 的 JSON 取顶层字段
  local field="$1"
  python3 -c 'import json,sys; d=json.load(sys.stdin); print(d.get(sys.argv[1], ""))' "$field"
}

echo
echo "0) Health: $API/health"
HEALTH="$(get_json "$API/health")"
echo "$HEALTH" | pretty
echo "$HEALTH" | python3 -c 'import json,sys; d=json.load(sys.stdin); assert d.get("ok") is True, d; print("health ok")'

echo
echo "1) Scale meta: $API/scales/MBTI"
SCALE="$(get_json "$API/scales/MBTI")"
echo "$SCALE" | pretty

echo
echo "2) Questions: $API/scales/MBTI/questions"
QUESTIONS="$(get_json "$API/scales/MBTI/questions")"
echo "$QUESTIONS" | python3 -c 'import json,sys; d=json.load(sys.stdin); items=d.get("items") or []; print("question_items_count=", len(items))'
echo "$QUESTIONS" | python3 -c 'import json,sys; d=json.load(sys.stdin); items=d.get("items") or []; print("first_question=", (items[0] if items else None))'

echo
echo "3) POST attempt: $API/attempts"
PAYLOAD='{
  "anon_id":"wxapp:test-anon-001",
  "scale_code":"MBTI",
  "scale_version":"v0.2",
  "client_platform":"wechat-miniprogram",
  "client_version":"0.2.1",
  "channel":"dev",
  "answers":[{"question_id":"MBTI-001","code":"A"}]
}'
ATTEMPT_RESP="$(post_json "$API/attempts" "$PAYLOAD")"
echo "$ATTEMPT_RESP" | pretty

ATTEMPT_ID="$(echo "$ATTEMPT_RESP" | extract_field attempt_id)"
if [[ -z "$ATTEMPT_ID" ]]; then
  echo "ERROR: attempt_id empty. Raw response:" >&2
  echo "$ATTEMPT_RESP" >&2
  exit 1
fi
echo "ATTEMPT_ID=$ATTEMPT_ID"

echo
echo "4) GET result: $API/attempts/$ATTEMPT_ID/result"
RESULT="$(get_json "$API/attempts/$ATTEMPT_ID/result")"
echo "$RESULT" | pretty

echo
echo "5) GET share: $API/attempts/$ATTEMPT_ID/share"
SHARE="$(get_json "$API/attempts/$ATTEMPT_ID/share")"
echo "$SHARE" | pretty

echo
echo "DONE ✅"
