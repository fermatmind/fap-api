#!/usr/bin/env bash
set -euo pipefail

SCRIPT_DIR="$(cd "$(dirname "${BASH_SOURCE[0]}")" && pwd)"
BACKEND_DIR="$(cd "$SCRIPT_DIR/.." && pwd)"
cd "$BACKEND_DIR"

need_cmd() { command -v "$1" >/dev/null 2>&1 || { echo "[CI][FAIL] missing command: $1" >&2; exit 2; }; }
need_cmd php
need_cmd curl
need_cmd jq

# -----------------------------
# CI-hard defaults (NO GLOBAL/en fallback)
# -----------------------------
API_HOST="${API_HOST:-127.0.0.1}"
API_PORT="${API_PORT:-8000}"
API="http://${API_HOST}:${API_PORT}"

FAP_DEFAULT_REGION="${FAP_DEFAULT_REGION:-CN_MAINLAND}"
FAP_DEFAULT_LOCALE="${FAP_DEFAULT_LOCALE:-zh-CN}"
FAP_DEFAULT_PACK_ID="${FAP_DEFAULT_PACK_ID:-MBTI.cn-mainland.zh-CN.v0.2.1-TEST}"
MBTI_CONTENT_PACKAGE="${MBTI_CONTENT_PACKAGE:-default/CN_MAINLAND/zh-CN/MBTI-CN-v0.2.1-TEST}"

APP_ENV="${APP_ENV:-testing}"
APP_DEBUG="${APP_DEBUG:-false}"
LOG_CHANNEL="${LOG_CHANNEL:-stderr}"

# Optional: if your code has "forbid legacy/scan/deprecated" switches, hard-enable here.
# (Replace names to your actual env keys if they exist)
FAP_FORBID_DEPRECATED="${FAP_FORBID_DEPRECATED:-1}"
FAP_FORBID_LEGACY="${FAP_FORBID_LEGACY:-1}"
FAP_FORBID_SCAN="${FAP_FORBID_SCAN:-1}"

# -----------------------------
# Artifacts
# -----------------------------
RUN_DIR="${RUN_DIR:-$BACKEND_DIR/artifacts/verify_mbti}"
LOG_DIR="$RUN_DIR/logs"
mkdir -p "$LOG_DIR"

SERVE_LOG="$LOG_DIR/artisan_serve.log"
SELF_LOG="$LOG_DIR/self_check.log"
SMOKE_LOG="$LOG_DIR/smoke_questions.log"

echo "[CI] backend_dir=$BACKEND_DIR"
echo "[CI] API=$API"
echo "[CI] defaults: region=$FAP_DEFAULT_REGION locale=$FAP_DEFAULT_LOCALE pack_id=$FAP_DEFAULT_PACK_ID"
echo "[CI] MBTI_CONTENT_PACKAGE=$MBTI_CONTENT_PACKAGE"
echo "[CI] artifacts=$RUN_DIR"

# -----------------------------
# Clean Laravel config cache (important in CI)
# -----------------------------
rm -f bootstrap/cache/config.php bootstrap/cache/services.php bootstrap/cache/packages.php 2>/dev/null || true

# -----------------------------
# Start server (HARD bind env to this process)
# -----------------------------
echo "[CI] starting server: php artisan serve --host=${API_HOST} --port=${API_PORT}"

APP_ENV="$APP_ENV" \
APP_DEBUG="$APP_DEBUG" \
LOG_CHANNEL="$LOG_CHANNEL" \
FAP_DEFAULT_REGION="$FAP_DEFAULT_REGION" \
FAP_DEFAULT_LOCALE="$FAP_DEFAULT_LOCALE" \
FAP_DEFAULT_PACK_ID="$FAP_DEFAULT_PACK_ID" \
MBTI_CONTENT_PACKAGE="$MBTI_CONTENT_PACKAGE" \
FAP_FORBID_DEPRECATED="$FAP_FORBID_DEPRECATED" \
FAP_FORBID_LEGACY="$FAP_FORBID_LEGACY" \
FAP_FORBID_SCAN="$FAP_FORBID_SCAN" \
php artisan serve --host="${API_HOST}" --port="${API_PORT}" >"$SERVE_LOG" 2>&1 &
SERVE_PID=$!

cleanup() {
  local ec=$?
  if kill -0 "$SERVE_PID" >/dev/null 2>&1; then
    kill "$SERVE_PID" >/dev/null 2>&1 || true
  fi
  exit $ec
}
trap cleanup EXIT

# Wait for health
echo "[CI] waiting for health: $API/api/v0.2/health"
for i in {1..60}; do
  if curl -fsS "$API/api/v0.2/health" >/dev/null 2>&1; then
    echo "[CI] server ready (pid=$SERVE_PID)"
    break
  fi
  sleep 0.5
done

if ! curl -fsS "$API/api/v0.2/health" >/dev/null 2>&1; then
  echo "[CI][FAIL] server not ready. tail artisan_serve.log:" >&2
  tail -n 120 "$SERVE_LOG" >&2 || true
  exit 10
fi

# -----------------------------
# Self-check (manifest/assets/schema)
# -----------------------------
echo "[CI] fap:self-check (manifest/assets/schema)"
set +e
php artisan fap:self-check --pkg="$MBTI_CONTENT_PACKAGE" >"$SELF_LOG" 2>&1
sc_ec=$?
set -e
if [[ "$sc_ec" != "0" ]]; then
  echo "[CI][FAIL] self-check failed (exit=$sc_ec). tail self_check.log:" >&2
  tail -n 200 "$SELF_LOG" >&2 || true
  exit 11
fi
echo "[CI] self-check OK"

# -----------------------------
# Smoke: questions endpoint MUST be OK before verify_mbti
# -----------------------------
echo "[CI] smoke: /api/v0.2/scales/MBTI/questions"
set +e
curl -sS "$API/api/v0.2/scales/MBTI/questions" >"$RUN_DIR/questions.smoke.json" 2>"$SMOKE_LOG"
sm_ec=$?
set -e

if [[ "$sm_ec" != "0" ]]; then
  echo "[CI][FAIL] smoke curl failed (exit=$sm_ec)." >&2
  tail -n 80 "$SMOKE_LOG" >&2 || true
  echo "[CI] tail artisan_serve.log:" >&2
  tail -n 200 "$SERVE_LOG" >&2 || true
  exit 12
fi

if ! jq -e '.ok==true' "$RUN_DIR/questions.smoke.json" >/dev/null 2>&1; then
  echo "[CI][FAIL] smoke returned ok=false. body:" >&2
  head -c 800 "$RUN_DIR/questions.smoke.json" >&2 || true
  echo >&2
  echo "[CI] tail artisan_serve.log:" >&2
  tail -n 200 "$SERVE_LOG" >&2 || true
  exit 13
fi
echo "[CI] smoke questions OK"

# -----------------------------
# Run verify_mbti.sh
# -----------------------------
echo "[CI] running: bash ./scripts/verify_mbti.sh"
API="$API" BASE="$API" STRICT=1 MODE=ci RUN_DIR="$RUN_DIR" bash ./scripts/verify_mbti.sh
echo "[CI] verify_mbti OK ✅"