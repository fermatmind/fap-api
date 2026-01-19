#!/usr/bin/env bash
set -euo pipefail

BACKEND_DIR="$(cd "$(dirname "${BASH_SOURCE[0]}")/.." && pwd)"
REPO_DIR="$(cd "${BACKEND_DIR}/.." && pwd)"

API="${API:-http://127.0.0.1:18000}"
SQLITE_DB="${SQLITE_DB:-${BACKEND_DIR}/database/database.sqlite}"

PHONE="${PHONE:-+8613800138002}"
SCENE="${SCENE:-login}"
PROVIDER="${PROVIDER:-wechat}"
ANON_ID="${ANON_ID:-identity_$(date +%s)}"

export PHONE
export SCENE

echo "[ACCEPT_IDENT] repo=${REPO_DIR}"
echo "[ACCEPT_IDENT] backend=${BACKEND_DIR}"
echo "[ACCEPT_IDENT] API=${API}"
echo "[ACCEPT_IDENT] SQLITE_DB=${SQLITE_DB}"
echo "[ACCEPT_IDENT] PHONE=${PHONE} SCENE=${SCENE} PROVIDER=${PROVIDER} ANON_ID=${ANON_ID}"

# 0) health
curl -sS "${API}/api/v0.2/health" >/dev/null

# 1) send_code (dev returns dev_code)
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
  echo "[ACCEPT_IDENT][FAIL] cannot obtain OTP code"
  echo "[ACCEPT_IDENT] send_code response=${SEND_JSON}"
  exit 1
fi
echo "[ACCEPT_IDENT] code=${CODE}"

# 2) verify -> token1
VERIFY_JSON="$(curl -sS -X POST "${API}/api/v0.2/auth/phone/verify" \
  -H "Content-Type: application/json" -H "Accept: application/json" \
  -d "{\"phone\":\"${PHONE}\",\"code\":\"${CODE}\",\"consent\":true,\"scene\":\"${SCENE}\"}")"

TOKEN1="$(php -r '$j=json_decode(stream_get_contents(STDIN), true); echo $j["token"] ?? "";' <<<"${VERIFY_JSON}")"
if [[ -z "${TOKEN1}" ]]; then
  echo "[ACCEPT_IDENT][FAIL] verify did not return token"
  echo "[ACCEPT_IDENT] verify response=${VERIFY_JSON}"
  exit 1
fi
echo "[ACCEPT_IDENT] token1=${TOKEN1}"

# 3) provider login (expect bound=false, get provider_uid)
PROV_JSON="$(curl -sS -X POST "${API}/api/v0.2/auth/provider" \
  -H "Content-Type: application/json" -H "Accept: application/json" \
  -d "{\"provider\":\"${PROVIDER}\",\"provider_code\":\"dev\",\"anon_id\":\"${ANON_ID}\"}")"

PROVIDER_UID="$(php -r '$j=json_decode(stream_get_contents(STDIN), true); echo $j["provider_uid"] ?? "";' <<<"${PROV_JSON}")"
if [[ -z "${PROVIDER_UID}" ]]; then
  echo "[ACCEPT_IDENT][FAIL] provider_uid missing"
  echo "[ACCEPT_IDENT] auth/provider response=${PROV_JSON}"
  exit 1
fi
echo "[ACCEPT_IDENT] provider_uid=${PROVIDER_UID}"

# 4) bind identity
BIND_JSON="$(curl -sS -X POST "${API}/api/v0.2/me/identities/bind" \
  -H "Content-Type: application/json" -H "Accept: application/json" \
  -H "Authorization: Bearer ${TOKEN1}" \
  -d "{\"provider\":\"${PROVIDER}\",\"provider_uid\":\"${PROVIDER_UID}\",\"consent\":true}")"

if ! php -r '$j=json_decode(stream_get_contents(STDIN), true); exit((int) !($j["ok"] ?? false));' <<<"${BIND_JSON}"; then
  echo "[ACCEPT_IDENT][FAIL] bind identity failed: ${BIND_JSON}"
  exit 1
fi
echo "[ACCEPT_IDENT] identity bound"

# 5) provider login again -> token2
PROV2_JSON="$(curl -sS -X POST "${API}/api/v0.2/auth/provider" \
  -H "Content-Type: application/json" -H "Accept: application/json" \
  -d "{\"provider\":\"${PROVIDER}\",\"provider_code\":\"dev\",\"anon_id\":\"${ANON_ID}\"}")"

TOKEN2="$(php -r '$j=json_decode(stream_get_contents(STDIN), true); echo $j["token"] ?? "";' <<<"${PROV2_JSON}")"
if [[ -z "${TOKEN2}" ]]; then
  echo "[ACCEPT_IDENT][FAIL] provider login did not return token"
  echo "[ACCEPT_IDENT] auth/provider response=${PROV2_JSON}"
  exit 1
fi
echo "[ACCEPT_IDENT] token2=${TOKEN2}"

# 6) compare /me/attempts user_id
ME1="$(curl -sS -H "Accept: application/json" -H "Authorization: Bearer ${TOKEN1}" \
  "${API}/api/v0.2/me/attempts?per_page=1&page=1")"
ME2="$(curl -sS -H "Accept: application/json" -H "Authorization: Bearer ${TOKEN2}" \
  "${API}/api/v0.2/me/attempts?per_page=1&page=1")"

export ME1
export ME2

php -r '
$j1=json_decode(getenv("ME1"), true);
$j2=json_decode(getenv("ME2"), true);
if (!is_array($j1) || !($j1["ok"] ?? false)) { fwrite(STDERR, "[ACCEPT_IDENT][FAIL] /me/attempts token1 not ok\n"); exit(1); }
if (!is_array($j2) || !($j2["ok"] ?? false)) { fwrite(STDERR, "[ACCEPT_IDENT][FAIL] /me/attempts token2 not ok\n"); exit(1); }
$u1=(string)($j1["user_id"] ?? "");
$u2=(string)($j2["user_id"] ?? "");
if ($u1 === "" || $u2 === "" || $u1 !== $u2) {
  fwrite(STDERR, "[ACCEPT_IDENT][FAIL] user_id mismatch: {$u1} vs {$u2}\n");
  exit(1);
}
echo "[ACCEPT_IDENT] PASS user_id={$u1}\n";
' >/dev/null

echo "[ACCEPT_IDENT] DONE OK"
