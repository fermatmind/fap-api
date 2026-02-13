#!/usr/bin/env bash
set -euo pipefail

ZIP="${1:-dist/fap-api-source.zip}"
test -f "$ZIP"

ROOT="$(cd "$(dirname "${BASH_SOURCE[0]}")/../.." && pwd)"
TMP_DIR="$(mktemp -d)"
trap 'rm -rf "$TMP_DIR"' EXIT

unzip -qq "$ZIP" -d "$TMP_DIR"
bash "$ROOT/scripts/security/assert_artifact_clean.sh" --mode artifact --target "$TMP_DIR/fap-api"

LIST="$(unzip -Z1 "$ZIP")"

# 允许 env 示例文件存在
LIST_FILTERED="$(echo "$LIST" | grep -vE '^(fap-api/backend/\.env\.example|fap-api/\.env\.example)$' || true)"

# 1) 路径黑名单（命中直接失败）
PATTERN_PATH='(^fap-api/\.git/|^fap-api/backend/\.env($|[./])|^fap-api/\.env($|[./])|^fap-api/backend/vendor/|^fap-api/vendor/|^fap-api/node_modules/|^fap-api/backend/node_modules/|^fap-api/backend/artifacts/|^fap-api/backend/database/.*\.sqlite$|^fap-api/backend/storage/logs/|^fap-api/backend/storage/framework/|^fap-api/backend/storage/app/private/reports/|^fap-api/backend/storage/app/archives/)'
HITS_PATH="$(echo "$LIST_FILTERED" | grep -E "$PATTERN_PATH" || true)"
[ -z "$HITS_PATH" ] || { echo "[verify][FAIL] forbidden paths found:"; echo "$HITS_PATH"; exit 1; }

# 2) 内容敏感模式（命中直接失败；扫描常见文本类型）
# 仅扫描体量可控的文本文件扩展名，避免二进制与超大文件拖慢校验
SCAN_FILES="$(echo "$LIST_FILTERED" | grep -E '\.(env|php|json|yml|yaml|md|txt|sh)$' || true)"

BAD=0
while IFS= read -r f; do
  [ -n "$f" ] || continue
  # 跳过 env 示例文件
  echo "$f" | grep -qE '(^fap-api/backend/\.env\.example$|^fap-api/\.env\.example$)' && continue

  CONTENT="$(unzip -p "$ZIP" "$f" 2>/dev/null || true)"

  # 高危密钥/凭据特征
  if echo "$CONTENT" | grep -qE 'APP_KEY=base64:[A-Za-z0-9+/=]{20,}'; then
    echo "$CONTENT" | grep -qE 'APP_KEY=base64:CHANGEME' || { echo "[verify][FAIL] APP_KEY leaked in $f"; BAD=1; }
  fi
  echo "$CONTENT" | grep -qE 'sk_live_[0-9a-zA-Z]{24,}' && { echo "[verify][FAIL] Stripe live secret leaked in $f"; BAD=1; }
  echo "$CONTENT" | grep -qE 'whsec_[0-9a-zA-Z]{24,}' && { echo "[verify][FAIL] Stripe webhook secret leaked in $f"; BAD=1; }
  echo "$CONTENT" | grep -qE 'AKIA[0-9A-Z]{16}' && { echo "[verify][FAIL] AWS AccessKey leaked in $f"; BAD=1; }
  echo "$CONTENT" | grep -qE -- '-----BEGIN (RSA |EC )?PRIVATE KEY-----' && { echo "[verify][FAIL] Private key leaked in $f"; BAD=1; }

  # 高敏数据快照特征（报告结构关键字段）
  echo "$CONTENT" | grep -qE '"scores"\s*:\s*\{' && echo "$CONTENT" | grep -qE '"profile_version"' && { echo "[verify][FAIL] report snapshot content leaked in $f"; BAD=1; }

done <<< "$SCAN_FILES"

[ "$BAD" -eq 0 ] || exit 1
echo "[verify] PASS"
