#!/usr/bin/env bash
set -euo pipefail

ZIP="${1:-dist/fap-api-source.zip}"
test -f "$ZIP"

LIST="$(unzip -l "$ZIP" | awk '{print $4}')"
PATTERN='(^fap-api/\.git/|^fap-api/backend/\.env($|\.|/)|^fap-api/\.env($|\.|/)|^fap-api/backend/vendor/|^fap-api/vendor/|^fap-api/node_modules/|^fap-api/backend/node_modules/|^fap-api/backend/artifacts/|^fap-api/backend/database/.*\.sqlite$|^fap-api/backend/storage/logs/|^fap-api/backend/storage/framework/|^fap-api/backend/storage/app/private/reports/|^fap-api/backend/storage/app/archives/)'

HITS="$(echo "$LIST" | grep -E "$PATTERN" || true)"
if [ -n "$HITS" ]; then
  echo "[verify][FAIL] forbidden paths found:"
  echo "$HITS"
  exit 1
fi

echo "[verify] PASS"
