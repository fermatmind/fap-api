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
require_cmd php

json_get() {
  local file_path="$1"
  local dot_path="$2"

  php -r '
    $filePath = $argv[1];
    $dotPath = $argv[2];

    if (!is_file($filePath)) {
        exit(1);
    }

    $raw = file_get_contents($filePath);
    if ($raw === false) {
        exit(1);
    }

    $payload = json_decode($raw, true);
    if (!is_array($payload)) {
        exit(1);
    }

    $value = $payload;
    foreach (explode(".", $dotPath) as $segment) {
        if ($segment === "") {
            continue;
        }

        if (!is_array($value) || !array_key_exists($segment, $value)) {
            exit(1);
        }

        $value = $value[$segment];
    }

    if ($value === null) {
        exit(1);
    }

    if (is_bool($value)) {
        echo $value ? "true" : "false";
        exit(0);
    }

    if (is_scalar($value)) {
        echo (string) $value;
        exit(0);
    }

    echo json_encode($value, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
  ' "$file_path" "$dot_path"
}

issue_anon_token() {
  local anon_id="$1"
  local raw token

  raw="$(
    ANON_ID="$anon_id" php -r '
      require "vendor/autoload.php";
      $app = require "bootstrap/app.php";
      $app->make(Illuminate\Contracts\Console\Kernel::class)->bootstrap();

      use Illuminate\Support\Facades\DB;
      use Illuminate\Support\Str;

      $anonId = trim((string) getenv("ANON_ID"));
      if ($anonId === "") {
          fwrite(STDERR, "anon_id_missing\n");
          exit(1);
      }

      $token = "fm_" . (string) Str::uuid();
      DB::table("fm_tokens")->insert([
          "token" => $token,
          "token_hash" => hash("sha256", $token),
          "user_id" => null,
          "anon_id" => $anonId,
          "org_id" => 0,
          "role" => "public",
          "expires_at" => now()->addDay(),
          "created_at" => now(),
          "updated_at" => now(),
      ]);

      echo $token;
    ' 2>>"$LOG_FILE" || true
  )"

  token="$(printf '%s' "$raw" | tr -d '\r' | awk 'NF{last=$0} END{gsub(/[[:space:]]/, "", last); print last}')"
  if [[ "$token" =~ ^fm_[0-9a-fA-F-]{36}$ ]]; then
    printf '%s' "$token"
    return 0
  fi

  echo "[PR17] invalid token output for anon_id=${anon_id}: $(printf '%s' "$raw" | head -c 240 | tr '\n' ' ')" | tee -a "$LOG_FILE" >&2
  return 1
}

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

simple_attempt_id="$(json_get "$simple_start" "attempt_id" || true)"
[[ -n "$simple_attempt_id" ]] || fail "simple start missing attempt_id"

simple_token="$(issue_anon_token "$SIMPLE_ANON_ID" || true)"
[[ "$simple_token" =~ ^fm_[0-9a-fA-F-]{36}$ ]] || fail "issue simple anon token failed"

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
  -H "Authorization: Bearer ${simple_token}" \
  -d @"$RUN_DIR/simple_submit_payload.json" \
  "$API/api/v0.3/attempts/submit" || true)
[[ "$http_code" == "200" ]] || fail "simple submit failed (http=$http_code)"

simple_raw="$(json_get "$simple_submit" "result.raw_score" || true)"
simple_final="$(json_get "$simple_submit" "result.final_score" || true)"
[[ -n "$simple_raw" && -n "$simple_final" ]] || fail "simple submit missing scores"

echo "[PR17] simple_score_demo result" | tee -a "$LOG_FILE"
http_code=$(curl -sS -L -o "$simple_result" -w "%{http_code}" \
  -H "X-Anon-Id: ${SIMPLE_ANON_ID}" \
  -H "Authorization: Bearer ${simple_token}" \
  "$API/api/v0.3/attempts/$simple_attempt_id/result" || true)
[[ "$http_code" == "200" ]] || fail "simple result failed (http=$http_code)"

echo "[PR17] simple_score_demo report" | tee -a "$LOG_FILE"
http_code=$(curl -sS -L -o "$simple_report" -w "%{http_code}" \
  -H "X-Anon-Id: ${SIMPLE_ANON_ID}" \
  -H "Authorization: Bearer ${simple_token}" \
  "$API/api/v0.3/attempts/$simple_attempt_id/report" || true)
[[ "$http_code" == "200" ]] || fail "simple report failed (http=$http_code)"

simple_locked="$(json_get "$simple_report" "locked" || true)"

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

raven_attempt_id="$(json_get "$raven_start" "attempt_id" || true)"
[[ -n "$raven_attempt_id" ]] || fail "raven start missing attempt_id"

raven_token="$(issue_anon_token "$RAVEN_ANON_ID" || true)"
[[ "$raven_token" =~ ^fm_[0-9a-fA-F-]{36}$ ]] || fail "issue raven anon token failed"

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
  -H "Authorization: Bearer ${raven_token}" \
  -d @"$RUN_DIR/raven_submit_payload.json" \
  "$API/api/v0.3/attempts/submit" || true)
[[ "$http_code" == "200" ]] || fail "raven submit failed (http=$http_code)"

raven_time_bonus="$(json_get "$raven_submit" "result.breakdown_json.time_bonus" || true)"
raven_final="$(json_get "$raven_submit" "result.final_score" || true)"
[[ -n "$raven_time_bonus" && -n "$raven_final" ]] || fail "raven submit missing fields"

echo "[PR17] iq_raven result" | tee -a "$LOG_FILE"
http_code=$(curl -sS -L -o "$raven_result" -w "%{http_code}" \
  -H "X-Anon-Id: ${RAVEN_ANON_ID}" \
  -H "Authorization: Bearer ${raven_token}" \
  "$API/api/v0.3/attempts/$raven_attempt_id/result" || true)
[[ "$http_code" == "200" ]] || fail "raven result failed (http=$http_code)"

echo "[PR17] iq_raven report" | tee -a "$LOG_FILE"
http_code=$(curl -sS -L -o "$raven_report" -w "%{http_code}" \
  -H "X-Anon-Id: ${RAVEN_ANON_ID}" \
  -H "Authorization: Bearer ${raven_token}" \
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
