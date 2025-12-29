#!/usr/bin/env bash
set -euo pipefail

BASE_URL="${BASE_URL:-http://127.0.0.1:8000}"
ID="${1:-}"

if [[ -z "$ID" ]]; then
  echo "Usage: $0 <ATTEMPT_ID>"
  echo "Example: $0 f8a01c04-6bb9-4811-b3ff-1371cd2477c6"
  exit 1
fi

api() { curl -s "$BASE_URL/api/v0.2/attempts/$ID/report"; }

set_attempt() {
  local region="$1"
  local locale="$2"
  php artisan tinker --execute='
$a=\App\Models\Attempt::find("'"$ID"'");
$a->region="'"$region"'";
$a->locale="'"$locale"'";
$a->save();
echo "OK ".$a->region." ".$a->locale.PHP_EOL;
'
}

check_versions() {
  api | jq '.report.versions'
}

expect_pack() {
  local expected="$1"
  local actual
  actual="$(api | jq -r '.report.versions.content_pack_id // ""')"
  if [[ "$actual" != "$expected" ]]; then
    echo "❌ EXPECT content_pack_id=$expected"
    echo "   GOT    content_pack_id=$actual"
    echo "Full versions:"
    check_versions
    exit 2
  fi
  echo "✅ content_pack_id=$actual"
}

echo "== Verify pack resolve (ReportComposer) =="
echo "BASE_URL=$BASE_URL"
echo "ATTEMPT_ID=$ID"
echo

# A. 精确命中（CN_MAINLAND + zh-CN）
echo "[A] exact: CN_MAINLAND / zh-CN"
set_attempt "CN_MAINLAND" "zh-CN"
check_versions
expect_pack "MBTI.cn-mainland.zh-CN.v0.2.1-TEST"
echo

# B. locale 降级（zh-TW → zh）
echo "[B] locale fallback: CN_MAINLAND / zh-TW -> zh"
set_attempt "CN_MAINLAND" "zh-TW"
check_versions
expect_pack "MBTI.cn-mainland.zh.v0.2.1-TEST"
echo

# C. region 降级（CN_MAINLAND/en 不存在 → GLOBAL/en 命中）
echo "[C] region fallback: CN_MAINLAND / en -> GLOBAL/en"
set_attempt "CN_MAINLAND" "en"
check_versions
expect_pack "MBTI.global.en.v0.2.1-TEST"
echo

# D. 最终兜底（fr-FR 不存在 → final_fallback 落到 GLOBAL/en）
echo "[D] final fallback: CN_MAINLAND / fr-FR -> GLOBAL/en"
set_attempt "CN_MAINLAND" "fr-FR"
check_versions
expect_pack "MBTI.global.en.v0.2.1-TEST"
echo

echo "🎉 ALL PASS"
