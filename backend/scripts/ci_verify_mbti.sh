#!/usr/bin/env bash
set -euo pipefail

# ----------------------------------------
# CI entry: start server -> self-check -> smoke -> verify_mbti
# ----------------------------------------

SCRIPT_DIR="$(cd "$(dirname "${BASH_SOURCE[0]}")" && pwd)"
BACKEND_DIR="$(cd "$SCRIPT_DIR/.." && pwd)"
REPO_DIR="$(cd "$BACKEND_DIR/.." && pwd)"

# -----------------------------
# Defaults (override by env)
# -----------------------------
HOST="${HOST:-127.0.0.1}"
PORT="${PORT:-8000}"
API="${API:-http://${HOST}:${PORT}}"

REGION="${REGION:-CN_MAINLAND}"
LOCALE="${LOCALE:-zh-CN}"

# Canonical "new layout" package path under content_packages/
MBTI_CONTENT_PACKAGE="${MBTI_CONTENT_PACKAGE:-default/CN_MAINLAND/zh-CN/MBTI-CN-v0.2.1-TEST}"

# Stable pack_id (used by config/content_packs.php fallback)
FAP_DEFAULT_PACK_ID="${FAP_DEFAULT_PACK_ID:-MBTI.cn-mainland.zh-CN.v0.2.1-TEST}"
FAP_DEFAULT_REGION="${FAP_DEFAULT_REGION:-$REGION}"
FAP_DEFAULT_LOCALE="${FAP_DEFAULT_LOCALE:-$LOCALE}"

# Artifacts
RUN_DIR="${RUN_DIR:-$BACKEND_DIR/artifacts/verify_mbti}"
LOG_DIR="$RUN_DIR/logs"
mkdir -p "$LOG_DIR"

SERVE_LOG="$LOG_DIR/artisan_serve.log"
SELF_CHECK_LOG="$LOG_DIR/self_check.log"
SMOKE_LOG="$LOG_DIR/smoke_questions.log"

echo "[CI] backend_dir=$BACKEND_DIR"
echo "[CI] API=$API"
echo "[CI] defaults: region=$REGION locale=$LOCALE pack_id=$FAP_DEFAULT_PACK_ID"
echo "[CI] MBTI_CONTENT_PACKAGE=$MBTI_CONTENT_PACKAGE"
echo "[CI] artifacts=$RUN_DIR"

need_cmd() { command -v "$1" >/dev/null 2>&1 || { echo "[CI][FAIL] missing command: $1" >&2; exit 2; }; }
need_cmd php
need_cmd curl
need_cmd jq

# Export env for both artisan serve and verify scripts
export REGION LOCALE MBTI_CONTENT_PACKAGE
export FAP_DEFAULT_PACK_ID FAP_DEFAULT_REGION FAP_DEFAULT_LOCALE

# ----------------------------------------
# Fix: legacy alias for questions endpoint
# Some code paths still look for "content_packages/MBTI-CN-v0.2.1-TEST"
# while canonical pack lives at "content_packages/default/.../MBTI-CN-v0.2.1-TEST"
# ----------------------------------------
PACKS_ROOT="$REPO_DIR/content_packages"
CANON_ABS="$PACKS_ROOT/$MBTI_CONTENT_PACKAGE"
LEGACY_NAME="$(basename "$CANON_ABS")"                     # MBTI-CN-v0.2.1-TEST
LEGACY_LINK="$PACKS_ROOT/$LEGACY_NAME"                     # content_packages/MBTI-CN-v0.2.1-TEST

if [[ -d "$CANON_ABS" ]]; then
  if [[ ! -e "$LEGACY_LINK" ]]; then
    echo "[CI] creating legacy alias: $LEGACY_LINK -> $CANON_ABS"
    ln -s "$CANON_ABS" "$LEGACY_LINK"
  else
    echo "[CI] legacy alias exists: $LEGACY_LINK"
  fi
else
  echo "[CI][FAIL] canonical package dir not found: $CANON_ABS" >&2
  echo "[CI][HINT] check repo has content_packages and MBTI_CONTENT_PACKAGE is correct" >&2
  exit 11
fi

