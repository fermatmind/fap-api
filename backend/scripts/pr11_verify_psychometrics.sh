#!/usr/bin/env bash
# Usage: PORT=18111 bash scripts/pr11_verify_psychometrics.sh
# Artifacts: backend/artifacts/pr11_psychometrics
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

RUN_DIR="$BACKEND_DIR/artifacts/pr11_psychometrics"
LOG_DIR="$RUN_DIR/logs"
mkdir -p "$LOG_DIR"

PORT="${PORT:-18111}"
API="http://127.0.0.1:${PORT}/api/v0.3"

SERVER_LOG="$LOG_DIR/server.log"
SUMMARY="$RUN_DIR/summary.txt"

PAYLOAD_JSON="$RUN_DIR/payload.json"
ATTEMPT_A_JSON="$RUN_DIR/attempt_a.json"
ATTEMPT_B_JSON="$RUN_DIR/attempt_b.json"
STATS_A_JSON="$RUN_DIR/stats_a.json"
STATS_A_AFTER_JSON="$RUN_DIR/stats_a_after.json"
STATS_B_JSON="$RUN_DIR/stats_b.json"
QUALITY_A_JSON="$RUN_DIR/quality_a.json"
REPORT_A_JSON="$RUN_DIR/report_a.json"

mkdir -p "$RUN_DIR"

require_cmd curl
require_cmd jq
require_cmd php
require_cmd shasum

cleanup() {
  local code=$?
  if [[ -n "${SERVER_PID:-}" ]]; then
    kill "$SERVER_PID" >/dev/null 2>&1 || true
  fi
  exit $code
}
trap cleanup EXIT

echo "[PR11] artifacts: $RUN_DIR"

if curl -fsS "$API/health" >/dev/null 2>&1; then
  echo "[PR11] server already running on :$PORT"
else
  echo "[PR11] starting server on :$PORT"
  (
    cd "$BACKEND_DIR"
    php artisan serve --host=127.0.0.1 --port=$PORT >"$SERVER_LOG" 2>&1
  ) &
  SERVER_PID=$!

  for i in {1..20}; do
    if curl -fsS "$API/health" >/dev/null 2>&1; then
      break
    fi
    sleep 0.5
  done

  curl -fsS "$API/health" >/dev/null || {
    echo "[FAIL] server not healthy on $API" >&2
    exit 3
  }
fi

PACK_DIR="$REPO_DIR/content_packages/default/CN_MAINLAND/zh-CN/MBTI-CN-v0.2.1-TEST"
QUESTIONS_JSON="$PACK_DIR/questions.json"

if [[ ! -f "$QUESTIONS_JSON" ]]; then
  echo "[FAIL] questions.json not found: $QUESTIONS_JSON" >&2
  exit 4
fi

