#!/usr/bin/env bash
set -euo pipefail

BACKEND_DIR="$(cd "$(dirname "${BASH_SOURCE[0]}")/.." && pwd)"
REPO_DIR="$(cd "${BACKEND_DIR}/.." && pwd)"

API="${API:-http://127.0.0.1:18000}"
SQLITE_DB="${SQLITE_DB:-${BACKEND_DIR}/database/database.sqlite}"

echo "[ACCEPT_ABUSE] repo=${REPO_DIR}"
echo "[ACCEPT_ABUSE] backend=${BACKEND_DIR}"
echo "[ACCEPT_ABUSE] API=${API}"
echo "[ACCEPT_ABUSE] SQLITE_DB=${SQLITE_DB}"

# 0) health
curl -sS "${API}/api/healthz" >/dev/null

# 0.5) ensure lookup_events exists
HAS_EVENTS="$(cd "${BACKEND_DIR}" && php artisan tinker --execute='
use Illuminate\Support\Facades\Schema;
echo Schema::hasTable("lookup_events") ? "1" : "0";
' 2>/dev/null | tail -n 1 | tr -d "\r\n")"

if [[ "${HAS_EVENTS}" != "1" ]]; then
  echo "[ACCEPT_ABUSE][FAIL] lookup_events table missing"
  exit 1
fi

# 1) provider login once (must write provider_login audit)
PROV_RAW="$(curl -sS -X POST "${API}/api/v0.3/auth/provider" \
  -H "Accept: application/json" -H "Content-Type: application/json" \
  -d '{"provider":"web","provider_code":"dev","anon_id":"accept_abuse_probe"}' || true)"
PROV_JSON="$(printf "%s\n" "${PROV_RAW}" | tail -n 1)"

if ! php -r '$j=json_decode(stream_get_contents(STDIN), true); exit((int) !($j && (($j["ok"] ?? false) === true)));' <<<"${PROV_JSON}"; then
  echo "[ACCEPT_ABUSE][FAIL] provider login failed: ${PROV_JSON}"
  exit 1
fi

PROVIDER_UID="$(php -r '$j=json_decode(stream_get_contents(STDIN), true); echo $j["provider_uid"] ?? "";' <<<"${PROV_JSON}")"
echo "[ACCEPT_ABUSE] provider_login ok provider_uid=${PROVIDER_UID}"

# 2) trigger rate limit on claim/report (invalid token)
RATE_LIMITED=0
for i in {1..45}; do
  code="$(curl -s -o /dev/null -w "%{http_code}" \
    -H "Accept: application/json" \
    "${API}/api/v0.3/claim/report?token=claim_invalid_token_for_abuse_test" || true)"
  if [[ "${code}" == "429" ]]; then
    RATE_LIMITED=1
    break
  fi
done

if [[ "${RATE_LIMITED}" != "1" ]]; then
  echo "[ACCEPT_ABUSE][FAIL] rate limit not triggered on claim/report (expected at least one 429)"
  exit 1
fi
echo "[ACCEPT_ABUSE] rate limit triggered"

# 3) validate audits exist (provider_login + claim_report invalid token)
CHECK_OUT="$(cd "${BACKEND_DIR}" && php artisan tinker --execute='
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

if (!Schema::hasTable("lookup_events")) {
  echo "PROVIDER_COUNT=0\n";
  echo "CLAIM_INVALID_COUNT=0\n";
  return;
}

$providerCount = DB::table("lookup_events")->where("method", "provider_login")->count();
$claimInvalidCount = DB::table("lookup_events")
  ->where("method", "claim_report")
  ->where("meta_json", "like", "%INVALID_TOKEN%")
  ->count();

echo "PROVIDER_COUNT={$providerCount}\n";
echo "CLAIM_INVALID_COUNT={$claimInvalidCount}\n";

$rows = DB::table("lookup_events")
  ->whereIn("method", ["provider_login","claim_report"])
  ->orderByDesc("created_at")
  ->limit(12)
  ->get(["method","success","ip","meta_json","created_at"]);

foreach ($rows as $r) {
  echo "METHOD={$r->method} SUCCESS={$r->success} IP={$r->ip} META={$r->meta_json}\n";
}
' 2>/dev/null)"

PROVIDER_COUNT="$(printf "%s" "${CHECK_OUT}" | sed -n 's/^PROVIDER_COUNT=//p' | tail -n 1 | tr -d "\r\n")"
CLAIM_INVALID_COUNT="$(printf "%s" "${CHECK_OUT}" | sed -n 's/^CLAIM_INVALID_COUNT=//p' | tail -n 1 | tr -d "\r\n")"

if [[ -z "${PROVIDER_COUNT}" || "${PROVIDER_COUNT}" == "0" ]]; then
  echo "${CHECK_OUT}"
  echo "[ACCEPT_ABUSE][FAIL] missing provider_login audit"
  exit 1
fi

if [[ -z "${CLAIM_INVALID_COUNT}" || "${CLAIM_INVALID_COUNT}" == "0" ]]; then
  echo "${CHECK_OUT}"
  echo "[ACCEPT_ABUSE][FAIL] missing claim_report INVALID_TOKEN audit"
  exit 1
fi

# print the tail for debugging visibility
printf "%s\n" "${CHECK_OUT}" | sed -n '3,999p'

echo "[ACCEPT_ABUSE] DONE OK"
