#!/usr/bin/env bash
set -euo pipefail

: "${ID:?Please export ID=<attempt_uuid> first}"

API="${API:-http://127.0.0.1:8000/api/v0.3/attempts/$ID/report}"
FILE="${FILE:-../content_packages/default/CN_MAINLAND/zh-CN/MBTI-CN-v0.2.1-TEST/report_highlights_templates.json}"
CANARY_PREFIX="${CANARY_PREFIX:-@@CANARY_HL@@}"

echo "[INFO] api=$API"
echo "[INFO] file=$FILE"
[[ -f "$FILE" ]] || { echo "[FAIL] file not found: $FILE" >&2; exit 1; }

# 备份到 /tmp，避免路径/权限/覆盖失败
TMP_BAK="$(mktemp -t report_highlights_templates.json.bak.XXXXXX)"
cp "$FILE" "$TMP_BAK"

cleanup() {
  cp "$TMP_BAK" "$FILE" 2>/dev/null || true
  rm -f "$TMP_BAK" || true
  echo "[OK] restored $FILE"
}
trap cleanup EXIT

# 1) baseline：确认 report 有 highlights
BASE_COUNT="$(curl -sS "$API" | jq -r '.report.highlights | length' 2>/dev/null || echo 0)"
if [[ "$BASE_COUNT" == "0" ]]; then
  echo "[FAIL] report.highlights is empty, cannot verify" >&2
  exit 2
fi
echo "[INFO] base_highlights_count=$BASE_COUNT"

# 2) 选一个“可观测且很可能来自 templates”的字符串：优先 tips[0]，再退化到 text
BASE_STR="$(curl -sS "$API" | jq -r '
  .report.highlights[0] as $h
  | (
      ($h.tips[0]? // empty),
      ($h.text? // empty)
    ) | select(type=="string" and length>0) | . 
' | head -n 1)"

if [[ -z "${BASE_STR:-}" ]]; then
  echo "[FAIL] cannot extract baseline highlight tips[0]/text from report" >&2
  echo "[HINT] sample highlight:" >&2
  curl -sS "$API" | jq '.report.highlights[0] // {}' >&2 || true
  exit 3
fi

echo "[BASE] $BASE_STR"

# 3) 确认 templates 文件里确实存在 BASE_STR（否则无法证明“文件->输出”链路）
if ! rg -n --fixed-strings "$BASE_STR" "$FILE" >/dev/null 2>&1; then
  echo "[FAIL] cannot find BASE string in templates file; highlights may not be driven by this templates doc." >&2
  echo "[HINT] BASE_STR was: $BASE_STR" >&2
  echo "[HINT] try searching templates file manually:" >&2
  echo "       rg -n --fixed-strings \"\$BASE_STR\" \"$FILE\"" >&2
  exit 4
fi

CANARY_STR="${CANARY_PREFIX} $(date +%s)"
echo "[CANARY] $CANARY_STR"

# 4) patch：把 templates 文件里 “第一次出现的 BASE_STR 字符串” 替换为 CANARY_STR
#    （只改一次，避免影响太大；但也足够验证动态生效）
jq --arg base "$BASE_STR" --arg canary "$CANARY_STR" '
  def patch_once:
    if (type=="string") and (. == $base) then $canary else . end;

  def walk_once:
    if type=="object" then
      reduce keys[] as $k ({}; . + { ($k): ($in[$k] | walk_once) })
    elif type=="array" then
      map(walk_once)
    else
      patch_once
    end;

  # 只替换“第一次命中”：用 reduce + 状态实现一次性替换
  def replace_first($x; $base; $canary):
    reduce paths(scalars) as $p
      ({doc:$x, done:false};
       if .done then .
       else
         (getpath($p) as $v
          | if ($v|type)=="string" and $v==$base then
              .doc |= setpath($p; $canary) | .done=true
            else .
            end)
       end
      ).doc;

  replace_first(.; $base; $canary)
' "$TMP_BAK" > "$FILE"

# 5) 再请求：只要 report.highlights 任意字段出现 CANARY_STR 就 PASS
NEW_HIT="$(curl -sS "$API" \
  | jq -r --arg canary "$CANARY_STR" '.report.highlights[]? | .. | strings | select(. == $canary)' \
  | head -n 1 || true)"

echo "[NEW]  $NEW_HIT"

if [[ "$NEW_HIT" != "$CANARY_STR" ]]; then
  echo "[FAIL] highlights hot reload not effective (canary not observed in report.highlights)" >&2
  echo "[HINT] highlight sample after patch:" >&2
  curl -sS "$API" | jq '.report.highlights[0] // {}' >&2 || true
  exit 5
fi

echo "[PASS] highlights hot reload works without PHP changes"
