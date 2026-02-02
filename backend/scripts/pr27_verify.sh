#!/usr/bin/env bash
set -euo pipefail

export CI=true
export FAP_NONINTERACTIVE=1
export COMPOSER_NO_INTERACTION=1
export GIT_TERMINAL_PROMPT=0
export NO_COLOR=1

ROOT_DIR="$(cd "$(dirname "${BASH_SOURCE[0]}")/../.." && pwd)"
BACKEND_DIR="${ROOT_DIR}/backend"
ART_DIR="${BACKEND_DIR}/artifacts/pr27"
API_BASE="http://127.0.0.1:1827"

mkdir -p "${ART_DIR}"

cd "${BACKEND_DIR}"
php artisan serve --host=127.0.0.1 --port=1827 >"${ART_DIR}/server.log" 2>&1 &
SERVER_PID=$!
echo "${SERVER_PID}" >"${ART_DIR}/server.pid"
cd "${ROOT_DIR}"

HEALTH_JSON="${ART_DIR}/health.json"
health_code="000"
for i in $(seq 1 30); do
  health_code=$(curl -sS -o "${HEALTH_JSON}" -w "%{http_code}" "${API_BASE}/up" || true)
  if [[ "${health_code}" == "200" ]]; then
    break
  fi
  sleep 1
done

if [[ "${health_code}" != "200" ]]; then
  echo "health_check_failed status=${health_code}" >&2
  cat "${HEALTH_JSON}" >&2 || true
  tail -n 200 "${ART_DIR}/server.log" >&2 || true
  exit 1
fi

QUESTIONS_JSON="${ART_DIR}/questions.json"
http_code=$(curl -sS -L -o "${QUESTIONS_JSON}" -w "%{http_code}" \
  "${API_BASE}/api/v0.3/scales/MBTI/questions" || true)
if [[ "${http_code}" != "200" ]]; then
  echo "questions_failed http=${http_code}" >&2
  cat "${QUESTIONS_JSON}" >&2 || true
  exit 1
fi

QUESTION_COUNT=$(php -r '
$j=json_decode(file_get_contents($argv[1]), true);
$q=$j["questions"] ?? [];
$items=[];
if (is_array($q) && array_key_exists("items", $q) && is_array($q["items"])) { $items=$q["items"]; }
elseif (is_array($q) && array_key_exists("questions", $q) && is_array($q["questions"])) { $items=$q["questions"]; }
elseif (is_array($q) && array_key_exists("data", $q) && is_array($q["data"])) { $items=$q["data"]; }
elseif (is_array($q)) { $items=$q; }
$count=0;
foreach ($items as $item) { if (is_array($item)) { $count++; } }
echo $count;
' "${QUESTIONS_JSON}")

if [[ "${QUESTION_COUNT}" -le 0 ]]; then
  echo "questions_empty" >&2
  exit 1
fi

ATTEMPT_START_JSON="${ART_DIR}/attempt_start.json"
http_code=$(curl -sS -L -o "${ATTEMPT_START_JSON}" -w "%{http_code}" \
  -X POST "${API_BASE}/api/v0.3/attempts/start" \
  -H "Content-Type: application/json" -H "Accept: application/json" \
  -d '{"scale_code":"MBTI"}' || true)
if [[ "${http_code}" != "200" ]]; then
  echo "attempt_start_failed http=${http_code}" >&2
  cat "${ATTEMPT_START_JSON}" >&2 || true
  exit 1
fi

ATTEMPT_ID=$(php -r '$j=json_decode(file_get_contents($argv[1]), true); echo $j["attempt_id"] ?? "";' "${ATTEMPT_START_JSON}")
if [[ -z "${ATTEMPT_ID}" ]]; then
  echo "missing_attempt_id" >&2
  exit 1
fi

ANSWERS_JSON="${ART_DIR}/answers.json"
php -r '
$attemptId=$argv[1];
$q=json_decode(file_get_contents($argv[2]), true);
$doc=$q["questions"] ?? [];
$items=[];
if (is_array($doc) && array_key_exists("items", $doc) && is_array($doc["items"])) { $items=$doc["items"]; }
elseif (is_array($doc) && array_key_exists("questions", $doc) && is_array($doc["questions"])) { $items=$doc["questions"]; }
elseif (is_array($doc) && array_key_exists("data", $doc) && is_array($doc["data"])) { $items=$doc["data"]; }
elseif (is_array($doc)) { $items=$doc; }
$answers=[];
foreach ($items as $item) {
  if (!is_array($item)) { continue; }
  $qid=$item["question_id"] ?? ($item["id"] ?? null);
  if (!$qid) { continue; }
  $answers[]=["question_id"=>$qid, "code"=>"A"];
}
$payload=["attempt_id"=>$attemptId, "answers"=>$answers, "duration_ms"=>120000];
file_put_contents($argv[3], json_encode($payload, JSON_UNESCAPED_UNICODE));
' "${ATTEMPT_ID}" "${QUESTIONS_JSON}" "${ANSWERS_JSON}"

SUBMIT_JSON="${ART_DIR}/submit.json"
http_code=$(curl -sS -L -o "${SUBMIT_JSON}" -w "%{http_code}" \
  -X POST "${API_BASE}/api/v0.3/attempts/submit" \
  -H "Content-Type: application/json" -H "Accept: application/json" \
  -d "@${ANSWERS_JSON}" || true)
if [[ "${http_code}" != "200" ]]; then
  echo "submit_failed http=${http_code}" >&2
  cat "${SUBMIT_JSON}" >&2 || true
  exit 1
fi

REPORT_JSON="${ART_DIR}/report.json"
http_code=$(curl -sS -L -o "${REPORT_JSON}" -w "%{http_code}" \
  "${API_BASE}/api/v0.3/attempts/${ATTEMPT_ID}/report" || true)
if [[ "${http_code}" != "200" ]]; then
  echo "report_failed http=${http_code}" >&2
  cat "${REPORT_JSON}" >&2 || true
  exit 1
fi

php -r '
$j=json_decode(file_get_contents($argv[1]), true);
if (($j["upgrade_sku"] ?? "") !== "MBTI_REPORT_FULL") { fwrite(STDERR, "upgrade_sku_mismatch\n"); exit(1); }
if (($j["upgrade_sku_effective"] ?? "") !== "MBTI_REPORT_FULL_199") { fwrite(STDERR, "upgrade_sku_effective_mismatch\n"); exit(1); }
$view=$j["view_policy"] ?? [];
if (($view["upgrade_sku"] ?? "") !== "MBTI_REPORT_FULL_199") { fwrite(STDERR, "view_policy_upgrade_sku_mismatch\n"); exit(1); }
$offers=$j["offers"] ?? [];
$found=false;
if (is_array($offers)) {
  foreach ($offers as $offer) {
    if (is_array($offer) && ($offer["sku"] ?? "") === "MBTI_REPORT_FULL_199") { $found=true; break; }
  }
}
if (!$found) { fwrite(STDERR, "offer_missing_MBTI_REPORT_FULL_199\n"); exit(1); }
' "${REPORT_JSON}"

if [[ -f "${ART_DIR}/server.pid" ]]; then
  kill "${SERVER_PID}" || true
  sleep 1
  if kill -0 "${SERVER_PID}" >/dev/null 2>&1; then
    kill -9 "${SERVER_PID}" || true
  fi
fi

if lsof -nP -iTCP:1827 -sTCP:LISTEN >/dev/null 2>&1; then
  lsof -nP -iTCP:1827 -sTCP:LISTEN || true
  echo "port_1827_still_listening" >&2
  exit 1
fi

echo "[PR27] verify complete"
