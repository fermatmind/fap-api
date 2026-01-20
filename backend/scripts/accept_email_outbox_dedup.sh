#!/usr/bin/env bash
set -euo pipefail

BACKEND_DIR="$(cd "$(dirname "${BASH_SOURCE[0]}")/.." && pwd)"
REPO_DIR="$(cd "${BACKEND_DIR}/.." && pwd)"

API="${API:-http://127.0.0.1:18000}"
SQLITE_DB="${SQLITE_DB:-${BACKEND_DIR}/database/database.sqlite}"

PHONE="${PHONE:-+8613800138002}"
SCENE="${SCENE:-login}"
EMAIL="${EMAIL:-accept_dedup_$(date +%s)@example.local}"

export PHONE
export SCENE

echo "[ACCEPT_EMAIL_DEDUP] repo=${REPO_DIR}"
echo "[ACCEPT_EMAIL_DEDUP] backend=${BACKEND_DIR}"
echo "[ACCEPT_EMAIL_DEDUP] API=${API}"
echo "[ACCEPT_EMAIL_DEDUP] SQLITE_DB=${SQLITE_DB}"
echo "[ACCEPT_EMAIL_DEDUP] PHONE=${PHONE} SCENE=${SCENE} EMAIL=${EMAIL}"

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
  echo "[ACCEPT_EMAIL_DEDUP][FAIL] no attempt_id found (results table empty)"
  exit 1
fi
export ATTEMPT_ID
echo "[ACCEPT_EMAIL_DEDUP] attempt_id=${ATTEMPT_ID}"

# 2) send_code (dev returns dev_code)
SEND_RAW="$(curl -sS -X POST "${API}/api/v0.2/auth/phone/send_code" \
  -H "Content-Type: application/json" -H "Accept: application/json" \
  -d "{\"phone\":\"${PHONE}\",\"consent\":true,\"scene\":\"${SCENE}\"}")"
SEND_JSON="$(printf "%s\n" "${SEND_RAW}" | tail -n 1)"

CODE="$(php -r '$j=json_decode(stream_get_contents(STDIN), true); echo $j["dev_code"] ?? "";' <<<"${SEND_JSON}")"

if [[ -z "${CODE}" ]]; then
  CODE="$(cd "${BACKEND_DIR}" && php artisan tinker --execute='
use Illuminate\Support\Facades\Cache;
$phone = getenv("PHONE");
$scene = getenv("SCENE") ?: "login";
$k = "otp:{$scene}:{$phone}";
echo (string) Cache::get($k);
' 2>/dev/null | tail -n 1 | tr -d "\r" | tr -d "\n")"
fi

if [[ -z "${CODE}" ]]; then
  echo "[ACCEPT_EMAIL_DEDUP][FAIL] cannot obtain OTP code"
  echo "[ACCEPT_EMAIL_DEDUP] send_code response=${SEND_JSON}"
  exit 1
fi
echo "[ACCEPT_EMAIL_DEDUP] code=${CODE}"

# 3) verify -> token
VERIFY_RAW="$(curl -sS -X POST "${API}/api/v0.2/auth/phone/verify" \
  -H "Content-Type: application/json" -H "Accept: application/json" \
  -d "{\"phone\":\"${PHONE}\",\"code\":\"${CODE}\",\"consent\":true,\"scene\":\"${SCENE}\"}")"
VERIFY_JSON="$(printf "%s\n" "${VERIFY_RAW}" | tail -n 1)"

TOKEN="$(php -r '$j=json_decode(stream_get_contents(STDIN), true); echo $j["token"] ?? "";' <<<"${VERIFY_JSON}")"
if [[ -z "${TOKEN}" ]]; then
  echo "[ACCEPT_EMAIL_DEDUP][FAIL] verify did not return token"
  echo "[ACCEPT_EMAIL_DEDUP] verify response=${VERIFY_JSON}"
  exit 1
fi
echo "[ACCEPT_EMAIL_DEDUP] token_issued=${TOKEN}"

# 4) bind email
BIND_JSON="$(curl -sS -X POST "${API}/api/v0.2/me/email/bind" \
  -H "Content-Type: application/json" -H "Accept: application/json" \
  -H "Authorization: Bearer ${TOKEN}" \
  -d "{\"email\":\"${EMAIL}\",\"consent\":true}")"

BIND_JSON="$(printf "%s\n" "${BIND_JSON}" | tail -n 1)"

if ! php -r '$j=json_decode(stream_get_contents(STDIN), true); exit((int) !($j["ok"] ?? false));' <<<"${BIND_JSON}"; then
  echo "[ACCEPT_EMAIL_DEDUP][FAIL] bind email failed: ${BIND_JSON}"
  exit 1
fi
echo "[ACCEPT_EMAIL_DEDUP] email bound"

# 5) trigger report twice
REPORT_JSON_1="$(curl -sS -H "Accept: application/json" -H "Authorization: Bearer ${TOKEN}" \
  "${API}/api/v0.2/attempts/${ATTEMPT_ID}/report")"
REPORT_JSON_1="$(printf "%s\n" "${REPORT_JSON_1}" | tail -n 1)"
REPORT_JSON_2="$(curl -sS -H "Accept: application/json" -H "Authorization: Bearer ${TOKEN}" \
  "${API}/api/v0.2/attempts/${ATTEMPT_ID}/report")"
