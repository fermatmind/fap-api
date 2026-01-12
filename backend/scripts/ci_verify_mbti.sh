#!/usr/bin/env bash
set -euo pipefail

# -----------------------------
# Paths
# -----------------------------
SCRIPT_DIR="$(cd "$(dirname "${BASH_SOURCE[0]}")" && pwd)"
BACKEND_DIR="$(cd "$SCRIPT_DIR/.." && pwd)"
REPO_DIR="$(cd "$BACKEND_DIR/.." && pwd)"
cd "$BACKEND_DIR"

# -----------------------------
# Defaults (override via env)
# -----------------------------
HOST="${HOST:-127.0.0.1}"
PORT="${PORT:-18000}"                      # ✅ avoid 8000 collisions by default
API="http://${HOST}:${PORT}"

REGION="${REGION:-CN_MAINLAND}"
LOCALE="${LOCALE:-zh-CN}"

# Your stable pack identifiers
PACK_ID="${PACK_ID:-MBTI.cn-mainland.zh-CN.v0.2.1-TEST}"
LEGACY_DIR="${LEGACY_DIR:-MBTI-CN-v0.2.1-TEST}"

# Artifacts
RUN_DIR="${RUN_DIR:-$BACKEND_DIR/artifacts/verify_mbti}"
ARTIFACT_DIR="$RUN_DIR"                    # alias (for docs/consistency)
LOG_DIR="$RUN_DIR/logs"
mkdir -p "$LOG_DIR"

SERVE_LOG="$LOG_DIR/artisan_serve.log"
SELF_CHECK_LOG="$LOG_DIR/self_check.log"
SMOKE_Q_LOG="$LOG_DIR/smoke_questions.json"
MVP_LOG="$LOG_DIR/mvp_check.log"

# MVP hard gate toggle:
# - MVP_STRICT=1 (default): fail CI if MVP thresholds not met
# - MVP_STRICT=0: only log, do not fail
MVP_STRICT="${MVP_STRICT:-1}"

echo "[CI] backend_dir=$BACKEND_DIR"
echo "[CI] repo_dir=$REPO_DIR"
echo "[CI] API=$API"
echo "[CI] defaults: region=$REGION locale=$LOCALE pack_id=$PACK_ID legacy_dir=$LEGACY_DIR"
echo "[CI] artifacts=$RUN_DIR"
echo "[CI] mvp_strict=$MVP_STRICT"

# -----------------------------
# Helpers
# -----------------------------
need_cmd() { command -v "$1" >/dev/null 2>&1 || { echo "[CI][FAIL] missing cmd: $1" >&2; exit 2; }; }
need_cmd curl
need_cmd jq
need_cmd php
need_cmd grep
need_cmd sed
need_cmd lsof

fail() { echo "[CI][FAIL] $*" >&2; exit 1; }

wait_health() {
  local url="$1"
  local tries="${2:-80}"   # ~16s if sleep 0.2
  for i in $(seq 1 "$tries"); do
    if curl -fsS "$url" >/dev/null 2>&1; then
      return 0
    fi
    sleep 0.2
  done
  return 1
}

# -----------------------------
# Ensure legacy alias exists (CI runner usually doesn't have it)
#   ../content_packages/MBTI-CN-v0.2.1-TEST -> default/CN_MAINLAND/zh-CN/MBTI-CN-v0.2.1-TEST
# -----------------------------
CONTENT_ROOT="$REPO_DIR/content_packages"
CANON_REL="default/${REGION}/${LOCALE}/${LEGACY_DIR}"
CANON_ABS="$CONTENT_ROOT/$CANON_REL"
ALIAS_ABS="$CONTENT_ROOT/$LEGACY_DIR"

if [[ -d "$CANON_ABS" ]]; then
  if [[ ! -e "$ALIAS_ABS" ]]; then
    echo "[CI] create legacy alias: $ALIAS_ABS -> $CANON_REL"
    (cd "$CONTENT_ROOT" && ln -s "$CANON_REL" "$LEGACY_DIR")
  else
    echo "[CI] legacy alias exists: $ALIAS_ABS"
  fi
else
  echo "[CI][WARN] canonical pack dir not found: $CANON_ABS"
  echo "[CI][WARN] CI may fail if app still tries to load legacy dir."
fi

# Prefer canonical pack dir; fall back to alias if needed
PACK_DIR=""
if [[ -d "$CANON_ABS" ]]; then
  PACK_DIR="$CANON_ABS"
elif [[ -d "$ALIAS_ABS" ]]; then
  PACK_DIR="$ALIAS_ABS"
fi

if [[ -z "$PACK_DIR" ]]; then
  echo "[CI][WARN] PACK_DIR not found (both canonical and legacy alias missing). MVP check will be skipped."
else
  echo "[CI] PACK_DIR=$PACK_DIR"
fi

# -----------------------------
# Prepare Laravel env + DB
# -----------------------------
echo "[CI] prepare laravel env/db"

if [[ ! -f ".env" ]]; then
  cp -a .env.example .env
fi

php artisan key:generate --force >/dev/null 2>&1 || true

mkdir -p database
touch database/database.sqlite

# Force sqlite (safe for CI)
export APP_ENV="${APP_ENV:-testing}"
export DB_CONNECTION="${DB_CONNECTION:-sqlite}"
export DB_DATABASE="${DB_DATABASE:-$BACKEND_DIR/database/database.sqlite}"

php artisan migrate --force >/dev/null

