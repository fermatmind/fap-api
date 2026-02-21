#!/usr/bin/env bash
set -euo pipefail

: "${ID:?Please export ID=<attempt_uuid> first}"

API="${API:-http://127.0.0.1:8000/api/v0.3/attempts/$ID/report}"
FILE="${FILE:-../content_packages/default/CN_MAINLAND/zh-CN/MBTI-CN-v0.3/report_recommended_reads.json}"
CANARY_PREFIX="${CANARY_PREFIX:-@@CANARY_READS@@}"

echo "[INFO] api=$API"
echo "[INFO] file=$FILE"
[[ -f "$FILE" ]] || { echo "[FAIL] file not found: $FILE" >&2; exit 1; }

# --- temp files (mac-safe) ---
TMP_BAK="$(mktemp -t report_recommended_reads.json.bak.XXXXXX)"
TMP_STRINGS="$(mktemp -t report_report_strings.txt.XXXXXX)"
cp "$FILE" "$TMP_BAK"

cleanup() {
  cp "$TMP_BAK" "$FILE" || true
  rm -f "$TMP_BAK" "$TMP_STRINGS" || true
  echo "[OK] restored $FILE"
}
trap cleanup EXIT

# 1) 拉一次 report，并把所有 strings 落盘（用于匹配“实际被用到”的 title）
curl -sS "$API" | jq -r '.. | strings' > "$TMP_STRINGS" || true

# 2) 从 reads doc 提取全部 title（去重、去空），找第一个“在 report strings 里出现过”的
BASE_TITLE="$(
  jq -r '
    [
      .. | objects | .title? // empty
    ]
    | map(select(type=="string" and length>0))
    | unique
    | .[]
  ' "$FILE" | while IFS= read -r t; do
      if grep -Fqx "$t" "$TMP_STRINGS"; then
        echo "$t"
        break
      fi
    done
)"

# 3) 兜底：如果没找到“被 report 用到”的 title，则用 fallback[0].title（可能会失败，但会给 hint）
if [[ -z "${BASE_TITLE:-}" ]]; then
  BASE_TITLE="$(jq -r '(.items.fallback // [])[0].title // empty' "$FILE" | head -n 1 || true)"
  if [[ -z "${BASE_TITLE:-}" ]]; then
    echo "[FAIL] cannot find any usable title in reads doc: $FILE" >&2
    exit 2
  fi
  echo "[WARN] cannot find a reads title that appears in report strings; falling back to items.fallback[0].title"
fi

CANARY_TITLE="${CANARY_PREFIX} ${BASE_TITLE}"
echo "[BASE] $BASE_TITLE"
echo "[CANARY] $CANARY_TITLE"

# 4) 把 reads doc 里所有 title==BASE_TITLE 的地方替换为 canary（最稳）
jq --arg base "$BASE_TITLE" --arg canary "$CANARY_TITLE" '
  def walk:
    if type=="object" then
      with_entries(.value |= walk)
      | (if has("title") and (.title|type)=="string" and .title==$base then .title=$canary else . end)
    elif type=="array" then
      map(walk)
    elif type=="string" then
      if .==$base then $canary else . end
    else
      .
    end;
  walk
' "$TMP_BAK" > "$FILE"

# 5) 再请求：report 任意字符串出现 canary 就 PASS
NEW_HIT="$(
  curl -sS "$API" \
  | jq -r --arg canary "$CANARY_TITLE" '.. | strings | select(. == $canary)' \
  | head -n 1 || true
)"

echo "[NEW]  $NEW_HIT"

if [[ "$NEW_HIT" != "$CANARY_TITLE" ]]; then
  echo "[FAIL] reads hot reload not effective (cannot observe canary in report JSON strings)" >&2
  echo "[HINT] you may be editing a title that is not used by this attempt." >&2
  echo "[HINT] try printing reads-ish objects from report to confirm where reads land:" >&2
  echo "       curl -sS \"$API\" | jq '.. | objects | select(has(\"url\") or has(\"link\") or has(\"reads\") or has(\"recommended\"))' | head" >&2
  exit 3
fi

echo "[PASS] reads hot reload works without PHP changes"