QUESTIONS_JSON="$QUESTIONS_JSON" PAYLOAD_JSON="$PAYLOAD_JSON" php -r '
$path = getenv("QUESTIONS_JSON");
$raw = file_get_contents($path);
$doc = json_decode($raw, true);
$items = isset($doc["items"]) ? $doc["items"] : $doc;
$answers = [];
foreach ($items as $q) {
    $answers[] = ["question_id" => $q["question_id"], "code" => "C"];
}
$payload = [
  "anon_id" => "anon_pr11",
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

echo "[PR11] POST attempt A"
http_a=$(curl -sS -o "$ATTEMPT_A_JSON" -w "%{http_code}" \
  -H "Content-Type: application/json" \
  -d @"$PAYLOAD_JSON" \
  "$API/attempts" || true)
if [[ "$http_a" != "200" && "$http_a" != "201" ]]; then
  echo "[FAIL] attempt A HTTP=$http_a" >&2
  head -c 400 "$ATTEMPT_A_JSON" >&2 || true
  exit 5
fi

ATTEMPT_A_ID=$(jq -r '.attempt_id // .id // empty' "$ATTEMPT_A_JSON")
if [[ -z "$ATTEMPT_A_ID" || "$ATTEMPT_A_ID" == "null" ]]; then
  echo "[FAIL] attempt A id missing" >&2
  exit 6
fi

echo "[PR11] GET stats/quality/report for A"
curl -fsS "$API/attempts/$ATTEMPT_A_ID/stats" >"$STATS_A_JSON"
curl -fsS "$API/attempts/$ATTEMPT_A_ID/quality" >"$QUALITY_A_JSON"
curl -fsS "$API/attempts/$ATTEMPT_A_ID/report?include=psychometrics" >"$REPORT_A_JSON"

NORM_A_VER=$(jq -r '.norm.version // empty' "$STATS_A_JSON")
NORM_A_ID=$(jq -r '.norm.norm_id // empty' "$STATS_A_JSON")
HASH_A=$(jq -c '.stats' "$STATS_A_JSON" | shasum -a 256 | awk '{print $1}')

if [[ -z "$NORM_A_VER" ]]; then
  echo "[FAIL] norm version missing in stats A" >&2
  exit 7
fi

NEXT_VER="1.0.1"

echo "[PR11] insert new norms version ($NEXT_VER)"
(
  cd "$BACKEND_DIR"
  php artisan tinker --execute="DB::table('scale_norms_versions')->insert([\
    'id' => (string) \\Illuminate\\Support\\Str::uuid(),\
    'scale_code' => 'MBTI',\
    'norm_id' => '$NORM_A_ID',\
    'region' => 'CN_MAINLAND',\
    'locale' => 'zh-CN',\
    'version' => '$NEXT_VER',\
    'checksum' => 'pr11-demo',\
    'meta_json' => json_encode(['source' => 'pr11_verify']),\
    'created_at' => now()\
  ]);"
) >/dev/null

echo "[PR11] re-fetch stats A (non-drift)"
curl -fsS "$API/attempts/$ATTEMPT_A_ID/stats" >"$STATS_A_AFTER_JSON"

NORM_A_VER_AFTER=$(jq -r '.norm.version // empty' "$STATS_A_AFTER_JSON")
HASH_A_AFTER=$(jq -c '.stats' "$STATS_A_AFTER_JSON" | shasum -a 256 | awk '{print $1}')

if [[ "$HASH_A" != "$HASH_A_AFTER" ]]; then
  echo "[FAIL] non-drift check failed: stats hash changed" >&2
  exit 8
fi

if [[ "$NORM_A_VER" != "$NORM_A_VER_AFTER" ]]; then
  echo "[FAIL] non-drift check failed: norm_version changed" >&2
  exit 9
fi

echo "[PR11] POST attempt B"
http_b=$(curl -sS -o "$ATTEMPT_B_JSON" -w "%{http_code}" \
  -H "Content-Type: application/json" \
  -d @"$PAYLOAD_JSON" \
  "$API/attempts" || true)
if [[ "$http_b" != "200" && "$http_b" != "201" ]]; then
  echo "[FAIL] attempt B HTTP=$http_b" >&2
  head -c 400 "$ATTEMPT_B_JSON" >&2 || true
  exit 10
fi

ATTEMPT_B_ID=$(jq -r '.attempt_id // .id // empty' "$ATTEMPT_B_JSON")
if [[ -z "$ATTEMPT_B_ID" || "$ATTEMPT_B_ID" == "null" ]]; then
  echo "[FAIL] attempt B id missing" >&2
  exit 11
fi

curl -fsS "$API/attempts/$ATTEMPT_B_ID/stats" >"$STATS_B_JSON"
NORM_B_VER=$(jq -r '.norm.version // empty' "$STATS_B_JSON")

if [[ "$NORM_B_VER" != "$NEXT_VER" ]]; then
  echo "[FAIL] new attempt should use new norm_version ($NEXT_VER), got=$NORM_B_VER" >&2
  exit 12
fi

{
  echo "[PR11] attempt A: $ATTEMPT_A_ID"
  echo "[PR11] attempt B: $ATTEMPT_B_ID"
  echo "[PR11] norm A version: $NORM_A_VER"
  echo "[PR11] norm B version: $NORM_B_VER"
  echo "[PR11] stats hash A: $HASH_A"
  echo "[PR11] stats hash A after: $HASH_A_AFTER"
  echo "[PR11] artifacts: $RUN_DIR"
} >"$SUMMARY"

echo "[PR11] done. summary: $SUMMARY"
