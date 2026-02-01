#!/usr/bin/env bash
set -euo pipefail

export CI=true
export FAP_NONINTERACTIVE=1
export COMPOSER_NO_INTERACTION=1
export GIT_TERMINAL_PROMPT=0
export NO_COLOR=1

REPO_DIR="$(cd "$(dirname "${BASH_SOURCE[0]}")/../.." && pwd)"
BACKEND_DIR="${REPO_DIR}/backend"
ART_DIR="${ART_DIR:-${REPO_DIR}/backend/artifacts/pr215}"
SERVE_PORT="${SERVE_PORT:-1815}"
API_BASE="http://127.0.0.1:${SERVE_PORT}"

mkdir -p "${ART_DIR}/logs"

for p in "${SERVE_PORT}" 18000; do
  lsof -ti tcp:${p} | xargs -r kill -9 || true
  lsof -nP -iTCP:${p} -sTCP:LISTEN || true
  lsof -ti tcp:${p} | xargs -r kill -9 || true
  lsof -nP -iTCP:${p} -sTCP:LISTEN || true
  :
done

cd "${BACKEND_DIR}"

php artisan serve --host=127.0.0.1 --port="${SERVE_PORT}" >"${ART_DIR}/logs/server.log" 2>&1 &
SRV_PID="$!"
echo "${SRV_PID}" > "${ART_DIR}/server.pid"
trap 'kill "${SRV_PID}" 2>/dev/null || true' EXIT

for i in $(seq 1 40); do
  curl -sS "${API_BASE}/api/v0.2/health" >/dev/null 2>&1 && break
  sleep 1
done

if ! curl -sS "${API_BASE}/api/v0.2/health" >"${ART_DIR}/health.json"; then
  tail -n 200 "${ART_DIR}/logs/server.log" || true
  exit 1
fi

curl -sS -H "Accept: application/json" -H "X-Region: CN_MAINLAND" -H "Accept-Language: zh-CN" \
  "${API_BASE}/api/v0.3/scales/MBTI/questions" >"${ART_DIR}/mbti_questions.json"

START_JSON="${ART_DIR}/attempt_start.json"
START_BODY='{"scale_code":"MBTI","anon_id":"pr215-local","region":"CN_MAINLAND","locale":"zh-CN"}'

curl -sS -X POST \
  -H "Accept: application/json" -H "Content-Type: application/json" \
  -H "X-Region: CN_MAINLAND" -H "Accept-Language: zh-CN" \
  -d "${START_BODY}" \
  "${API_BASE}/api/v0.3/attempts/start" >"${START_JSON}"

ATTEMPT_ID="$(php -r '$j=json_decode(stream_get_contents(STDIN), true); echo $j["attempt_id"] ?? "";' < "${START_JSON}")"
if [[ -z "${ATTEMPT_ID}" ]]; then
  echo "create attempt failed" >&2
  cat "${START_JSON}" >&2 || true
  tail -n 200 "${ART_DIR}/logs/server.log" || true
  tail -n 200 "${BACKEND_DIR}/storage/logs/laravel.log" 2>/dev/null || true
  exit 2
fi

echo "${ATTEMPT_ID}" > "${ART_DIR}/attempt_id.txt"

ART_DIR="${ART_DIR}" php -r '
$questions=json_decode(file_get_contents(getenv("ART_DIR") . "/mbti_questions.json"), true);
$items=$questions["questions"]["items"] ?? $questions["items"] ?? [];
$answers=[];
if (is_array($items)) {
  foreach ($items as $item) {
    if (!is_array($item)) { continue; }
    $qid=$item["question_id"] ?? "";
    if ($qid === "") { continue; }
    $code="C";
    $opts=$item["options"] ?? [];
    if (is_array($opts) && count($opts) > 0) {
      $code=$opts[0]["code"] ?? $code;
    }
    $answers[]=["question_id"=>$qid, "code"=>$code];
  }
}
if (count($answers) <= 0) {
  fwrite(STDERR, "no answers built\n");
  exit(3);
}
$attemptId=trim(file_get_contents(getenv("ART_DIR") . "/attempt_id.txt"));
$payload=["attempt_id"=>$attemptId, "duration_ms"=>45000, "answers"=>$answers];
file_put_contents(getenv("ART_DIR") . "/submit.json", json_encode($payload, JSON_UNESCAPED_UNICODE));
'

curl -sS -X POST \
  -H "Accept: application/json" -H "Content-Type: application/json" \
  -H "X-Region: CN_MAINLAND" -H "Accept-Language: zh-CN" \
  --data-binary @"${ART_DIR}/submit.json" \
  "${API_BASE}/api/v0.3/attempts/submit" > "${ART_DIR}/submit_resp.json"

curl -sS -H "Accept: application/json" -H "X-Region: CN_MAINLAND" -H "Accept-Language: zh-CN" \
  "${API_BASE}/api/v0.3/attempts/${ATTEMPT_ID}/result" > "${ART_DIR}/result.json"

curl -sS -H "Accept: application/json" -H "X-Region: CN_MAINLAND" -H "Accept-Language: zh-CN" \
  "${API_BASE}/api/v0.3/attempts/${ATTEMPT_ID}/report" > "${ART_DIR}/report.json"

echo "OK" > "${ART_DIR}/verify.ok"