# Quick sanity: questions.json must exist in canonical dir
if [[ ! -f "$CANON_ABS/questions.json" ]]; then
  echo "[CI][FAIL] questions.json missing at: $CANON_ABS/questions.json" >&2
  ls -la "$CANON_ABS" || true
  exit 12
fi

# ----------------------------------------
# Start server (artisan serve)
# ----------------------------------------
SERVER_PID=""

cleanup() {
  local ec=$?
  if [[ -n "${SERVER_PID:-}" ]]; then
    kill "$SERVER_PID" >/dev/null 2>&1 || true
    wait "$SERVER_PID" >/dev/null 2>&1 || true
  fi
  if [[ $ec -ne 0 ]]; then
    echo "[CI][FAIL] ci_verify_mbti exited with code=$ec" >&2
    echo "---- tail artisan_serve.log ----" >&2
    tail -n 200 "$SERVE_LOG" 2>/dev/null || true
    echo "---- tail self_check.log ----" >&2
    tail -n 200 "$SELF_CHECK_LOG" 2>/dev/null || true
    echo "---- tail smoke_questions.log ----" >&2
    tail -n 200 "$SMOKE_LOG" 2>/dev/null || true
    echo "[CI] artifacts=$RUN_DIR" >&2
  fi
  exit $ec
}
trap cleanup EXIT

cd "$BACKEND_DIR"

echo "[CI] starting server: php artisan serve --host=$HOST --port=$PORT"
# note: keep it in background and log to file
( php artisan serve --host="$HOST" --port="$PORT" >"$SERVE_LOG" 2>&1 ) &
SERVER_PID=$!
echo "[CI] waiting for health: $API/api/v0.2/health"

# wait for health
for i in $(seq 1 60); do
  if curl -fsS "$API/api/v0.2/health" >/dev/null 2>&1; then
    echo "[CI] server ready (pid=$SERVER_PID)"
    break
  fi
  sleep 0.5
  if [[ $i -eq 60 ]]; then
    echo "[CI][FAIL] server not ready after waiting" >&2
    exit 13
  fi
done

# ----------------------------------------
# Self check
# ----------------------------------------
echo "[CI] fap:self-check (manifest/assets/schema)"
# Use --pkg to point to canonical package
if php artisan fap:self-check --pkg="$MBTI_CONTENT_PACKAGE" >"$SELF_CHECK_LOG" 2>&1; then
  echo "[CI] self-check OK"
else
  echo "[CI][FAIL] self-check failed" >&2
  tail -n 220 "$SELF_CHECK_LOG" >&2 || true
  exit 21
fi

# ----------------------------------------
# Smoke: questions endpoint must work
# ----------------------------------------
echo "[CI] smoke: /api/v0.2/scales/MBTI/questions"
SMOKE_JSON="$RUN_DIR/smoke_questions.json"
http="$(curl -sS -L -o "$SMOKE_JSON" -w "%{http_code}" "$API/api/v0.2/scales/MBTI/questions" || true)"
echo "HTTP=$http" >"$SMOKE_LOG"
cat "$SMOKE_JSON" >>"$SMOKE_LOG" 2>/dev/null || true

if [[ "$http" != "200" ]]; then
  echo "[CI][FAIL] smoke HTTP=$http (see $SMOKE_LOG)" >&2
  exit 31
fi

if ! jq -e '.ok==true' "$SMOKE_JSON" >/dev/null 2>&1; then
  echo "[CI][FAIL] smoke returned ok=false. body:" >&2
  cat "$SMOKE_JSON" >&2 || true
  exit 32
fi

cnt="$(jq -r '.items | length' "$SMOKE_JSON" 2>/dev/null || echo 0)"
if [[ ! "$cnt" =~ ^[0-9]+$ ]] || (( cnt <= 0 )); then
  echo "[CI][FAIL] smoke questions items empty. cnt=$cnt" >&2
  exit 33
fi
echo "[CI] smoke questions OK (count=$cnt)"

# ----------------------------------------
# Run verify_mbti (E2E)
# ----------------------------------------
echo "[CI] running: bash ./scripts/verify_mbti.sh"
API="$API" REGION="$REGION" LOCALE="$LOCALE" RUN_DIR="$RUN_DIR" bash ./scripts/verify_mbti.sh

echo "[CI] DONE ✅ artifacts=$RUN_DIR"