# -----------------------------
# Start server (fail fast if port in use)
# -----------------------------
if lsof -ti "tcp:${PORT}" >/dev/null 2>&1; then
  fail "port already in use: ${PORT}. Stop your local server or set PORT=18xxx"
fi

echo "[CI] starting server: php artisan serve --host=$HOST --port=$PORT"
php artisan serve --host="$HOST" --port="$PORT" >"$SERVE_LOG" 2>&1 &
SERVE_PID=$!

cleanup() {
  local ec=$?
  if [[ -n "${SERVE_PID:-}" ]]; then
    kill "$SERVE_PID" >/dev/null 2>&1 || true
  fi
  exit $ec
}
trap cleanup EXIT

echo "[CI] waiting for health: $API/api/v0.2/health"
wait_health "$API/api/v0.2/health" 100 || {
  echo "[CI][FAIL] server not ready"
  echo "---- tail artisan_serve.log ----" >&2
  tail -n 120 "$SERVE_LOG" >&2 || true
  exit 11
}
echo "[CI] server ready (pid=$SERVE_PID)"

# -----------------------------
# Self-check: manifest/assets/schema
# -----------------------------
echo "[CI] fap:self-check (manifest/assets/schema)"
php artisan fap:self-check >"$SELF_CHECK_LOG" 2>&1 || {
  echo "[CI][FAIL] self-check failed"
  tail -n 220 "$SELF_CHECK_LOG" >&2 || true
  exit 12
}
echo "[CI] self-check OK"

# -----------------------------
# MVP check (templates + reads)
# - Always persist log to artifacts (for postmortem)
# - Default is HARD GATE (MVP_STRICT=1)
# -----------------------------
if [[ -n "$PACK_DIR" ]]; then
  MVP_SH="$SCRIPT_DIR/mvp_check.sh"
  if [[ ! -x "$MVP_SH" ]]; then
    echo "[CI][FAIL] missing or not executable: $MVP_SH" >&2
    exit 14
  fi

  echo "[CI] MVP check -> $MVP_LOG"
  set +e
  {
    echo "== MVP check (templates + reads) =="
    echo "PACK_DIR=$PACK_DIR"
    bash "$MVP_SH" "$PACK_DIR"
    echo "EXIT=$?"
  } 2>&1 | tee "$MVP_LOG"
  set -e

  if [[ "$MVP_STRICT" == "1" ]]; then
    # 1) templates coverage: any false => FAIL
    if grep -qE '^[A-Z]{2}\.[A-Z]=false$' "$MVP_LOG"; then
      echo "[CI][FAIL] MVP templates coverage has false (see $MVP_LOG)" >&2
      exit 30
    fi

    # 2) reads thresholds
    total_unique="$(grep -E '^reads\.total_unique=' "$MVP_LOG" | tail -n 1 | sed -E 's/^reads\.total_unique=//')"
    fallback="$(grep -E '^reads\.fallback=' "$MVP_LOG" | tail -n 1 | sed -E 's/^reads\.fallback=//')"
    non_empty="$(grep -E '^reads\.non_empty_strategy_buckets=' "$MVP_LOG" | tail -n 1 | sed -E 's/^reads\.non_empty_strategy_buckets=([0-9]+).*/\1/')"

    if ! [[ "$total_unique" =~ ^[0-9]+$ && "$fallback" =~ ^[0-9]+$ && "$non_empty" =~ ^[0-9]+$ ]]; then
      echo "[CI][FAIL] MVP reads stats missing/non-numeric (see $MVP_LOG)" >&2
      exit 31
    fi
    if (( total_unique < 7 )); then
      echo "[CI][FAIL] MVP reads.total_unique=$total_unique < 7 (see $MVP_LOG)" >&2
      exit 32
    fi
    if (( fallback < 2 )); then
      echo "[CI][FAIL] MVP reads.fallback=$fallback < 2 (see $MVP_LOG)" >&2
      exit 33
    fi
    if (( non_empty < 2 )); then
      echo "[CI][FAIL] MVP reads.non_empty_strategy_buckets=$non_empty < 2 (see $MVP_LOG)" >&2
      exit 34
    fi

    echo "[CI] MVP check PASS ✅"
  else
    echo "[CI][WARN] MVP_STRICT=0; skip MVP hard gate (log only)."
  fi
fi

# -----------------------------
# Smoke: questions endpoint must be ok=true
# -----------------------------
echo "[CI] smoke: /api/v0.2/scales/MBTI/questions"
curl -fsS "$API/api/v0.2/scales/MBTI/questions" >"$SMOKE_Q_LOG" || {
  echo "[CI][FAIL] smoke curl failed"
  tail -n 120 "$SERVE_LOG" >&2 || true
  exit 13
}

if ! jq -e '.ok==true' "$SMOKE_Q_LOG" >/dev/null 2>&1; then
  echo "[CI][FAIL] smoke returned ok=false. body:"
  head -c 1200 "$SMOKE_Q_LOG" || true
  echo
  echo "[CI] tail artisan_serve.log:"
  tail -n 200 "$SERVE_LOG" || true
  exit 13
fi
echo "[CI] smoke OK"

# -----------------------------
# Run E2E verify
# -----------------------------
echo "[CI] running verify_mbti.sh"
API="$API" REGION="$REGION" LOCALE="$LOCALE" RUN_DIR="$RUN_DIR" bash "$SCRIPT_DIR/verify_mbti.sh"
echo "[CI] verify_mbti OK ✅"
