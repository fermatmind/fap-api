#!/usr/bin/env bash
set -euo pipefail

BACKEND_DIR="$(cd "$(dirname "${BASH_SOURCE[0]}")/.." && pwd)"
REPO_DIR="$(cd "${BACKEND_DIR}/.." && pwd)"

API="${API:-http://127.0.0.1:18000}"
SQLITE_DB="${SQLITE_DB:-${BACKEND_DIR}/database/database.sqlite}"

PHONE="${PHONE:-+8613800138001}"
SCENE="${SCENE:-login}"
EMAIL="${EMAIL:-accept_email_$(date +%s)@example.local}"

export PHONE
export SCENE

echo "[ACCEPT_EMAIL] repo=${REPO_DIR}"
echo "[ACCEPT_EMAIL] backend=${BACKEND_DIR}"
echo "[ACCEPT_EMAIL] API=${API}"
echo "[ACCEPT_EMAIL] SQLITE_DB=${SQLITE_DB}"
echo "[ACCEPT_EMAIL] PHONE=${PHONE} SCENE=${SCENE} EMAIL=${EMAIL}"

# 0) health
curl -sS "${API}/api/v0.2/health" >/dev/null

# 1) find attempt_id with result
ATT_OUT="$(cd "${BACKEND_DIR}" && php artisan tinker --execute='
use Illuminate\Support\Facades\DB;

try {
  $has = DB::select("SELECT name FROM sqlite_master WHERE type=\"table\" AND name=\"results\" LIMIT 1");
  if (!$has) { echo "ATTEMPT_ID=\n"; return; }
} catch (\Throwable $e) {
  echo "ATTEMPT_ID=\n"; return;
}

$r = DB::table("results")->orderByDesc("created_at")->first();
if (!$r) { echo "ATTEMPT_ID=\n"; return; }

echo "ATTEMPT_ID={$r->attempt_id}\n";
')"
ATTEMPT_ID="$(printf "%s" "${ATT_OUT}" | sed -n 's/^ATTEMPT_ID=//p' | tail -n 1)"

if [[ -z "${ATTEMPT_ID}" ]]; then
  echo "[ACCEPT_EMAIL][FAIL] no attempt_id found (results table empty)"
  exit 1
fi
echo "[ACCEPT_EMAIL] attempt_id=${ATTEMPT_ID}"

# 2) send_code (dev returns dev_code)
SEND_JSON="$(curl -sS -X POST "${API}/api/v0.2/auth/phone/send_code" \
  -H "Content-Type: application/json" -H "Accept: application/json" \
  -d "{\"phone\":\"${PHONE}\",\"consent\":true,\"scene\":\"${SCENE}\"}")"

CODE="$(php -r '$j=json_decode(stream_get_contents(STDIN), true); echo $j["dev_code"] ?? "";' <<<"${SEND_JSON}")"

if [[ -z "${CODE}" ]]; then
  CODE="$(cd "${BACKEND_DIR}" && php artisan tinker --execute='
use Illuminate\Support\Facades\Cache;
$phone = getenv("PHONE");
$scene = getenv("SCENE") ?: "login";
$k = "otp:code:{$scene}:" . sha1($phone);
echo (string) Cache::get($k);
' 2>/dev/null | tail -n 1 | tr -d "\r" | tr -d "\n")"
fi

if [[ -z "${CODE}" ]]; then
  echo "[ACCEPT_EMAIL][FAIL] cannot obtain OTP code"
  echo "[ACCEPT_EMAIL] send_code response=${SEND_JSON}"
  exit 1
fi
echo "[ACCEPT_EMAIL] code=${CODE}"

# 3) verify -> token
VERIFY_JSON="$(curl -sS -X POST "${API}/api/v0.2/auth/phone/verify" \
  -H "Content-Type: application/json" -H "Accept: application/json" \
  -d "{\"phone\":\"${PHONE}\",\"code\":\"${CODE}\",\"consent\":true,\"scene\":\"${SCENE}\"}")"

TOKEN="$(php -r '$j=json_decode(stream_get_contents(STDIN), true); echo $j["token"] ?? "";' <<<"${VERIFY_JSON}")"
if [[ -z "${TOKEN}" ]]; then
  echo "[ACCEPT_EMAIL][FAIL] verify did not return token"
  echo "[ACCEPT_EMAIL] verify response=${VERIFY_JSON}"
  exit 1
fi
echo "[ACCEPT_EMAIL] token_issued=${TOKEN}"

# 4) bind email
BIND_JSON="$(curl -sS -X POST "${API}/api/v0.2/me/email/bind" \
  -H "Content-Type: application/json" -H "Accept: application/json" \
  -H "Authorization: Bearer ${TOKEN}" \
  -d "{\"email\":\"${EMAIL}\",\"consent\":true}")"

if ! php -r '$j=json_decode(stream_get_contents(STDIN), true); exit((int) !($j["ok"] ?? false));' <<<"${BIND_JSON}"; then
  echo "[ACCEPT_EMAIL][FAIL] bind email failed: ${BIND_JSON}"
  exit 1
fi
echo "[ACCEPT_EMAIL] email bound"

# 5) trigger report (with token)
REPORT_JSON="$(curl -sS -H "Accept: application/json" -H "Authorization: Bearer ${TOKEN}" \
  "${API}/api/v0.2/attempts/${ATTEMPT_ID}/report")"

if ! php -r '$j=json_decode(stream_get_contents(STDIN), true); exit((int) !($j["ok"] ?? false));' <<<"${REPORT_JSON}"; then
  echo "[ACCEPT_EMAIL][FAIL] report failed: ${REPORT_JSON}"
  exit 1
fi
echo "[ACCEPT_EMAIL] report triggered"

# 6) read latest outbox claim token
OUTBOX_OUT="$(cd "${BACKEND_DIR}" && php artisan tinker --execute='
use Illuminate\Support\Facades\DB;

try {
  $has = DB::select("SELECT name FROM sqlite_master WHERE type=\"table\" AND name=\"email_outbox\" LIMIT 1");
  if (!$has) { echo "CLAIM_TOKEN=\n"; return; }
} catch (\Throwable $e) {
  echo "CLAIM_TOKEN=\n"; return;
}

$row = DB::table("email_outbox")->orderByDesc("created_at")->first();
if (!$row) { echo "CLAIM_TOKEN=\n"; return; }

$payload = is_string($row->payload_json) ? json_decode($row->payload_json, true) : (array)$row->payload_json;
$token = is_array($payload) ? ($payload["claim_token"] ?? "") : "";
echo "CLAIM_TOKEN={$token}\n";
')"

CLAIM_TOKEN="$(printf "%s" "${OUTBOX_OUT}" | sed -n 's/^CLAIM_TOKEN=//p' | tail -n 1)"
if [[ -z "${CLAIM_TOKEN}" ]]; then
  echo "[ACCEPT_EMAIL][FAIL] claim token not found in outbox"
  exit 1
fi
echo "[ACCEPT_EMAIL] claim_token=${CLAIM_TOKEN}"

# 7) claim report
CLAIM_JSON="$(curl -sS -H "Accept: application/json" \
  "${API}/api/v0.2/claim/report?token=${CLAIM_TOKEN}")"

if ! php -r '$j=json_decode(stream_get_contents(STDIN), true); exit((int) !($j["ok"] ?? false));' <<<"${CLAIM_JSON}"; then
  echo "[ACCEPT_EMAIL][FAIL] claim failed: ${CLAIM_JSON}"
  exit 1
fi

echo "[ACCEPT_EMAIL] DONE OK"
