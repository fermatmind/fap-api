#!/usr/bin/env bash
set -euo pipefail

SCRIPT_DIR="$(cd "$(dirname "${BASH_SOURCE[0]}")" && pwd)"
BACKEND_DIR="$(cd "$SCRIPT_DIR/.." && pwd)"
ROOT_DIR="$(cd "$BACKEND_DIR/.." && pwd)"
RUN_DIR="$BACKEND_DIR/artifacts/pr17"

mkdir -p "$RUN_DIR"

LOG_FILE="$RUN_DIR/verify.log"
SUMMARY_FILE="$RUN_DIR/summary.txt"
SERVER_LOG="$RUN_DIR/server.log"
PID_FILE="$RUN_DIR/server.pid"

SERVE_HOST="${SERVE_HOST:-127.0.0.1}"
SERVE_PORT="${SERVE_PORT:-18002}"
API="${API:-http://${SERVE_HOST}:${SERVE_PORT}}"
START_SERVER="${START_SERVER:-1}"
SIMPLE_ANON_ID="${SIMPLE_ANON_ID:-pr17-simple-anon}"
RAVEN_ANON_ID="${RAVEN_ANON_ID:-pr17-raven-anon}"
export FAP_PACKS_DRIVER="${FAP_PACKS_DRIVER:-local}"
export FAP_PACKS_ROOT="${FAP_PACKS_ROOT:-${ROOT_DIR}/content_packages}"
export FAP_DEFAULT_REGION="${FAP_DEFAULT_REGION:-CN_MAINLAND}"
export FAP_DEFAULT_LOCALE="${FAP_DEFAULT_LOCALE:-zh-CN}"

fail() { echo "[FAIL] $*" | tee -a "$LOG_FILE" >&2; exit 1; }

require_cmd() {
  command -v "$1" >/dev/null 2>&1 || fail "missing required command: $1"
}

require_cmd curl
require_cmd jq

cleanup_port() {
  local port="$1"
  local pid
  pid="$(lsof -ti tcp:"${port}" 2>/dev/null || true)"
  if [[ -n "${pid}" ]]; then
    kill "${pid}" >/dev/null 2>&1 || true
    sleep 0.5
    kill -9 "${pid}" >/dev/null 2>&1 || true
  fi
}

cd "$BACKEND_DIR"

echo "[PR17] artifacts: $RUN_DIR" | tee -a "$LOG_FILE"

# composer install should be executed manually (see docs/verify/pr17_verify.md)

echo "[PR17] migrate" | tee -a "$LOG_FILE"
php artisan migrate --force | tee -a "$LOG_FILE"

echo "[PR17] seed: Pr16IqRavenDemoSeeder" | tee -a "$LOG_FILE"
php artisan db:seed --class=Pr16IqRavenDemoSeeder | tee -a "$LOG_FILE"

echo "[PR17] seed: Pr17SimpleScoreDemoSeeder" | tee -a "$LOG_FILE"
php artisan db:seed --class=Pr17SimpleScoreDemoSeeder | tee -a "$LOG_FILE"

if [[ "$START_SERVER" == "1" ]]; then
  cleanup_port "$SERVE_PORT"
  echo "[PR17] starting local server" | tee -a "$LOG_FILE"
  php artisan serve --host="$SERVE_HOST" --port="$SERVE_PORT" >"$SERVER_LOG" 2>&1 &
  SERVER_PID=$!
  echo "$SERVER_PID" > "$PID_FILE"
  trap 'kill "$SERVER_PID" >/dev/null 2>&1 || true' EXIT

  for i in {1..30}; do
    if curl -sSf "$API/api/v0.3/scales" >/dev/null 2>&1; then
      break
    fi
    sleep 0.5
  done
fi

simple_start="$RUN_DIR/curl_simple_start.json"
simple_submit="$RUN_DIR/curl_simple_submit.json"
simple_result="$RUN_DIR/curl_simple_result.json"
simple_report="$RUN_DIR/curl_simple_report.json"

cat <<JSON > "$RUN_DIR/simple_start_payload.json"
{"scale_code":"SIMPLE_SCORE_DEMO","anon_id":"${SIMPLE_ANON_ID}"}
JSON

echo "[PR17] simple_score_demo start" | tee -a "$LOG_FILE"
http_code=$(curl -sS -L -o "$simple_start" -w "%{http_code}" -X POST \
  -H 'Content-Type: application/json' \
  -H "X-Anon-Id: ${SIMPLE_ANON_ID}" \
  -d @"$RUN_DIR/simple_start_payload.json" \
  "$API/api/v0.3/attempts/start" || true)
[[ "$http_code" == "200" ]] || fail "simple start failed (http=$http_code)"

simple_attempt_id=$(jq -r '.attempt_id // empty' "$simple_start")
[[ -n "$simple_attempt_id" ]] || fail "simple start missing attempt_id"

echo "[PR17] simple_score_demo submit" | tee -a "$LOG_FILE"
cat <<JSON > "$RUN_DIR/simple_submit_payload.json"
{
  "attempt_id": "$simple_attempt_id",
  "duration_ms": 120000,
  "answers": [
    {"question_id":"SS-001","code":"5"},
    {"question_id":"SS-002","code":"4"},
    {"question_id":"SS-003","code":"3"},
    {"question_id":"SS-004","code":"2"},
    {"question_id":"SS-005","code":"1"}
  ]
}
JSON

