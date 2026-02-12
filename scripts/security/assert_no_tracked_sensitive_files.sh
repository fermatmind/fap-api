#!/usr/bin/env bash
set -euo pipefail

ROOT="$(git rev-parse --show-toplevel)"
cd "$ROOT"

# tracked 文件路径检查（命中直接失败）
TRACKED="$(git ls-files)"

grep -qE '(^backend/\.env$|^\.env$)' <<< "$TRACKED" && { echo "[FAIL] tracked .env found"; exit 1; }
grep -qE '(^backend/vendor/|^vendor/)' <<< "$TRACKED" && { echo "[FAIL] tracked vendor found"; exit 1; }
grep -qE '(^backend/artifacts/)' <<< "$TRACKED" && { echo "[FAIL] tracked artifacts found"; exit 1; }
grep -qE '(^backend/database/.*\.sqlite$)' <<< "$TRACKED" && { echo "[FAIL] tracked sqlite found"; exit 1; }

HITS_REPORTS="$(grep -E '^backend/storage/app/private/reports/' <<< "$TRACKED" | grep -vE '^backend/storage/app/private/reports/\.gitkeep$' || true)"
[ -z "$HITS_REPORTS" ] || { echo "[FAIL] tracked report snapshots found"; echo "$HITS_REPORTS"; exit 1; }

HITS_ARCHIVES="$(grep -E '^backend/storage/app/archives/' <<< "$TRACKED" | grep -vE '^backend/storage/app/archives/\.gitkeep$' || true)"
[ -z "$HITS_ARCHIVES" ] || { echo "[FAIL] tracked archives found"; echo "$HITS_ARCHIVES"; exit 1; }

HITS_RUNTIME="$(grep -E '(^backend/storage/logs/|^backend/storage/framework/)' <<< "$TRACKED" | grep -vE '(^backend/storage/logs/\.gitkeep$|^backend/storage/framework/\.gitkeep$|^backend/storage/framework/cache/\.gitkeep$|^backend/storage/framework/sessions/\.gitkeep$|^backend/storage/framework/views/\.gitkeep$)' || true)"
[ -z "$HITS_RUNTIME" ] || { echo "[FAIL] tracked runtime storage found"; echo "$HITS_RUNTIME"; exit 1; }

echo "[assert] PASS"
