#!/usr/bin/env bash
set -euo pipefail

: "${ID:?Please export ID=<attempt_uuid> first}"

API="${API:-http://127.0.0.1:8000/api/v0.3/attempts/$ID/report}"
FILE="${FILE:-../content_packages/default/CN_MAINLAND/zh-CN/MBTI-CN-v0.3/report_overrides.json}"
CANARY_PREFIX="${CANARY_PREFIX:-@@CANARY_OV@@}"

echo "[INFO] api=$API"
echo "[INFO] file=$FILE"
[[ -f "$FILE" ]] || { echo "[FAIL] file not found: $FILE" >&2; exit 1; }

# 1) 取一个稳定的目标：report.highlights[0]
HL_ID="$(curl -sS "$API" | jq -r '.report.highlights[0].id // empty' | head -n 1)"
HL_TITLE="$(curl -sS "$API" | jq -r '.report.highlights[0].title // empty' | head -n 1)"
HL_TEXT="$(curl -sS "$API" | jq -r '.report.highlights[0].text // empty' | head -n 1)"

if [[ -z "${HL_ID:-}" ]]; then
  echo "[FAIL] cannot read baseline highlight from report (report.highlights[0].id is empty)" >&2
  echo "[HINT] print report.highlights[0]:" >&2
  curl -sS "$API" | jq '.report.highlights[0] // {}' >&2 || true
  exit 2
fi

CANARY_STR="${CANARY_PREFIX} $(date +%s)"
echo "[INFO] hl_id=$HL_ID"
echo "[BASE] title=${HL_TITLE:-<empty>}"
echo "[BASE] text=${HL_TEXT:-<empty>}"
echo "[CANARY] $CANARY_STR"

# 2) 备份
TMP_BAK="$(mktemp -t report_overrides.json.bak.XXXXXX)"
cp "$FILE" "$TMP_BAK"
cleanup() {
  cp "$TMP_BAK" "$FILE" || true
  rm -f "$TMP_BAK" || true
  echo "[OK] restored $FILE"
}
trap cleanup EXIT

# 3) 追加 canary overrides（多种 target/patch 形状，命中任意一种就算通过）
jq --arg hl_id "$HL_ID" --arg canary "$CANARY_STR" '
  def base_meta($suffix):
    {
      id: ("canary.override.hl." + $suffix),
      priority: 999999,
      tags: ["canary:override"],
      rules: { min_match: 0, require_any: [], require_all: [], forbid: [] }
    };

  def r1: base_meta("v1_target_kind_highlight_patch_title_text") + {
    target: { kind: "highlight", id: $hl_id },
    patch:  { title: $canary, text: $canary, text_tpl: $canary }
  };

  def r2: base_meta("v2_target_kind_hl_patch_title_text") + {
    target: { kind: "hl", id: $hl_id },
    patch:  { title: $canary, text: $canary, text_tpl: $canary }
  };

  def r3: base_meta("v3_target_type_highlight_set") + {
    target: { type: "highlight", id: $hl_id },
    set:    { title: $canary, text: $canary, text_tpl: $canary }
  };

  def r4: base_meta("v4_where_kind_highlight_patch") + {
    where:  { kind: "highlight", id: $hl_id },
    patch:  { title: $canary, text: $canary, text_tpl: $canary }
  };

  def r5: base_meta("v5_target_section_highlights_patch") + {
    target: { kind: "highlight", section: "highlights", id: $hl_id },
    patch:  { title: $canary, text: $canary, text_tpl: $canary }
  };

  def canary_rules: [r1, r2, r3, r4, r5];

  if (.rules? | type) == "array" then
    .rules = (.rules + canary_rules)
  elif (.overrides? | type) == "array" then
    .overrides = (.overrides + canary_rules)
  else
    .rules = canary_rules
  end
' "$TMP_BAK" > "$FILE"

# 4) 再请求：只要 report.highlights 里出现 canary，就 PASS
NEW_HIT="$(curl -sS "$API" \
  | jq -r --arg canary "$CANARY_STR" '.report.highlights[]? | .. | strings | select(. == $canary)' \
  | head -n 1 || true)"

echo "[NEW]  $NEW_HIT"

if [[ "$NEW_HIT" != "$CANARY_STR" ]]; then
  echo "[FAIL] overrides hot reload not effective (canary not observed in report.highlights)" >&2
  echo "[HINT] print report.highlights[0] after patch:" >&2
  curl -sS "$API" | jq '.report.highlights[0] // {}' >&2 || true
  echo "[HINT] overrides top-level keys:" >&2
  jq -r 'keys' "$FILE" >&2 || true
  echo "[HINT] overrides rules length:" >&2
  jq -r '((.rules? // .overrides? // []) | length)' "$FILE" >&2 || true
  exit 3
fi

echo "[PASS] overrides hot reload works without PHP changes"
