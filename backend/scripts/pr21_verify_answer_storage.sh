#!/usr/bin/env bash
set -euo pipefail

export CI=true
export FAP_NONINTERACTIVE=1
export COMPOSER_NO_INTERACTION=1
export GIT_TERMINAL_PROMPT=0
export NO_COLOR=1
export SERVE_PORT="${SERVE_PORT:-1821}"
export ANSWER_ROWS_WRITE_MODE=on

SCRIPT_DIR="$(cd "$(dirname "${BASH_SOURCE[0]}")" && pwd)"
ROOT_DIR="$(cd "$SCRIPT_DIR/../.." && pwd)"
BACKEND_DIR="$ROOT_DIR/backend"

ART_DIR="$ROOT_DIR/backend/artifacts/pr21"
mkdir -p "$ART_DIR"
LOG_FILE="$ART_DIR/verify.log"
: > "$LOG_FILE"

PYTHON_BIN="python"
if ! command -v "$PYTHON_BIN" >/dev/null 2>&1; then
  PYTHON_BIN="python3"
fi
if ! command -v "$PYTHON_BIN" >/dev/null 2>&1; then
  echo "python not found" >> "$LOG_FILE"
  exit 1
fi

log() {
  echo "[$(date +'%Y-%m-%d %H:%M:%S')] $*" | tee -a "$LOG_FILE"
}

ensure_demo_scale() {
  local count
  cat <<'PHP' > /tmp/pr21_scale_check.php
<?php
$repo = getenv('REPO_DIR') ?: getcwd();
require $repo . '/backend/vendor/autoload.php';
$app = require $repo . '/backend/bootstrap/app.php';
$kernel = $app->make(Illuminate\Contracts\Console\Kernel::class);
$kernel->bootstrap();
echo (int) \Illuminate\Support\Facades\DB::table('scales_registry')
    ->where('code', 'DEMO_ANSWERS')
    ->count();
PHP
  count=$(REPO_DIR="$ROOT_DIR" php /tmp/pr21_scale_check.php 2>/dev/null || echo "0")
  if [[ "$count" == "0" ]]; then
    log "Seeding DEMO_ANSWERS scale"
    (
      cd "$BACKEND_DIR"
      php artisan db:seed --class=Pr21AnswerDemoSeeder
    ) >> "$LOG_FILE" 2>&1
  fi
}

USE_EMBEDDED=0
if ! php -r '$s=@stream_socket_server("tcp://127.0.0.1:'"$SERVE_PORT"'", $errno, $errstr); if($s){fclose($s); exit(0);} exit(1);' >/dev/null 2>&1; then
  USE_EMBEDDED=1
fi

if [[ "$USE_EMBEDDED" == "1" ]]; then
  log "Socket bind not permitted; using embedded HTTP kernel"
  cat <<'PHP' > /tmp/pr21_http.php
<?php
$repo = getenv('REPO_DIR') ?: getcwd();
$method = $argv[1] ?? 'GET';
$uri = $argv[2] ?? '/';
$payloadPath = $argv[3] ?? '';
$headersPath = $argv[4] ?? '';

require $repo . '/backend/vendor/autoload.php';
$app = require $repo . '/backend/bootstrap/app.php';
$kernel = $app->make(Illuminate\Contracts\Http\Kernel::class);
$kernel->bootstrap();

$headers = [];
if ($headersPath && file_exists($headersPath)) {
    $decoded = json_decode(file_get_contents($headersPath), true);
    $headers = is_array($decoded) ? $decoded : [];
}
$content = '';
if ($payloadPath && file_exists($payloadPath)) {
    $content = (string) file_get_contents($payloadPath);
}

$server = [
    'CONTENT_TYPE' => 'application/json',
    'HTTP_ACCEPT' => 'application/json',
];
foreach ($headers as $k => $v) {
    $key = 'HTTP_' . strtoupper(str_replace('-', '_', $k));
    $server[$key] = $v;
}

$request = Illuminate\Http\Request::create($uri, $method, [], [], [], $server, $content);
$request->headers->set('Content-Type', 'application/json');
$request->headers->set('Accept', 'application/json');
foreach ($headers as $k => $v) {
    $request->headers->set($k, $v);
}

$response = $kernel->handle($request);
echo $response->getContent();
$kernel->terminate($request, $response);
PHP
fi

cleanup() {
  if [[ -n "${SERVER_PID:-}" ]]; then
    kill "$SERVER_PID" >/dev/null 2>&1 || true
  fi
}
trap cleanup EXIT

