#!/usr/bin/env bash
set -euo pipefail

BASE_URL="${BASE_URL:-http://127.0.0.1:8000}"

echo "[ci_verify_mbti] BASE_URL=${BASE_URL}"

# 1) healthz ok
curl -fsS "${BASE_URL}/api/v0.2/healthz" | jq -e '.ok==true' >/dev/null
echo "[ok] healthz"

# 2) mbti questions ok
curl -fsS "${BASE_URL}/api/v0.2/scales/MBTI/questions?region=CN_MAINLAND&locale=zh-CN" | jq -e '.ok==true' >/dev/null
echo "[ok] MBTI questions"

# 3) content-packs ok
curl -fsS "${BASE_URL}/api/v0.2/content-packs" | jq -e '.ok==true' >/dev/null
echo "[ok] content-packs"

echo "[ci_verify_mbti] DONE"