REPORT_JSON_2="$(printf "%s\n" "${REPORT_JSON_2}" | tail -n 1)"

if ! php -r '$j=json_decode(stream_get_contents(STDIN), true); exit((int) !($j["ok"] ?? false));' <<<"${REPORT_JSON_1}"; then
  echo "[ACCEPT_EMAIL_DEDUP][FAIL] report#1 failed: ${REPORT_JSON_1}"
  exit 1
fi
if ! php -r '$j=json_decode(stream_get_contents(STDIN), true); exit((int) !($j["ok"] ?? false));' <<<"${REPORT_JSON_2}"; then
  echo "[ACCEPT_EMAIL_DEDUP][FAIL] report#2 failed: ${REPORT_JSON_2}"
  exit 1
fi
echo "[ACCEPT_EMAIL_DEDUP] report triggered twice"

# 6) pending count should be 1
COUNT_OUT="$(cd "${BACKEND_DIR}" && php artisan tinker --execute='
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

if (!Schema::hasTable("email_outbox")) { echo "PENDING_COUNT=\n"; return; }
if (!Schema::hasColumn("email_outbox", "attempt_id")) { echo "PENDING_COUNT=\n"; return; }

$attemptId = getenv("ATTEMPT_ID") ?: "";
if ($attemptId === "") { echo "PENDING_COUNT=\n"; return; }

$count = DB::table("email_outbox")
  ->where("attempt_id", $attemptId)
  ->where("template", "report_claim")
  ->where("status", "pending")
  ->where("claim_expires_at", ">", now())
  ->count();

echo "PENDING_COUNT={$count}\n";
' 2>/dev/null)"
PENDING_COUNT="$(printf "%s" "${COUNT_OUT}" | sed -n 's/^PENDING_COUNT=//p' | tail -n 1)"

if [[ -z "${PENDING_COUNT}" ]]; then
  echo "[ACCEPT_EMAIL_DEDUP][FAIL] cannot read pending count"
  exit 1
fi
if [[ "${PENDING_COUNT}" != "1" ]]; then
  echo "[ACCEPT_EMAIL_DEDUP][FAIL] pending count=${PENDING_COUNT}, expect 1"
  exit 1
fi
echo "[ACCEPT_EMAIL_DEDUP] pending count=1"

# 7) claim once ok, second TOKEN_USED
OUTBOX_OUT="$(cd "${BACKEND_DIR}" && php artisan tinker --execute='
use Illuminate\Support\Facades\DB;

$attemptId = getenv("ATTEMPT_ID") ?: "";
if ($attemptId === "") { echo "CLAIM_TOKEN=\n"; return; }

$row = DB::table("email_outbox")
  ->where("attempt_id", $attemptId)
  ->where("template", "report_claim")
  ->where("status", "pending")
  ->orderByDesc("updated_at")
  ->first();
if (!$row) { echo "CLAIM_TOKEN=\n"; return; }

$payload = is_string($row->payload_json) ? json_decode($row->payload_json, true) : (array)$row->payload_json;
$token = is_array($payload) ? ($payload["claim_token"] ?? "") : "";
echo "CLAIM_TOKEN={$token}\n";
')"

CLAIM_TOKEN="$(printf "%s" "${OUTBOX_OUT}" | sed -n 's/^CLAIM_TOKEN=//p' | tail -n 1)"
if [[ -z "${CLAIM_TOKEN}" ]]; then
  echo "[ACCEPT_EMAIL_DEDUP][FAIL] claim token not found in outbox"
  exit 1
fi
echo "[ACCEPT_EMAIL_DEDUP] claim_token=${CLAIM_TOKEN}"

CLAIM_JSON_1="$(curl -sS -H "Accept: application/json" \
  "${API}/api/v0.2/claim/report?token=${CLAIM_TOKEN}")"

CLAIM_JSON_1="$(printf "%s\n" "${CLAIM_JSON_1}" | tail -n 1)"

if ! php -r '$j=json_decode(stream_get_contents(STDIN), true); exit((int) !($j["ok"] ?? false));' <<<"${CLAIM_JSON_1}"; then
  echo "[ACCEPT_EMAIL_DEDUP][FAIL] claim#1 failed: ${CLAIM_JSON_1}"
  exit 1
fi

CLAIM_JSON_2="$(curl -sS -H "Accept: application/json" \
  "${API}/api/v0.2/claim/report?token=${CLAIM_TOKEN}")"

CLAIM_JSON_2="$(printf "%s\n" "${CLAIM_JSON_2}" | tail -n 1)"

if ! php -r '$j=json_decode(stream_get_contents(STDIN), true); exit((int) !(!($j["ok"] ?? false) && (($j["error"] ?? "") === "TOKEN_USED")));' <<<"${CLAIM_JSON_2}"; then
  echo "[ACCEPT_EMAIL_DEDUP][FAIL] claim#2 expected TOKEN_USED: ${CLAIM_JSON_2}"
  exit 1
fi

echo "[ACCEPT_EMAIL_DEDUP] DONE OK"