http_code=$(curl -sS -L -o "$simple_submit" -w "%{http_code}" -X POST \
  -H 'Content-Type: application/json' \
  -H "X-Anon-Id: ${SIMPLE_ANON_ID}" \
  -d @"$RUN_DIR/simple_submit_payload.json" \
  "$API/api/v0.3/attempts/submit" || true)
[[ "$http_code" == "200" ]] || fail "simple submit failed (http=$http_code)"

simple_raw=$(jq -r '.result.raw_score // empty' "$simple_submit")
simple_final=$(jq -r '.result.final_score // empty' "$simple_submit")
[[ -n "$simple_raw" && -n "$simple_final" ]] || fail "simple submit missing scores"

echo "[PR17] simple_score_demo result" | tee -a "$LOG_FILE"
http_code=$(curl -sS -L -o "$simple_result" -w "%{http_code}" \
  -H "X-Anon-Id: ${SIMPLE_ANON_ID}" \
  "$API/api/v0.3/attempts/$simple_attempt_id/result" || true)
[[ "$http_code" == "200" ]] || fail "simple result failed (http=$http_code)"

echo "[PR17] simple_score_demo report" | tee -a "$LOG_FILE"
http_code=$(curl -sS -L -o "$simple_report" -w "%{http_code}" \
  -H "X-Anon-Id: ${SIMPLE_ANON_ID}" \
  "$API/api/v0.3/attempts/$simple_attempt_id/report" || true)
[[ "$http_code" == "200" ]] || fail "simple report failed (http=$http_code)"

simple_locked=$(jq -r '.locked // empty' "$simple_report")

# IQ Raven flow
raven_start="$RUN_DIR/curl_raven_start.json"
raven_submit="$RUN_DIR/curl_raven_submit.json"
raven_result="$RUN_DIR/curl_raven_result.json"
raven_report="$RUN_DIR/curl_raven_report.json"

echo "[PR17] iq_raven start" | tee -a "$LOG_FILE"
cat <<JSON > "$RUN_DIR/raven_start_payload.json"
{"scale_code":"IQ_RAVEN","anon_id":"${RAVEN_ANON_ID}"}
JSON
http_code=$(curl -sS -L -o "$raven_start" -w "%{http_code}" -X POST \
  -H 'Content-Type: application/json' \
  -H "X-Anon-Id: ${RAVEN_ANON_ID}" \
  -d @"$RUN_DIR/raven_start_payload.json" \
  "$API/api/v0.3/attempts/start" || true)
[[ "$http_code" == "200" ]] || fail "raven start failed (http=$http_code)"

raven_attempt_id=$(jq -r '.attempt_id // empty' "$raven_start")
[[ -n "$raven_attempt_id" ]] || fail "raven start missing attempt_id"

echo "[PR17] iq_raven submit" | tee -a "$LOG_FILE"
cat <<JSON > "$RUN_DIR/raven_submit_payload.json"
{
  "attempt_id": "$raven_attempt_id",
  "duration_ms": 20000,
  "answers": [
    {"question_id":"RAVEN_DEMO_1","code":"B"}
  ]
}
JSON
http_code=$(curl -sS -L -o "$raven_submit" -w "%{http_code}" -X POST \
  -H 'Content-Type: application/json' \
  -H "X-Anon-Id: ${RAVEN_ANON_ID}" \
  -d @"$RUN_DIR/raven_submit_payload.json" \
  "$API/api/v0.3/attempts/submit" || true)
[[ "$http_code" == "200" ]] || fail "raven submit failed (http=$http_code)"

raven_time_bonus=$(jq -r '.result.breakdown_json.time_bonus // empty' "$raven_submit")
raven_final=$(jq -r '.result.final_score // empty' "$raven_submit")
[[ -n "$raven_time_bonus" && -n "$raven_final" ]] || fail "raven submit missing fields"

echo "[PR17] iq_raven result" | tee -a "$LOG_FILE"
http_code=$(curl -sS -L -o "$raven_result" -w "%{http_code}" \
  -H "X-Anon-Id: ${RAVEN_ANON_ID}" \
  "$API/api/v0.3/attempts/$raven_attempt_id/result" || true)
[[ "$http_code" == "200" ]] || fail "raven result failed (http=$http_code)"

echo "[PR17] iq_raven report" | tee -a "$LOG_FILE"
http_code=$(curl -sS -L -o "$raven_report" -w "%{http_code}" \
  -H "X-Anon-Id: ${RAVEN_ANON_ID}" \
  "$API/api/v0.3/attempts/$raven_attempt_id/report" || true)
[[ "$http_code" == "200" ]] || fail "raven report failed (http=$http_code)"

cat <<SUMMARY > "$SUMMARY_FILE"
PR17 verify summary
- migrate: ok
- seed: ok (Pr16IqRavenDemoSeeder, Pr17SimpleScoreDemoSeeder)
- simple_score_demo raw_score: $simple_raw
- simple_score_demo final_score: $simple_final
- iq_raven time_bonus: $raven_time_bonus
- iq_raven final_score: $raven_final
- report locked (simple_score_demo): $simple_locked
- modified_files:
$(git diff --name-only)
- demo_packs:
  - content_packages/default/CN_MAINLAND/zh-CN/SIMPLE-SCORE-CN-v0.3.0-DEMO
  - content_packages/default/CN_MAINLAND/zh-CN/IQ-RAVEN-CN-v0.3.0-DEMO
SUMMARY

echo "[PR17] done" | tee -a "$LOG_FILE"
