#!/usr/bin/env bash
# Usage: PORT=18020 bash scripts/pr12_verify_ai_insights.sh
# Artifacts: backend/artifacts/pr12/
set -euo pipefail

require_cmd() {
  command -v "$1" >/dev/null 2>&1 || {
    echo "[FAIL] missing command: $1" >&2
    exit 2
  }
}

SCRIPT_DIR="$(cd "$(dirname "${BASH_SOURCE[0]}")" && pwd)"
BACKEND_DIR="$(cd "$SCRIPT_DIR/.." && pwd)"
REPO_DIR="$(cd "$BACKEND_DIR/.." && pwd)"

RUN_DIR="$BACKEND_DIR/artifacts/pr12"
LOG_DIR="$RUN_DIR/logs"
mkdir -p "$LOG_DIR"
export XDG_CONFIG_HOME="$RUN_DIR/.xdg"
mkdir -p "$XDG_CONFIG_HOME"

SERVER_LOG="$LOG_DIR/server.log"
SUMMARY="$RUN_DIR/summary.txt"

PAYLOAD_JSON="$RUN_DIR/attempt_payload.json"
ATTEMPT_JSON="$RUN_DIR/attempt.json"
INSIGHT_REQ_JSON="$RUN_DIR/insight_request.json"
INSIGHT_CREATE_JSON="$RUN_DIR/insight_create.json"
INSIGHT_SHOW_JSON="$RUN_DIR/insight_show.json"
INSIGHT_FEEDBACK_JSON="$RUN_DIR/insight_feedback.json"
INSIGHT_CREATE_BREAKER_JSON="$RUN_DIR/insight_create_breaker.json"

require_cmd curl
require_cmd jq
require_cmd php

USE_INTERNAL=0

if [[ -z "${DB_CONNECTION:-}" ]]; then
  export DB_CONNECTION=sqlite
fi
if [[ -z "${DB_DATABASE:-}" ]]; then
  export DB_DATABASE="$BACKEND_DIR/database/database.sqlite"
fi

PHP_BIND_OK=1
if ! php -r '$s=@stream_socket_server("tcp://127.0.0.1:0",$e,$s); if ($s){fclose($s); echo "OK";} else {echo "FAIL";}' | grep -q "OK"; then
  PHP_BIND_OK=0
  echo "[WARN] php cannot bind local ports; will skip artisan serve" >&2
fi

REDIS_SOCKET_OK=1
if ! php -r '$fp=@fsockopen("127.0.0.1",6379,$e,$s,1); if ($fp){fclose($fp); echo "OK";} else {echo "FAIL";}' | grep -q "OK"; then
  REDIS_SOCKET_OK=0
  echo "[WARN] php cannot open redis socket; will bypass breaker for main flow" >&2
fi
AI_FAIL_OPEN_ENV=""
if [[ "$REDIS_SOCKET_OK" -eq 0 ]]; then
  AI_FAIL_OPEN_ENV="AI_FAIL_OPEN_WHEN_REDIS_DOWN=true"
fi

port_in_use() {
  local port="$1"
  if command -v lsof >/dev/null 2>&1; then
    lsof -iTCP:"$port" -sTCP:LISTEN -P >/dev/null 2>&1
    return $?
  fi
  if command -v nc >/dev/null 2>&1; then
    nc -z 127.0.0.1 "$port" >/dev/null 2>&1
    return $?
  fi
  return 1
}

select_port() {
  local base_port="$1"
  local end_port=18039
  local port
  for port in $(seq "$base_port" "$end_port"); do
    if port_in_use "$port"; then
      echo "[PR12] port $port in use. If needed: lsof -iTCP:$port -sTCP:LISTEN -P" >&2
      continue
    fi
    echo "$port"
    return 0
  done
  return 1
}

wait_health() {
  local retries=12
  for i in $(seq 1 "$retries"); do
    if curl -fsS "$API/health" >/dev/null 2>&1; then
      return 0
    fi
    sleep 0.4
  done
  return 1
}

start_server() {
  local extra_env="$1"
  echo "[PR12] starting server on :$PORT ${extra_env}"
  (
    cd "$BACKEND_DIR"
    env $extra_env php artisan serve --host=127.0.0.1 --port=$PORT >"$SERVER_LOG" 2>&1
  ) &
  SERVER_PID=$!

  if ! wait_health; then
    echo "[WARN] server not healthy on $API" >&2
    return 1
  fi
  return 0
}

stop_server() {
  if [[ -n "${SERVER_PID:-}" ]]; then
    kill "$SERVER_PID" >/dev/null 2>&1 || true
    unset SERVER_PID
  fi
}

cleanup() {
  local code=$?
  stop_server
  exit $code
}
trap cleanup EXIT

