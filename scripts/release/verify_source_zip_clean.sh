#!/usr/bin/env bash
set -euo pipefail

ZIP="${1:-dist/fap-api-source.zip}"
test -f "$ZIP"

LIST="$(unzip -l "$ZIP" | awk '{print $4}')"

PATTERN='(^fap-api/\.git/|^fap-api/backend/\.env($|\.|/)|^fap-api/backend/vendor/|^fap-api/vendor/|^fap-api/node_modules/|^fap-api/backend/node_modules/|^fap-api/backend/database/.*\.sqlite$|^fap-api/backend/storage/logs/|^fap-api/backend/storage/framework/|^fap-api/backend/storage/app/private/reports/|^fap-api/backend/storage/app/archives/|^fap-api/backend/artifacts/)'
ALLOWED_GITKEEP='(^fap-api/backend/storage/logs/$|^fap-api/backend/storage/framework/$|^fap-api/backend/storage/framework/cache/$|^fap-api/backend/storage/framework/sessions/$|^fap-api/backend/storage/framework/views/$|^fap-api/backend/storage/app/private/reports/$|^fap-api/backend/storage/app/archives/$|^fap-api/backend/storage/logs/\.gitkeep$|^fap-api/backend/storage/framework/\.gitkeep$|^fap-api/backend/storage/framework/cache/\.gitkeep$|^fap-api/backend/storage/framework/sessions/\.gitkeep$|^fap-api/backend/storage/framework/views/\.gitkeep$|^fap-api/backend/storage/app/private/reports/\.gitkeep$|^fap-api/backend/storage/app/archives/\.gitkeep$)'

HITS="$(echo "$LIST" | grep -E "$PATTERN" || true)"
HITS="$(echo "$HITS" | grep -Ev "$ALLOWED_GITKEEP" || true)"

if [ -n "$HITS" ]; then
  echo "[verify][FAIL] forbidden paths found in source zip:"
  echo "$HITS"
  exit 1
fi

echo "[verify] PASS"
