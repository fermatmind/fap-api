#!/usr/bin/env bash
set -euo pipefail

# ==========================================
# ci_verify_mbti.sh
# One-command CI/server E2E verification:
#   - enforce non-fallback defaults (CN_MAINLAND / zh-CN / pack_id)
#   - boot API server (artisan serve)
#   - run verify_mbti.sh
#   - always stop server
#
# Usage:
#   bash ./scripts/ci_verify_mbti.sh
#   PORT=8010 bash ./scripts/ci_verify_mbti.sh
#   API=http://127.0.0.1:8010 bash ./scripts/ci_verify_mbti.sh
#   RUN_DIR=/tmp/verify_mbti bash ./scripts/ci_verify_mbti.sh
# ==========================================

SCRIPT_DIR="$(cd "$(dirname "${BASH_SOURCE[0]}")" && pwd)"
BACKEND_DIR="$(cd "$SCRIPT_DIR/.." && pwd)"

cd "$BACKEND_DIR"

# -----------------------------
# Defaults (safe for CI)
# -----------------------------
HOST="${HOST:-127.0.0.1}"
PORT="${PORT:-8000}"
API="${API:-http://${HOST}:${PORT}}"

# Content defaults (prevent GLOBAL/en fallback)
export FAP_DEFAULT_REGION="${FAP_DEFAULT_REGION:-CN_MAINLAND}"
export FAP_DEFAULT_LOCALE="${FAP_DEFAULT_LOCALE:-zh-CN}"
export FAP_DEFAULT_PACK_ID="${FAP_DEFAULT_PACK_ID:-MBTI.cn-mainland.zh-CN.v0.2.1-TEST}"

# If your app resolves manifests by this env (you used it in FapSelfCheck), keep it aligned:
export MBTI_CONTENT_PACKAGE="${MBTI_CONTENT_PACKAGE:-default/CN_MAINLAND/zh-CN/MBTI-CN-v0.2.1-TEST}"

# verify_mbti.sh inputs (you can override in CI)
export REGION="${REGION:-CN_MAINLAND}"
export LOCALE="${LOCALE:-zh-CN}"
export EXPECT_PACK_PREFIX="${EXPECT_PACK_PREFIX:-MBTI.cn-mainland.zh-CN.}"
export STRICT="${STRICT:-1}"     # forbid deprecated/GLOBAL/en signals
export VERIFY_MODE="${VERIFY_MODE:-ci}"  # informational only (avoid clashing with accept_overrides_D.sh MODE)
export API="$API"                # verify_mbti.sh reads API var

# Artifacts dir
RUN_DIR="${RUN_DIR:-$BACKEND_DIR/artifacts/verify_mbti}"
mkdir -p "$RUN_DIR" "$RUN_DIR/logs"

# -----------------------------
# Helpers
# -----------------------------
need_cmd() {
  command -v "$1" >/dev/null 2>&1 || { echo "[FAIL] missing command: $1" >&2; exit 2; }
}
need_cmd php
need_cmd curl
need_cmd jq
need_cmd python3

SERVER_PID=""
SERVER_LOG="$RUN_DIR/logs/artisan_serve.log"

cleanup() {
  local ec=$?
  if [[ -n "${SERVER_PID:-}" ]]; then
    if kill -0 "$SERVER_PID" >/dev/null 2>&1; then
      kill "$SERVER_PID" >/dev/null 2>&1 || true
      for _ in 1 2 3 4 5; do
        kill -0 "$SERVER_PID" >/dev/null 2>&1 || break
        sleep 0.2
      done
      kill -9 "$SERVER_PID" >/dev/null 2>&1 || true
    fi
  fi

  if [[ $ec -ne 0 ]]; then
    echo "[FAIL] ci_verify_mbti exited with code=$ec" >&2

    if [[ -f "$SERVER_LOG" ]]; then
      echo "---- artisan serve log (tail 120 lines) ----" >&2
      tail -n 120 "$SERVER_LOG" >&2 || true
      echo >&2
    fi

    if [[ -f "$RUN_DIR/logs/self_check.log" ]]; then
      echo "---- self-check log (tail 200 lines) ----" >&2
      tail -n 200 "$RUN_DIR/logs/self_check.log" >&2 || true
      echo >&2
    fi

    if [[ -f "$RUN_DIR/logs/overrides_accept_D.log" ]]; then
      echo "---- overrides_accept_D.log (tail 200 lines) ----" >&2
      tail -n 200 "$RUN_DIR/logs/overrides_accept_D.log" >&2 || true
      echo >&2
    fi

    echo "[ARTIFACTS] $RUN_DIR" >&2
  fi
  exit $ec
}
trap cleanup EXIT INT TERM

wait_health() {
  local url="$1"
  local tries="${2:-60}"    # 60 * 0.25s = 15s
  local sleep_s="${3:-0.25}"

  for ((i=1; i<=tries; i++)); do
    if curl -fsS "$url" >/dev/null 2>&1; then
      return 0
    fi
    sleep "$sleep_s"
  done
  return 1
}

# -----------------------------
# Boot server (artisan serve)
# -----------------------------
echo "[CI] backend_dir=$BACKEND_DIR"
echo "[CI] API=$API"
echo "[CI] defaults: region=$FAP_DEFAULT_REGION locale=$FAP_DEFAULT_LOCALE pack_id=$FAP_DEFAULT_PACK_ID"
echo "[CI] MBTI_CONTENT_PACKAGE=$MBTI_CONTENT_PACKAGE"
echo "[CI] artifacts=$RUN_DIR"
echo "[CI] verify_mode=$VERIFY_MODE"

# Clean cached config for deterministic env behavior
php artisan config:clear >/dev/null 2>&1 || true

echo "[CI] starting server: php artisan serve --host=$HOST --port=$PORT"
php artisan serve --host="$HOST" --port="$PORT" >"$SERVER_LOG" 2>&1 &
SERVER_PID=$!

echo "[CI] waiting for health: $API/api/v0.2/health"
if ! wait_health "$API/api/v0.2/health" 80 0.25; then
  echo "[FAIL] server health not ready: $API/api/v0.2/health" >&2
  exit 10
fi
echo "[CI] server ready (pid=$SERVER_PID)"

# Optional: self-check content pack fast (recommended in CI)
# If you want to skip: export SKIP_SELF_CHECK=1
if [[ "${SKIP_SELF_CHECK:-0}" != "1" ]]; then
  echo "[CI] fap:self-check (manifest/assets/schema)"
  php artisan fap:self-check --pkg="$MBTI_CONTENT_PACKAGE" >"$RUN_DIR/logs/self_check.log" 2>&1 || {
    echo "---- self-check log (tail 200 lines) ----" >&2
    tail -n 200 "$RUN_DIR/logs/self_check.log" >&2 || true
    exit 11
  }
  echo "[CI] self-check OK"
fi

# -----------------------------
# Run verify_mbti
# -----------------------------
echo "[CI] running: bash ./scripts/verify_mbti.sh"
RUN_DIR="$RUN_DIR" API="$API" VERIFY_MODE="$VERIFY_MODE" bash "$SCRIPT_DIR/verify_mbti.sh"

echo "[CI] DONE ✅"