PORT_BASE="${PORT:-18020}"
PORT=""
SERVER_OK=0
if [[ "$PHP_BIND_OK" -eq 1 ]]; then
  for candidate in $(seq "$PORT_BASE" 18039); do
    if port_in_use "$candidate"; then
      echo "[PR12] port $candidate in use. If needed: lsof -iTCP:$candidate -sTCP:LISTEN -P" >&2
      continue
    fi

    PORT="$candidate"
    API="http://127.0.0.1:${PORT}/api/v0.2"

    if start_server ""; then
      SERVER_OK=1
      break
    fi

    stop_server
  done
fi

if [[ "$SERVER_OK" -ne 1 ]]; then
  USE_INTERNAL=1
  echo "[WARN] unable to start php artisan serve; falling back to internal requests" >&2
fi

http_request() {
  local method="$1"
  local path="$2"
  local body_file="$3"
  local out_file="$4"
  local extra_env="${5:-}"
  local req_path="$path"

  if [[ "$USE_INTERNAL" -eq 1 ]]; then
    if [[ "$req_path" != /api/* ]]; then
      req_path="/api/v0.2${req_path}"
    fi
    local cmd=(php -r '
require "vendor/autoload.php";
$app = require "bootstrap/app.php";
$kernel = $app->make(Illuminate\Contracts\Http\Kernel::class);
$method = getenv("REQ_METHOD") ?: "GET";
$path = getenv("REQ_PATH") ?: "/";
$bodyPath = getenv("REQ_BODY") ?: "";
$outPath = getenv("REQ_OUT") ?: "";
$body = $bodyPath !== "" && file_exists($bodyPath) ? file_get_contents($bodyPath) : "";
$request = Illuminate\Http\Request::create($path, $method, [], [], [], [], $body);
$request->headers->set("Content-Type", "application/json");
$response = $kernel->handle($request);
if ($outPath !== "") {
  file_put_contents($outPath, $response->getContent());
}
echo (string) $response->getStatusCode();
$kernel->terminate($request, $response);
');
    if [[ -n "$extra_env" ]]; then
      (
        cd "$BACKEND_DIR"
        REQ_METHOD="$method" REQ_PATH="$req_path" REQ_BODY="$body_file" REQ_OUT="$out_file" \
          env $extra_env "${cmd[@]}"
      )
    else
      (
        cd "$BACKEND_DIR"
        REQ_METHOD="$method" REQ_PATH="$req_path" REQ_BODY="$body_file" REQ_OUT="$out_file" \
          "${cmd[@]}"
      )
    fi
    return 0
  fi

  local url="$API$path"
  if [[ -n "$body_file" ]]; then
    curl -sS -o "$out_file" -w "%{http_code}" \
      -H "Content-Type: application/json" \
      -d @"$body_file" \
      "$url"
  else
    curl -sS -o "$out_file" -w "%{http_code}" "$url"
  fi
}

echo "[PR12] artifacts: $RUN_DIR"

PACK_DIR="$REPO_DIR/content_packages/default/CN_MAINLAND/zh-CN/MBTI-CN-v0.2.1-TEST"
QUESTIONS_JSON="$PACK_DIR/questions.json"
if [[ ! -f "$QUESTIONS_JSON" ]]; then
  echo "[FAIL] questions.json not found: $QUESTIONS_JSON" >&2
  exit 5
fi

ANON_ID="anon_pr12_$(date +%s)"

QUESTIONS_JSON="$QUESTIONS_JSON" PAYLOAD_JSON="$PAYLOAD_JSON" ANON_ID="$ANON_ID" php -r '
$path = getenv("QUESTIONS_JSON");
$raw = file_get_contents($path);
$doc = json_decode($raw, true);
$items = isset($doc["items"]) ? $doc["items"] : $doc;
$answers = [];
foreach ($items as $q) {
    $answers[] = ["question_id" => $q["question_id"], "code" => "C"];
}
$payload = [
  "anon_id" => getenv("ANON_ID"),
  "scale_code" => "MBTI",
  "scale_version" => "v0.2.1-TEST",
  "question_count" => count($answers),
  "answers" => $answers,
  "client_platform" => "web",
  "region" => "CN_MAINLAND",
  "locale" => "zh-CN"
];
file_put_contents(getenv("PAYLOAD_JSON"), json_encode($payload, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE));
'

echo "[PR12] POST attempt"
http_attempt=$(http_request "POST" "/attempts" "$PAYLOAD_JSON" "$ATTEMPT_JSON" || true)
if [[ "$http_attempt" != "200" && "$http_attempt" != "201" ]]; then
  echo "[FAIL] attempt HTTP=$http_attempt" >&2
  head -c 400 "$ATTEMPT_JSON" >&2 || true
  exit 6
fi

ATTEMPT_ID=$(jq -r '.attempt_id // .id // empty' "$ATTEMPT_JSON")
if [[ -z "$ATTEMPT_ID" || "$ATTEMPT_ID" == "null" ]]; then
  echo "[FAIL] attempt id missing" >&2
  exit 7
fi

PERIOD_END=$(php -r 'echo date("Y-m-d");')
PERIOD_START=$(php -r 'echo date("Y-m-d", strtotime("-7 days"));')

cat > "$INSIGHT_REQ_JSON" <<JSON
{"period_type":"week","period_start":"$PERIOD_START","period_end":"$PERIOD_END","anon_id":"$ANON_ID"}
JSON

echo "[PR12] POST /insights/generate"
http_insight=$(http_request "POST" "/insights/generate" "$INSIGHT_REQ_JSON" "$INSIGHT_CREATE_JSON" "$AI_FAIL_OPEN_ENV" || true)

if [[ "$http_insight" != "200" && "$http_insight" != "201" ]]; then
  echo "[FAIL] generate HTTP=$http_insight" >&2
  head -c 400 "$INSIGHT_CREATE_JSON" >&2 || true
  exit 8
fi

INSIGHT_ID=$(jq -r '.id // empty' "$INSIGHT_CREATE_JSON")
if [[ -z "$INSIGHT_ID" || "$INSIGHT_ID" == "null" ]]; then
  echo "[FAIL] insight id missing" >&2
  exit 9
fi

STATUS=""
for i in $(seq 1 30); do
  http_request "GET" "/insights/$INSIGHT_ID" "" "$INSIGHT_SHOW_JSON" >/dev/null || true
  STATUS=$(jq -r '.status // empty' "$INSIGHT_SHOW_JSON")
  if [[ "$STATUS" == "succeeded" ]]; then
    break
  fi
  if [[ "$STATUS" == "failed" ]]; then
    echo "[FAIL] insight failed" >&2
    cat "$INSIGHT_SHOW_JSON" >&2
    exit 10
  fi

  (
    cd "$BACKEND_DIR"
    if [[ -n "$AI_FAIL_OPEN_ENV" ]]; then
      env $AI_FAIL_OPEN_ENV php artisan queue:work --once --queue=insights >/dev/null 2>&1 || true
    else
      php artisan queue:work --once --queue=insights >/dev/null 2>&1 || true
    fi
  )
  sleep 1

done

if [[ "$STATUS" != "succeeded" ]]; then
  echo "[FAIL] insight status timeout ($STATUS)" >&2
  cat "$INSIGHT_SHOW_JSON" >&2
  exit 11
fi

jq -e '.output_json.summary and (.output_json.strengths|type=="array") and (.output_json.risks|type=="array") and (.output_json.actions|type=="array") and (.output_json.disclaimer|type=="string")' \
  "$INSIGHT_SHOW_JSON" >/dev/null || {
    echo "[FAIL] output_json schema invalid" >&2
    exit 12
  }

jq -e '.evidence_json|type=="array"' "$INSIGHT_SHOW_JSON" >/dev/null || {
  echo "[FAIL] evidence_json not array" >&2
  exit 13
}

if [[ $(jq '.evidence_json | length' "$INSIGHT_SHOW_JSON") -gt 0 ]]; then
  jq -e '.evidence_json[0].type and .evidence_json[0].source and .evidence_json[0].pointer and .evidence_json[0].quote and .evidence_json[0].hash and .evidence_json[0].created_at' \
    "$INSIGHT_SHOW_JSON" >/dev/null || {
      echo "[FAIL] evidence_json schema invalid" >&2
      exit 14
    }
fi

TOKEN_IN=$(jq -r '.tokens_in // 0' "$INSIGHT_SHOW_JSON")
TOKEN_OUT=$(jq -r '.tokens_out // 0' "$INSIGHT_SHOW_JSON")
COST_USD=$(jq -r '.cost_usd // 0' "$INSIGHT_SHOW_JSON")

cat > "$INSIGHT_FEEDBACK_JSON" <<JSON
{"rating":4,"reason":"helpful","comment":"PR12 verify"}
JSON

http_feedback=$(http_request "POST" "/insights/$INSIGHT_ID/feedback" "$INSIGHT_FEEDBACK_JSON" "$RUN_DIR/feedback_resp.json" || true)
if [[ "$http_feedback" != "200" && "$http_feedback" != "201" ]]; then
  echo "[FAIL] feedback HTTP=$http_feedback" >&2
  head -c 400 "$RUN_DIR/feedback_resp.json" >&2 || true
  exit 15
fi

FEEDBACK_COUNT=$(cd "$BACKEND_DIR" && php artisan tinker --execute="echo DB::table('ai_insight_feedback')->where('insight_id', '$INSIGHT_ID')->count();")
if [[ "${FEEDBACK_COUNT:-0}" -lt 1 ]]; then
  echo "[FAIL] feedback not stored" >&2
  exit 16
fi

BREAKER_CODE=""
if [[ "$REDIS_SOCKET_OK" -eq 1 ]]; then
  if [[ "$USE_INTERNAL" -eq 0 ]]; then
    stop_server
    start_server "AI_DAILY_TOKENS=1 AI_BREAKER_ENABLED=true AI_INSIGHTS_ENABLED=true AI_ENABLED=true"
  fi

  http_breaker=$(http_request "POST" "/insights/generate" "$INSIGHT_REQ_JSON" "$INSIGHT_CREATE_BREAKER_JSON" "AI_DAILY_TOKENS=1 AI_BREAKER_ENABLED=true AI_INSIGHTS_ENABLED=true AI_ENABLED=true" || true)

  BREAKER_CODE=$(jq -r '.error_code // empty' "$INSIGHT_CREATE_BREAKER_JSON")
  if [[ "$http_breaker" == "200" || "$http_breaker" == "201" ]]; then
    echo "[FAIL] breaker test should fail but succeeded" >&2
    cat "$INSIGHT_CREATE_BREAKER_JSON" >&2
    exit 17
  fi

  if [[ "$BREAKER_CODE" != "AI_BUDGET_EXCEEDED" ]]; then
    echo "[FAIL] breaker error_code mismatch: $BREAKER_CODE" >&2
    cat "$INSIGHT_CREATE_BREAKER_JSON" >&2
    exit 18
  fi
else
  BREAKER_CODE="SKIPPED_REDIS_SOCKET_BLOCKED"
  echo "[WARN] breaker test skipped: php socket blocked" >&2
fi

INPUT_HASH=$(PERIOD_TYPE=week PERIOD_START="$PERIOD_START" PERIOD_END="$PERIOD_END" \
ANON_ID="$ANON_ID" PROMPT_VERSION="v1.0.0" PROVIDER="mock" MODEL="mock-model" \
php -r '
$payload = [
  "period_type" => getenv("PERIOD_TYPE"),
  "period_start" => getenv("PERIOD_START"),
  "period_end" => getenv("PERIOD_END"),
  "user_id" => "",
  "anon_id" => getenv("ANON_ID"),
  "prompt_version" => getenv("PROMPT_VERSION"),
  "provider" => getenv("PROVIDER"),
  "model" => getenv("MODEL"),
];
$hash = hash("sha256", json_encode($payload, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES));
echo $hash;
')

CLEAN_JSON=$(cd "$BACKEND_DIR" && php artisan tinker --execute="
\$ids = DB::table('ai_insights')->where('input_hash', '$INPUT_HASH')->pluck('id');
\$feedbackDeleted = 0;
if (count(\$ids) > 0) {
  \$feedbackDeleted = DB::table('ai_insight_feedback')->whereIn('insight_id', \$ids)->delete();
}
\$insightsDeleted = DB::table('ai_insights')->where('input_hash', '$INPUT_HASH')->delete();

echo json_encode(['feedback_deleted' => \$feedbackDeleted, 'insights_deleted' => \$insightsDeleted]);")

CLEAN_FEEDBACK=$(echo "$CLEAN_JSON" | jq -r '.feedback_deleted // 0')
CLEAN_INSIGHTS=$(echo "$CLEAN_JSON" | jq -r '.insights_deleted // 0')

REDIS_DEL=""
if [[ "$REDIS_SOCKET_OK" -eq 1 ]]; then
  DAY_KEY="ai:budget:day:$(date +%F):mock:mock-model:anon:$ANON_ID"
  MONTH_KEY="ai:budget:month:$(date +%Y-%m):mock:mock-model:anon:$ANON_ID"

  REDIS_DEL=$(cd "$BACKEND_DIR" && php artisan tinker --execute="
\$redis = app('redis');
\$deleted = \$redis->del('$DAY_KEY', '$MONTH_KEY');
echo (string) \$deleted;")
else
  REDIS_DEL="SKIPPED_SOCKET_BLOCKED"
fi

{
  MODE="server"
  if [[ "$USE_INTERNAL" -eq 1 ]]; then
    MODE="internal"
  fi
  echo "insight_id=$INSIGHT_ID"
  echo "attempt_id=$ATTEMPT_ID"
  echo "tokens_in=$TOKEN_IN"
  echo "tokens_out=$TOKEN_OUT"
  echo "cost_usd=$COST_USD"
  echo "breaker_test=$BREAKER_CODE"
  echo "port=${PORT:-internal}"
  echo "mode=$MODE"
  echo "cleanup_feedback_deleted=$CLEAN_FEEDBACK"
  echo "cleanup_insights_deleted=$CLEAN_INSIGHTS"
  echo "cleanup_redis_deleted=$REDIS_DEL"
  echo "artifacts=$RUN_DIR"
} > "$SUMMARY"

echo "[PR12] done. summary: $SUMMARY"
