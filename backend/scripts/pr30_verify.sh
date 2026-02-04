#!/usr/bin/env bash
set -euo pipefail

export CI=true
export FAP_NONINTERACTIVE=1
export COMPOSER_NO_INTERACTION=1
export GIT_TERMINAL_PROMPT=0
export NO_COLOR=1
export PAGER=cat
export GIT_PAGER=cat
export TERM=dumb
export XDEBUG_MODE=off

SCRIPT_DIR="$(cd "$(dirname "${BASH_SOURCE[0]}")" && pwd)"
REPO_DIR="$(cd "${SCRIPT_DIR}/../.." && pwd)"
BACKEND_DIR="${REPO_DIR}/backend"

SERVE_PORT="${SERVE_PORT:-1830}"
API_BASE="http://127.0.0.1:${SERVE_PORT}"

ART_DIR="${ART_DIR:-${BACKEND_DIR}/artifacts/pr30}"
LOG_DIR="${ART_DIR}/logs"
VERIFY_LOG="${ART_DIR}/verify.log"
mkdir -p "${ART_DIR}" "${LOG_DIR}"

exec > >(tee "${VERIFY_LOG}") 2>&1

fail() {
  echo "[pr30][fail] $*" >&2
  exit 1
}

echo "[pr30] api_base=${API_BASE}"

# 1) healthz
curl -sS "${API_BASE}/api/healthz" > "${ART_DIR}/healthz.json"
php -r '
$j=json_decode(file_get_contents("'"${ART_DIR}/healthz.json"'"), true);
if (!is_array($j) || !($j["ok"] ?? false)) {
  fwrite(STDERR, "healthz ok=false\n");
  exit(2);
}
' || fail "healthz failed"

# 2) v0.3 questions
curl -sS -H "Accept: application/json" -H "X-Region: CN_MAINLAND" -H "Accept-Language: zh-CN" \
  "${API_BASE}/api/v0.3/scales/MBTI/questions" > "${ART_DIR}/questions.json"

# 3) start attempt
curl -sS -X POST \
  -H "Accept: application/json" -H "Content-Type: application/json" \
  -H "X-Region: CN_MAINLAND" -H "Accept-Language: zh-CN" \
  --data '{"scale_code":"MBTI","anon_id":"pr30-cli"}' \
  "${API_BASE}/api/v0.3/attempts/start" > "${ART_DIR}/attempt_start.json"

ATTEMPT_ID="$(php -r '$d=json_decode(file_get_contents("'"${ART_DIR}/attempt_start.json"'"), true); echo $d["attempt_id"] ?? "";')"
if [[ -z "${ATTEMPT_ID}" ]]; then
  fail "attempt start failed"
fi
echo "${ATTEMPT_ID}" > "${ART_DIR}/attempt_id.txt"

# 4) build answers dynamically
php -r '
$q=json_decode(file_get_contents("'"${ART_DIR}/questions.json"'"), true);
$items=$q["questions"]["items"] ?? $q["items"] ?? ($q["questions"] ?? []);
$answers=[];
foreach ($items as $item) {
  if (!is_array($item)) { continue; }
  $qid=$item["question_id"] ?? "";
  if ($qid === "") { continue; }
  $opts=$item["options"] ?? [];
  $code="";
  if (is_array($opts) && isset($opts[0]) && is_array($opts[0])) {
    $code=(string) ($opts[0]["code"] ?? $opts[0]["option_code"] ?? "");
  }
  if ($code === "") { $code="A"; }
  $answers[]=["question_id"=>$qid,"code"=>$code];
}
file_put_contents("'"${ART_DIR}/answers.json"'", json_encode($answers, JSON_UNESCAPED_UNICODE));
' || fail "build answers failed"

if [[ ! -s "${ART_DIR}/answers.json" ]]; then
  fail "answers.json empty"
fi

# 5) submit attempt
php -r '
$answers=json_decode(file_get_contents("'"${ART_DIR}/answers.json"'"), true);
if (!is_array($answers)) { $answers=[]; }
$payload=[
  "attempt_id"=>"'"${ATTEMPT_ID}"'",
  "duration_ms"=>120000,
  "answers"=>$answers,
];
file_put_contents("'"${ART_DIR}/submit.json"'", json_encode($payload, JSON_UNESCAPED_UNICODE));
'

curl -sS -X POST \
  -H "Accept: application/json" -H "Content-Type: application/json" \
  -H "X-Region: CN_MAINLAND" -H "Accept-Language: zh-CN" \
  --data-binary @"${ART_DIR}/submit.json" \
  "${API_BASE}/api/v0.3/attempts/submit" > "${ART_DIR}/submit_resp.json"

php -r '
$d=json_decode(file_get_contents("'"${ART_DIR}/submit_resp.json"'"), true);
if (!is_array($d) || !($d["ok"] ?? false)) {
  fwrite(STDERR, "submit ok=false\n");
  exit(2);
}
' || fail "attempt submit failed"

# 6) report
curl -sS -H "Accept: application/json" \
  "${API_BASE}/api/v0.3/attempts/${ATTEMPT_ID}/report" > "${ART_DIR}/report.json"

php -r '
$d=json_decode(file_get_contents("'"${ART_DIR}/report.json"'"), true);
if (!is_array($d) || !($d["ok"] ?? false)) {
  fwrite(STDERR, "report ok=false\n");
  exit(2);
}
' || fail "report failed"

# 7) rate limit probe (public GET)
RATE_HEADERS="${ART_DIR}/rate_limit_headers.txt"
HTTP_CODE="$(curl -sS -D "${RATE_HEADERS}" -o /dev/null -w "%{http_code}" "${API_BASE}/api/v0.2/health")"
if [[ "${HTTP_CODE}" != "200" ]]; then
  fail "rate limit probe initial request failed http=${HTTP_CODE}"
fi

LIMIT="$(grep -i '^X-RateLimit-Limit:' "${RATE_HEADERS}" | tail -n 1 | awk '{print $2}' | tr -d '\r')"
REMAINING="$(grep -i '^X-RateLimit-Remaining:' "${RATE_HEADERS}" | tail -n 1 | awk '{print $2}' | tr -d '\r')"

if [[ -z "${LIMIT}" || -z "${REMAINING}" ]]; then
  fail "rate limit headers missing"
fi

echo "rate_limit_limit=${LIMIT}" > "${ART_DIR}/rate_limit.txt"
echo "rate_limit_remaining=${REMAINING}" >> "${ART_DIR}/rate_limit.txt"

ATTEMPTS=$((REMAINING + 1))
HIT=0
for i in $(seq 1 "${ATTEMPTS}"); do
  HDR="${ART_DIR}/rate_limit_attempt_${i}.headers"
  CODE="$(curl -sS -D "${HDR}" -o /dev/null -w "%{http_code}" "${API_BASE}/api/v0.2/health")"
  if [[ "${CODE}" == "429" ]]; then
    HIT=1
    RETRY_AFTER="$(grep -i '^Retry-After:' "${HDR}" | tail -n 1 | awk '{print $2}' | tr -d '\r')"
    if [[ -z "${RETRY_AFTER}" ]]; then
      fail "Retry-After header missing"
    fi
    echo "rate_limit_hit_after=${i}" >> "${ART_DIR}/rate_limit.txt"
    echo "rate_limit_retry_after=${RETRY_AFTER}" >> "${ART_DIR}/rate_limit.txt"
    break
  fi
done

if [[ "${HIT}" -ne 1 ]]; then
  fail "rate limit not triggered"
fi

echo "[pr30] verify ok"