if [[ "$USE_EMBEDDED" == "0" ]]; then
  log "Starting server on port ${SERVE_PORT}"
  (
    cd "$BACKEND_DIR"
    php artisan serve --host=127.0.0.1 --port="$SERVE_PORT"
  ) >> "$LOG_FILE" 2>&1 &
  SERVER_PID=$!

  log "Waiting for health"
  health_code=""
  for i in $(seq 1 30); do
    health_code=$(curl -s -o /dev/null -w "%{http_code}" "http://127.0.0.1:${SERVE_PORT}/api/v0.2/health" || true)
    if [[ "$health_code" == "200" ]]; then
      break
    fi
    sleep 0.5
  done
  if [[ "$health_code" != "200" ]]; then
    log "Health check failed: ${health_code}"
    exit 1
  fi
fi

ensure_demo_scale

log "POST /api/v0.3/attempts/start"
if [[ "$USE_EMBEDDED" == "1" ]]; then
  embedded_payload='{"scale_code":"DEMO_ANSWERS"}'
  embedded_headers='{}'
  payload_file=$(mktemp)
  headers_file=$(mktemp)
  printf '%s' "$embedded_payload" > "$payload_file"
  printf '%s' "$embedded_headers" > "$headers_file"
  REPO_DIR="$ROOT_DIR" php /tmp/pr21_http.php POST "/api/v0.3/attempts/start" "$payload_file" "$headers_file" > "$ART_DIR/curl_start.json"
  rm -f "$payload_file" "$headers_file"
else
  curl -sS -X POST "http://127.0.0.1:${SERVE_PORT}/api/v0.3/attempts/start" \
    -H "Content-Type: application/json" \
    -d '{"scale_code":"DEMO_ANSWERS"}' \
    > "$ART_DIR/curl_start.json"
fi

read -r ATTEMPT_ID RESUME_TOKEN < <(CURL_START_PATH="$ART_DIR/curl_start.json" "$PYTHON_BIN" - <<'PY'
import json
import os
path = os.environ.get('CURL_START_PATH', '')
with open(path,'r',encoding='utf-8') as f:
    data=json.load(f)
print(data.get('attempt_id',''), data.get('resume_token',''))
PY
)

if [[ -z "$ATTEMPT_ID" || -z "$RESUME_TOKEN" ]]; then
  log "Failed to parse attempt_id/resume_token"
  exit 1
fi

log "PUT progress seq=1"
if [[ "$USE_EMBEDDED" == "1" ]]; then
  embedded_payload='{"seq":1,"cursor":"page-1","duration_ms":1200,"answers":[{"question_id":"DEMO-SLIDER-1","question_type":"slider","question_index":0,"code":"3","answer":{"value":3}}]}'
  embedded_headers=$(printf '{"X-Resume-Token":"%s"}' "$RESUME_TOKEN")
  payload_file=$(mktemp)
  headers_file=$(mktemp)
  printf '%s' "$embedded_payload" > "$payload_file"
  printf '%s' "$embedded_headers" > "$headers_file"
  REPO_DIR="$ROOT_DIR" php /tmp/pr21_http.php PUT "/api/v0.3/attempts/${ATTEMPT_ID}/progress" "$payload_file" "$headers_file" >> "$LOG_FILE" 2>&1
  rm -f "$payload_file" "$headers_file"
else
  curl -sS -X PUT "http://127.0.0.1:${SERVE_PORT}/api/v0.3/attempts/${ATTEMPT_ID}/progress" \
    -H "Content-Type: application/json" \
    -H "X-Resume-Token: ${RESUME_TOKEN}" \
    -d '{
      "seq":1,
      "cursor":"page-1",
      "duration_ms":1200,
      "answers":[
        {"question_id":"DEMO-SLIDER-1","question_type":"slider","question_index":0,"code":"3","answer":{"value":3}}
      ]
    }' >> "$LOG_FILE" 2>&1
fi

log "PUT progress seq=2"
if [[ "$USE_EMBEDDED" == "1" ]]; then
  embedded_payload='{"seq":2,"cursor":"page-2","duration_ms":2400,"answers":[{"question_id":"DEMO-SLIDER-1","question_type":"slider","question_index":0,"code":"4","answer":{"value":4}},{"question_id":"DEMO-RANK-1","question_type":"rank_order","question_index":1,"code":"A>B>C","answer":{"order":["A","B","C"]}}]}'
  embedded_headers=$(printf '{"X-Resume-Token":"%s"}' "$RESUME_TOKEN")
  payload_file=$(mktemp)
  headers_file=$(mktemp)
  printf '%s' "$embedded_payload" > "$payload_file"
  printf '%s' "$embedded_headers" > "$headers_file"
  REPO_DIR="$ROOT_DIR" php /tmp/pr21_http.php PUT "/api/v0.3/attempts/${ATTEMPT_ID}/progress" "$payload_file" "$headers_file" >> "$LOG_FILE" 2>&1
  rm -f "$payload_file" "$headers_file"
