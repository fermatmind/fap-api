#!/usr/bin/env bash
set -euo pipefail

BACKEND_DIR="$(cd "$(dirname "${BASH_SOURCE[0]}")/.." && pwd)"
REPO_DIR="$(cd "${BACKEND_DIR}/.." && pwd)"

RUN_DIR="${RUN_DIR:-$BACKEND_DIR/artifacts/pr18}"
LOG_FILE="$RUN_DIR/verify.log"
SERVE_PORT="${SERVE_PORT:-18000}"
API="http://127.0.0.1:${SERVE_PORT}"
SQLITE_DB="${RUN_DIR}/pr18.sqlite"

mkdir -p "$RUN_DIR"
rm -f "$SQLITE_DB"

exec > >(tee "$LOG_FILE") 2>&1

echo "[PR18] repo=<REPO_PATH>"
echo "[PR18] api=${API}"

run_artisan() {
  DB_CONNECTION=sqlite DB_DATABASE="$SQLITE_DB" php artisan "$@"
}

echo "[PR18] migrate"
run_artisan migrate --force

echo "[PR18] seed scales"
run_artisan fap:scales:seed-default
run_artisan fap:scales:sync-slugs
run_artisan db:seed --class=Database\\Seeders\\Pr17SimpleScoreDemoSeeder

SERVE_LOG="/tmp/pr18_serve.log"
DB_CONNECTION=sqlite DB_DATABASE="$SQLITE_DB" php artisan serve --host=127.0.0.1 --port="${SERVE_PORT}" >"${SERVE_LOG}" 2>&1 &
SERVE_PID=$!

cleanup() {
  if ps -p "${SERVE_PID}" >/dev/null 2>&1; then
    kill "${SERVE_PID}" >/dev/null 2>&1 || true
  fi
}
trap cleanup EXIT

echo "[PR18] wait for server"
for _ in {1..30}; do
  if curl -sS "${API}/api/v0.2/health" >/dev/null 2>&1; then
    break
  fi
  sleep 0.5
done

get_token() {
  local phone="$1"
  local anon="$2"
  local send_json
  local code
  local verify_json
  local token

  send_json="$(curl -sS -X POST "${API}/api/v0.2/auth/phone/send_code" \
    -H "Content-Type: application/json" -H "Accept: application/json" \
    -d "{\"phone\":\"${phone}\",\"consent\":true,\"scene\":\"login\"}")"

  code="$(php -r '$j=json_decode(stream_get_contents(STDIN), true); echo $j["dev_code"] ?? "";' <<<"${send_json}")"

  if [[ -z "${code}" ]]; then
    CODE_PHONE="${phone}" DB_CONNECTION=sqlite DB_DATABASE="$SQLITE_DB" php artisan tinker --execute='
use Illuminate\Support\Facades\Cache;
$phone = getenv("CODE_PHONE");
$k = "otp:login:{$phone}";
echo (string) Cache::get($k);
' 2>/dev/null | tail -n 1 | tr -d "\r\n" > /tmp/pr18_code.txt || true
    code="$(cat /tmp/pr18_code.txt 2>/dev/null || true)"
  fi

  if [[ -z "${code}" ]]; then
    echo "[PR18][FAIL] cannot obtain OTP code for ${phone}"
    exit 1
  fi

  verify_json="$(curl -sS -X POST "${API}/api/v0.2/auth/phone/verify" \
    -H "Content-Type: application/json" -H "Accept: application/json" \
    -d "{\"phone\":\"${phone}\",\"code\":\"${code}\",\"consent\":true,\"scene\":\"login\",\"anon_id\":\"${anon}\"}")"

  token="$(php -r '$j=json_decode(stream_get_contents(STDIN), true); echo $j["token"] ?? "";' <<<"${verify_json}")"
  if [[ -z "${token}" ]]; then
    echo "[PR18][FAIL] verify did not return token for ${phone}"
    exit 1
  fi

  echo "${token}"
}

TOKEN_A="$(get_token "+8613800138001" "pr18_user_a")"
TOKEN_B="$(get_token "+8613800138002" "pr18_user_b")"

