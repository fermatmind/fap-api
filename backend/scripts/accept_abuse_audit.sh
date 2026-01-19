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
curl -sS "${API}/api/v0.2/health" >/dev/null

# 1) sanity: lookup_events table exists
HAS_TABLE="$(cd "${BACKEND_DIR}" && php artisan tinker --execute='
use Illuminate\Support\Facades\DB;

try {
  $has = DB::select("SELECT name FROM sqlite_master WHERE type=\"table\" AND name=\"lookup_events\" LIMIT 1");
  echo $has ? "1" : "0";
} catch (\Throwable $e) {
  echo "0";
}
')"
if [[ "${HAS_TABLE}" != "1" ]]; then
  echo "[ACCEPT_ABUSE][FAIL] lookup_events table missing"
  exit 1
fi

# 2) generate a success audit (provider login)
curl -sS -X POST "${API}/api/v0.2/auth/provider" \
  -H "Content-Type: application/json" -H "Accept: application/json" \
  -d '{"provider":"web","provider_code":"dev","anon_id":"accept_abuse"}' >/dev/null

# 3) rate limit claim/report (expect 429 after ~30/min)
HIT_429="0"
for i in $(seq 1 40); do
  status="$(curl -sS -o /dev/null -w "%{http_code}" \
    "${API}/api/v0.2/claim/report?token=invalid")"
  if [[ "${status}" == "429" ]]; then
    HIT_429="1"
    break
  fi
done

if [[ "${HIT_429}" != "1" ]]; then
  echo "[ACCEPT_ABUSE][FAIL] rate limit not triggered (expected 429)"
  exit 1
fi
echo "[ACCEPT_ABUSE] rate limit triggered"

# 4) audit entries present
AUDIT_OUT="$(cd "${BACKEND_DIR}" && php artisan tinker --execute='
use Illuminate\Support\Facades\DB;

$rows = DB::table("lookup_events")
  ->orderByDesc("created_at")
  ->limit(10)
  ->get(["method","success","ip","meta_json","created_at"]);

foreach ($rows as $r) {
  $m = (string) ($r->method ?? "");
  $s = (string) ($r->success ?? "");
  $ip = (string) ($r->ip ?? "");
  $meta = (string) ($r->meta_json ?? "");
  echo "METHOD={$m} SUCCESS={$s} IP={$ip} META={$meta}\n";
}
')"

echo "${AUDIT_OUT}"

if ! echo "${AUDIT_OUT}" | grep -q "METHOD=claim_report"; then
  echo "[ACCEPT_ABUSE][FAIL] missing claim_report audit"
  exit 1
fi
if ! echo "${AUDIT_OUT}" | grep -q "METHOD=provider_login"; then
  echo "[ACCEPT_ABUSE][FAIL] missing provider_login audit"
  exit 1
fi
if ! echo "${AUDIT_OUT}" | grep -q "META=.*error"; then
  echo "[ACCEPT_ABUSE][FAIL] audit meta_json missing error"
  exit 1
fi

echo "[ACCEPT_ABUSE] DONE OK"
