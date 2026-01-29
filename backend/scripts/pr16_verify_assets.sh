#!/usr/bin/env bash
set -euo pipefail

SCRIPT_DIR="$(cd "$(dirname "${BASH_SOURCE[0]}")" && pwd)"
BACKEND_DIR="$(cd "$SCRIPT_DIR/.." && pwd)"
RUN_DIR="$BACKEND_DIR/artifacts/pr16"

mkdir -p "$RUN_DIR"

LOG_FILE="$RUN_DIR/verify.log"
SUMMARY_FILE="$RUN_DIR/summary.txt"
QUESTIONS_JSON="$RUN_DIR/questions.json"

SERVE_HOST="${SERVE_HOST:-127.0.0.1}"
SERVE_PORT="${SERVE_PORT:-8000}"
API="${API:-http://${SERVE_HOST}:${SERVE_PORT}}"
START_SERVER="${START_SERVER:-1}"

fail() { echo "[FAIL] $*" | tee -a "$LOG_FILE" >&2; exit 1; }

require_cmd() {
  command -v "$1" >/dev/null 2>&1 || fail "missing required command: $1"
}

require_cmd curl
require_cmd jq

cd "$BACKEND_DIR"

echo "[PR16] artifacts: $RUN_DIR" | tee -a "$LOG_FILE"

echo "[PR16] migrate" | tee -a "$LOG_FILE"
php artisan migrate | tee -a "$LOG_FILE"

echo "[PR16] seed: Pr16IqRavenDemoSeeder" | tee -a "$LOG_FILE"
php artisan db:seed --class=Pr16IqRavenDemoSeeder | tee -a "$LOG_FILE"

echo "[PR16] self-check: strict-assets" | tee -a "$LOG_FILE"
php artisan fap:self-check --strict-assets --pkg=default/CN_MAINLAND/zh-CN/IQ-RAVEN-CN-v0.3.0-DEMO | tee -a "$LOG_FILE"

# Start local server if API not reachable
if [[ "$START_SERVER" == "1" ]]; then
  if ! curl -sSf "$API/api/v0.3/scales" >/dev/null 2>&1; then
    echo "[PR16] starting local server" | tee -a "$LOG_FILE"
    php artisan serve --host="$SERVE_HOST" --port="$SERVE_PORT" >"$RUN_DIR/server.log" 2>&1 &
    SERVER_PID=$!
    trap 'kill "$SERVER_PID" >/dev/null 2>&1 || true' EXIT

    for i in {1..20}; do
      if curl -sSf "$API/api/v0.3/scales" >/dev/null 2>&1; then
        break
      fi
      sleep 0.5
    done
  fi
fi

echo "[PR16] fetch questions" | tee -a "$LOG_FILE"
http_code=$(curl -sS -L -o "$QUESTIONS_JSON" -w "%{http_code}" "$API/api/v0.3/scales/IQ_RAVEN/questions" || true)
if [[ "$http_code" != "200" ]]; then
  echo "[PR16] http status=$http_code" | tee -a "$LOG_FILE" >&2
  head -c 800 "$QUESTIONS_JSON" 2>/dev/null | tee -a "$LOG_FILE" >&2 || true
  fail "questions endpoint failed"
fi

if [[ ! -s "$QUESTIONS_JSON" ]]; then
  fail "questions response empty"
fi

asset_url=$(jq -r '.. | strings | select(test("/default/IQ-RAVEN-CN-v0.3.0-DEMO/assets/images/"))' "$QUESTIONS_JSON" | head -n 1)
if [[ -z "$asset_url" ]]; then
  fail "no asset url found in response"
fi
if [[ ! "$asset_url" =~ ^https?:// ]]; then
  fail "asset url not absolute: $asset_url"
fi

echo "[PR16] sample asset url: $asset_url" | tee -a "$LOG_FILE"

cat <<SUMMARY > "$SUMMARY_FILE"
PR16 verify summary
- seed: ok
- selfcheck: ok
- questions_api: ok
- sample_asset_url: $asset_url
SUMMARY

echo "[PR16] done" | tee -a "$LOG_FILE"
