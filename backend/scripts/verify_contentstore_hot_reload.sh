#!/usr/bin/env bash
set -euo pipefail

# 用法：
#   export ID="attempt-uuid"
#   bash scripts/verify_contentstore_hot_reload.sh
#
# 可选：
#   FILE=../content_packages/.../report_cards_traits.json
#   CARD_ID=traits_axis_SN_N_strong

: "${ID:?missing env ID, e.g. export ID=...}"

API="http://127.0.0.1:8000/api/v0.2/attempts/${ID}/report"

# 默认文件/卡片（你现在验证用的那套）
FILE="${FILE:-../content_packages/MBTI/GLOBAL/en/v0.2.1-TEST/report_cards_traits.json}"
CARD_ID="${CARD_ID:-traits_axis_SN_N_strong}"
CANARY_PREFIX="${CANARY_PREFIX:-@@CANARY@@}"

if [[ ! -f "$FILE" ]]; then
  echo "[ERR] FILE not found: $FILE" >&2
  exit 1
fi

TMP_BAK="${FILE}.bak.verify.$(date +%s)"

cleanup() {
  if [[ -f "$TMP_BAK" ]]; then
    mv -f "$TMP_BAK" "$FILE"
    echo "[OK] restored $FILE"
  fi
}
trap cleanup EXIT

echo "[INFO] api=$API"
echo "[INFO] file=$FILE"
echo "[INFO] card_id=$CARD_ID"

# 1) 取 base title
BASE_TITLE="$(curl -sS "$API" | jq -r --arg id "$CARD_ID" '.report.sections.traits.cards[] | select(.id==$id) | .title' | head -n 1 || true)"
if [[ -z "${BASE_TITLE}" || "${BASE_TITLE}" == "null" ]]; then
  echo "[ERR] cannot find card title for id=$CARD_ID (maybe not selected in TopN?)" >&2
  echo "[HINT] Try set CARD_ID to the actual selected one:" >&2
  echo "       curl -sS \"$API\" | jq -r '.report.sections.traits.cards[].id'" >&2
  exit 1
fi
echo "[BASE] $BASE_TITLE"

# 2) 备份原文件
cp "$FILE" "$TMP_BAK"

# 3) 写入 canary title
jq --arg id "$CARD_ID" --arg canary "${CANARY_PREFIX} ${BASE_TITLE}" '
  .items |= map(
    if .id==$id then .title=$canary else . end
  )
' "$TMP_BAK" > "$FILE"

# 4) 再请求，必须命中 canary
NEW_TITLE="$(curl -sS "$API" | jq -r --arg id "$CARD_ID" '.report.sections.traits.cards[] | select(.id==$id) | .title' | head -n 1 || true)"
echo "[NEW]  $NEW_TITLE"

if [[ "$NEW_TITLE" != "${CANARY_PREFIX} ${BASE_TITLE}" ]]; then
  echo "[FAIL] hot reload not effective (expected canary title)" >&2
  exit 2
fi

echo "[PASS] content hot reload works without PHP changes"