else
  curl -sS -X PUT "http://127.0.0.1:${SERVE_PORT}/api/v0.3/attempts/${ATTEMPT_ID}/progress" \
    -H "Content-Type: application/json" \
    -H "X-Resume-Token: ${RESUME_TOKEN}" \
    -d '{
      "seq":2,
      "cursor":"page-2",
      "duration_ms":2400,
      "answers":[
        {"question_id":"DEMO-SLIDER-1","question_type":"slider","question_index":0,"code":"4","answer":{"value":4}},
        {"question_id":"DEMO-RANK-1","question_type":"rank_order","question_index":1,"code":"A>B>C","answer":{"order":["A","B","C"]}}
      ]
    }' >> "$LOG_FILE" 2>&1
fi

log "GET progress"
if [[ "$USE_EMBEDDED" == "1" ]]; then
  embedded_payload=''
  embedded_headers=$(printf '{"X-Resume-Token":"%s"}' "$RESUME_TOKEN")
  payload_file=$(mktemp)
  headers_file=$(mktemp)
  printf '%s' "$embedded_payload" > "$payload_file"
  printf '%s' "$embedded_headers" > "$headers_file"
  REPO_DIR="$ROOT_DIR" php /tmp/pr21_http.php GET "/api/v0.3/attempts/${ATTEMPT_ID}/progress" "$payload_file" "$headers_file" > "$ART_DIR/curl_progress_get.json"
  rm -f "$payload_file" "$headers_file"
else
  curl -sS "http://127.0.0.1:${SERVE_PORT}/api/v0.3/attempts/${ATTEMPT_ID}/progress" \
    -H "X-Resume-Token: ${RESUME_TOKEN}" \
    > "$ART_DIR/curl_progress_get.json"
fi

CURL_PROGRESS_PATH="$ART_DIR/curl_progress_get.json" "$PYTHON_BIN" - <<'PY'
import json, sys
import os
path = os.environ.get('CURL_PROGRESS_PATH', '')
with open(path,'r',encoding='utf-8') as f:
    data=json.load(f)
if data.get('answered_count') != 2 or data.get('cursor') != 'page-2':
    print('progress assertion failed', data)
    sys.exit(1)
PY

log "POST submit"
if [[ "$USE_EMBEDDED" == "1" ]]; then
  embedded_payload='{"attempt_id":"'"${ATTEMPT_ID}"'","duration_ms":3600,"answers":[{"question_id":"DEMO-SLIDER-1","question_type":"slider","question_index":0,"code":"4","answer":{"value":4}},{"question_id":"DEMO-RANK-1","question_type":"rank_order","question_index":1,"code":"A>B>C","answer":{"order":["A","B","C"]}},{"question_id":"DEMO-TEXT-1","question_type":"open_text","question_index":2,"code":"TEXT","answer":{"text":"demo"}}]}'
  embedded_headers='{}'
  payload_file=$(mktemp)
  headers_file=$(mktemp)
  printf '%s' "$embedded_payload" > "$payload_file"
  printf '%s' "$embedded_headers" > "$headers_file"
  REPO_DIR="$ROOT_DIR" php /tmp/pr21_http.php POST "/api/v0.3/attempts/submit" "$payload_file" "$headers_file" > "$ART_DIR/curl_submit.json"
  rm -f "$payload_file" "$headers_file"
else
  curl -sS -X POST "http://127.0.0.1:${SERVE_PORT}/api/v0.3/attempts/submit" \
    -H "Content-Type: application/json" \
    -d '{
      "attempt_id":"'"${ATTEMPT_ID}"'",
      "duration_ms":3600,
      "answers":[
        {"question_id":"DEMO-SLIDER-1","question_type":"slider","question_index":0,"code":"4","answer":{"value":4}},
        {"question_id":"DEMO-RANK-1","question_type":"rank_order","question_index":1,"code":"A>B>C","answer":{"order":["A","B","C"]}},
        {"question_id":"DEMO-TEXT-1","question_type":"open_text","question_index":2,"code":"TEXT","answer":{"text":"demo"}}
      ]
    }' \
    > "$ART_DIR/curl_submit.json"
fi

CURL_SUBMIT_PATH="$ART_DIR/curl_submit.json" "$PYTHON_BIN" - <<'PY'
import json, sys
import os
path = os.environ.get('CURL_SUBMIT_PATH', '')
with open(path,'r',encoding='utf-8') as f:
    data=json.load(f)