ORG1_JSON="$(curl -sS -X POST "${API}/api/v0.3/orgs" \
  -H "Content-Type: application/json" -H "Accept: application/json" \
  -H "Authorization: Bearer ${TOKEN_A}" \
  -d '{"name":"Org One"}')"
ORG1_ID="$(php -r '$j=json_decode(stream_get_contents(STDIN), true); echo $j["org"]["org_id"] ?? "";' <<<"${ORG1_JSON}")"

ORG2_JSON="$(curl -sS -X POST "${API}/api/v0.3/orgs" \
  -H "Content-Type: application/json" -H "Accept: application/json" \
  -H "Authorization: Bearer ${TOKEN_B}" \
  -d '{"name":"Org Two"}')"
ORG2_ID="$(php -r '$j=json_decode(stream_get_contents(STDIN), true); echo $j["org"]["org_id"] ?? "";' <<<"${ORG2_JSON}")"

INVITE_JSON="$(curl -sS -X POST "${API}/api/v0.3/orgs/${ORG2_ID}/invites" \
  -H "Content-Type: application/json" -H "Accept: application/json" \
  -H "Authorization: Bearer ${TOKEN_B}" \
  -H "X-Org-Id: ${ORG2_ID}" \
  -d '{"email":"usera@pr18.test"}')"
INVITE_TOKEN="$(php -r '$j=json_decode(stream_get_contents(STDIN), true); echo $j["invite"]["token"] ?? "";' <<<"${INVITE_JSON}")"

ACCEPT_JSON="$(curl -sS -X POST "${API}/api/v0.3/orgs/invites/accept" \
  -H "Content-Type: application/json" -H "Accept: application/json" \
  -H "Authorization: Bearer ${TOKEN_A}" \
  -d "{\"token\":\"${INVITE_TOKEN}\"}")"

ORGS_ME_JSON="$(curl -sS -H "Accept: application/json" -H "Authorization: Bearer ${TOKEN_A}" \
  "${API}/api/v0.3/orgs/me")"
printf "%s" "${ORGS_ME_JSON}" > "${RUN_DIR}/curl_orgs_me.json"

START_JSON="$(curl -sS -X POST "${API}/api/v0.3/attempts/start" \
  -H "Content-Type: application/json" -H "Accept: application/json" \
  -H "Authorization: Bearer ${TOKEN_A}" \
  -H "X-Org-Id: ${ORG1_ID}" \
  -d '{"scale_code":"SIMPLE_SCORE_DEMO"}')"
ATTEMPT_ID="$(php -r '$j=json_decode(stream_get_contents(STDIN), true); echo $j["attempt_id"] ?? "";' <<<"${START_JSON}")"

SUBMIT_JSON="$(curl -sS -X POST "${API}/api/v0.3/attempts/submit" \
  -H "Content-Type: application/json" -H "Accept: application/json" \
  -H "Authorization: Bearer ${TOKEN_A}" \
  -H "X-Org-Id: ${ORG1_ID}" \
  -d "{\"attempt_id\":\"${ATTEMPT_ID}\",\"answers\":[{\"question_id\":\"SS-001\",\"code\":\"5\"}],\"duration_ms\":1000}")"

CROSS_JSON="$(curl -sS -H "Accept: application/json" \
  -H "Authorization: Bearer ${TOKEN_A}" \
  -H "X-Org-Id: ${ORG2_ID}" \
  "${API}/api/v0.3/attempts/${ATTEMPT_ID}/result")"
printf "%s" "${CROSS_JSON}" > "${RUN_DIR}/curl_cross_org_404.json"

CROSS_ERROR="$(php -r '$j=json_decode(stream_get_contents(STDIN), true); echo $j["error"] ?? "";' <<<"${CROSS_JSON}")"

echo "[PR18] org1_id=${ORG1_ID} org2_id=${ORG2_ID} attempt_id=${ATTEMPT_ID} cross_error=${CROSS_ERROR}"
echo "[PR18] verify OK ✅"