if not data.get('ok'):
    print('submit failed', data)
    sys.exit(1)
PY

log "POST submit (idempotent)"
if [[ "$USE_EMBEDDED" == "1" ]]; then
  embedded_payload='{"attempt_id":"'"${ATTEMPT_ID}"'","duration_ms":3600,"answers":[{"question_id":"DEMO-SLIDER-1","question_type":"slider","question_index":0,"code":"4","answer":{"value":4}},{"question_id":"DEMO-RANK-1","question_type":"rank_order","question_index":1,"code":"A>B>C","answer":{"order":["A","B","C"]}},{"question_id":"DEMO-TEXT-1","question_type":"open_text","question_index":2,"code":"TEXT","answer":{"text":"demo"}}]}'
  embedded_headers='{}'
  payload_file=$(mktemp)
  headers_file=$(mktemp)
  printf '%s' "$embedded_payload" > "$payload_file"
  printf '%s' "$embedded_headers" > "$headers_file"
  REPO_DIR="$ROOT_DIR" php /tmp/pr21_http.php POST "/api/v0.3/attempts/submit" "$payload_file" "$headers_file" > "$ART_DIR/curl_submit_dup.json"
  rm -f "$payload_file" "$headers_file"
else
  curl -sS -X POST "http://127.0.0.1:${SERVE_PORT}/api/v0.3/attempts/submit" \
    -H "Content-Type: application/json" \
    -d '{
      "attempt_id":"'"${ATTEMPT_ID}"'",
      "duration_ms":3600,
      "answers":[
        {"question_id":"DEMO-SLIDER-1","question_type":"slider","question_index":0,"code":"4","answer":{"value":4}},
        {"question_id":"DEMO-RANK-1","question_type":"rank_order","question_index":1,"code":"A>B>C","answer":{"order":["A","B","C"]}},
        {"question_id":"DEMO-TEXT-1","question_type":"open_text","question_index":2,"code":"TEXT","answer":{"text":"demo"}}
      ]
    }' \
    > "$ART_DIR/curl_submit_dup.json"
fi

CURL_SUBMIT_DUP_PATH="$ART_DIR/curl_submit_dup.json" "$PYTHON_BIN" - <<'PY'
import json, sys
import os
path = os.environ.get('CURL_SUBMIT_DUP_PATH', '')
with open(path,'r',encoding='utf-8') as f:
    data=json.load(f)
if not data.get('ok') or data.get('idempotent') is not True:
    print('idempotent submit failed', data)
    sys.exit(1)
PY

log "Archive command"
(
  cd "$BACKEND_DIR"
  php artisan fap:archive:cold-data --before=2000-01-01
) >> "$LOG_FILE" 2>&1

log "DB assertions"
cat <<'PHP' > /tmp/pr21_db_assert.php
<?php
$repo = getenv('REPO_DIR') ?: getcwd();
require $repo . '/backend/vendor/autoload.php';
$app = require $repo . '/backend/bootstrap/app.php';
$kernel = $app->make(Illuminate\Contracts\Console\Kernel::class);
$kernel->bootstrap();

$attemptId = getenv('ATTEMPT_ID') ?: '';
$expectedRows = (int) (getenv('EXPECTED_ROWS') ?: 0);

$answerSets = \Illuminate\Support\Facades\DB::table('attempt_answer_sets')->where('attempt_id', $attemptId)->count();
$answerRows = \Illuminate\Support\Facades\DB::table('attempt_answer_rows')->where('attempt_id', $attemptId)->count();
$audits = \Illuminate\Support\Facades\DB::table('archive_audits')->count();

$payload = [
    'attempt_id' => $attemptId,
    'answer_sets' => $answerSets,
    'answer_rows' => $answerRows,
    'answer_rows_expected' => $expectedRows,
    'answer_rows_ok' => ($expectedRows > 0 ? $answerRows === $expectedRows : null),
    'archive_audits' => $audits,
];

echo json_encode($payload, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
PHP

REPO_DIR="$ROOT_DIR" ATTEMPT_ID="$ATTEMPT_ID" EXPECTED_ROWS=3 php /tmp/pr21_db_assert.php > "$ART_DIR/db_assertions.json"

DB_ASSERT_PATH="$ART_DIR/db_assertions.json" "$PYTHON_BIN" - <<'PY'
import json, sys
import os
path = os.environ.get('DB_ASSERT_PATH', '')
with open(path,'r',encoding='utf-8') as f:
    data=json.load(f)
if data.get('answer_sets') != 1 or data.get('answer_rows') != 3 or data.get('archive_audits',0) < 1:
    print('db assertion failed', data)
    sys.exit(1)
PY

log "Done"
