#!/usr/bin/env bash
set -euo pipefail

ROOT_DIR="$(cd "$(dirname "${BASH_SOURCE[0]}")/../.." && pwd)"
BACKEND_DIR="${ROOT_DIR}/backend"
RUN_DIR="${BACKEND_DIR}/artifacts/pr19"
LOG_DIR="${RUN_DIR}"
SERVE_LOG="${LOG_DIR}/server.log"
VERIFY_LOG="${LOG_DIR}/verify.log"
ROUTES_TXT="${LOG_DIR}/routes.txt"
ENV_TXT="${LOG_DIR}/env.txt"
SQLITE_DB="${RUN_DIR}/pr19.sqlite"
export FAP_PACKS_DRIVER="${FAP_PACKS_DRIVER:-local}"
export FAP_PACKS_ROOT="${FAP_PACKS_ROOT:-${ROOT_DIR}/content_packages}"
export FAP_DEFAULT_REGION="${FAP_DEFAULT_REGION:-CN_MAINLAND}"
export FAP_DEFAULT_LOCALE="${FAP_DEFAULT_LOCALE:-zh-CN}"

HOST="127.0.0.1"
PORT_BASE=18000
PORT_END=18010
PORT=""
API=""

mkdir -p "${RUN_DIR}"

exec > >(tee "${VERIFY_LOG}") 2>&1

fail() { echo "[PR19][FAIL] $*" >&2; exit 1; }

run_artisan() {
  DB_CONNECTION=sqlite DB_DATABASE="${SQLITE_DB}" APP_ENV=testing php artisan "$@"
}

port_in_use() {
  local port="$1"
  lsof -iTCP:"${port}" -sTCP:LISTEN -n -P >/dev/null 2>&1
}

pick_port() {
  local port
  for port in $(seq "${PORT_BASE}" "${PORT_END}"); do
    if ! port_in_use "${port}"; then
      PORT="${port}"
      return 0
    fi
  done
  PORT="${PORT_BASE}"
  return 1
}

start_server() {
  local port="$1"
  echo "[PR19] starting server on :${port}"
  DB_CONNECTION=sqlite DB_DATABASE="${SQLITE_DB}" APP_ENV=testing \
    php artisan serve --host="${HOST}" --port="${port}" >"${SERVE_LOG}" 2>&1 &
  SERVE_PID=$!
}

wait_health() {
  local url="$1"
  for _ in $(seq 1 60); do
    if curl -fsS "${url}" >/dev/null 2>&1; then
      return 0
    fi
    sleep 0.25
  done
  return 1
}

cleanup_port() {
  local port="$1"
  local pid
  pid="$(lsof -ti tcp:"${port}" 2>/dev/null || true)"
  if [[ -n "${pid}" ]]; then
    echo "[PR19] port ${port} in use by pid=${pid}, killing"
    kill "${pid}" >/dev/null 2>&1 || true
    sleep 1
  fi
}

cleanup() {
  if [[ -n "${SERVE_PID:-}" ]] && kill -0 "${SERVE_PID}" >/dev/null 2>&1; then
    kill "${SERVE_PID}" >/dev/null 2>&1 || true
  fi
}
trap cleanup EXIT

cd "${BACKEND_DIR}"

rm -f "${SQLITE_DB}"

echo "[PR19] migrate"
run_artisan migrate --force

echo "[PR19] seed scales + commerce"
run_artisan db:seed --class=Database\\Seeders\\ScaleRegistrySeeder
run_artisan db:seed --class=Database\\Seeders\\Pr17SimpleScoreDemoSeeder
run_artisan db:seed --class=Database\\Seeders\\Pr19CommerceSeeder

run_artisan tinker --execute='
use Illuminate\Support\Facades\DB;
$row = DB::table("scales_registry")->where("org_id",0)->where("code","SIMPLE_SCORE_DEMO")->first();
if ($row) {
  $commercial = $row->commercial_json ?? null;
  if (is_string($commercial)) {
    $decoded = json_decode($commercial, true);
    $commercial = is_array($decoded) ? $decoded : null;
  }
  if (!is_array($commercial)) { $commercial = []; }
  $commercial["credit_benefit_code"] = "MBTI_CREDIT";
  DB::table("scales_registry")->where("org_id",0)->where("code","SIMPLE_SCORE_DEMO")->update([
    "commercial_json" => json_encode($commercial, JSON_UNESCAPED_UNICODE),
    "updated_at" => now(),
  ]);
}
' >/dev/null 2>&1 || true

run_artisan route:list >"${ROUTES_TXT}"

pick_port || true
API="http://${HOST}:${PORT}"

echo "port=${PORT}" >"${ENV_TXT}"
echo "api=${API}" >>"${ENV_TXT}"
echo "db=${SQLITE_DB}" >>"${ENV_TXT}"

start_server "${PORT}"
if ! wait_health "${API}/api/v0.2/health"; then
  echo "[PR19][WARN] server not healthy on port ${PORT}, retry once"
  cleanup_port "${PORT}"
  start_server "${PORT}"
  wait_health "${API}/api/v0.2/health" || fail "server failed to start on port ${PORT}"
fi

echo "[PR19] server ok on ${API}"

USER_JSON="${RUN_DIR}/curl_user_seed.json"
run_artisan tinker --execute='
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
$uid = 9001;
if (!DB::table("users")->where("id", $uid)->exists()) {
  DB::table("users")->insert([
    "id" => $uid,
    "name" => "PR19 User",
    "email" => "pr19_user@example.com",
    "password" => "secret",
    "created_at" => now(),
    "updated_at" => now(),
  ]);
}
$token = "fm_" . (string) Str::uuid();
DB::table("fm_tokens")->insert([
  "token" => $token,
  "anon_id" => "anon_pr19",
  "user_id" => $uid,
  "expires_at" => now()->addDays(1),
  "created_at" => now(),
  "updated_at" => now(),
]);
print(json_encode(["user_id" => $uid, "token" => $token]));
' | tail -n 1 >"${USER_JSON}"

TOKEN="$(php -r '$j=json_decode(file_get_contents($argv[1]), true); echo $j["token"] ?? "";' "${USER_JSON}")"
[[ -n "${TOKEN}" ]] || fail "missing fm_token"

echo "token=${TOKEN}" >>"${ENV_TXT}"

echo "[PR19] create org"
ORG_JSON="${RUN_DIR}/curl_org_create.json"
http_code=$(curl -sS -L -o "${ORG_JSON}" -w "%{http_code}" \
  -X POST "${API}/api/v0.3/orgs" \
  -H "Content-Type: application/json" -H "Accept: application/json" \
  -H "Authorization: Bearer ${TOKEN}" \
  -d '{"name":"PR19 Org"}' || true)
[[ "${http_code}" == "200" || "${http_code}" == "201" ]] || fail "org create failed (http=${http_code})"
ORG_ID="$(php -r '$j=json_decode(file_get_contents($argv[1]), true); echo $j["org"]["org_id"] ?? "";' "${ORG_JSON}")"
[[ -n "${ORG_ID}" ]] || fail "missing org_id"

echo "org_id=${ORG_ID}" >>"${ENV_TXT}"

CURL_SKUS="${RUN_DIR}/curl_skus.json"
http_code=$(curl -sS -L -o "${CURL_SKUS}" -w "%{http_code}" \
  "${API}/api/v0.3/skus?scale=MBTI" \
  -H "Accept: application/json" \
  -H "Authorization: Bearer ${TOKEN}" \
  -H "X-Org-Id: ${ORG_ID}" || true)
[[ "${http_code}" == "200" ]] || fail "skus failed (http=${http_code})"

CURL_ORDER="${RUN_DIR}/curl_order.json"
http_code=$(curl -sS -L -o "${CURL_ORDER}" -w "%{http_code}" \
  -X POST "${API}/api/v0.3/orders" \
  -H "Content-Type: application/json" -H "Accept: application/json" \
  -H "Authorization: Bearer ${TOKEN}" \
  -H "X-Org-Id: ${ORG_ID}" \
  -d '{"sku":"MBTI_CREDIT","quantity":1,"provider":"stub"}' || true)
[[ "${http_code}" == "200" ]] || fail "create order failed (http=${http_code})"
ORDER_NO="$(php -r '$j=json_decode(file_get_contents($argv[1]), true); echo $j["order_no"] ?? "";' "${CURL_ORDER}")"
[[ -n "${ORDER_NO}" ]] || fail "missing order_no"

echo "order_no=${ORDER_NO}" >>"${ENV_TXT}"

PROVIDER_EVENT_ID="evt_pr19_1"
for i in $(seq 1 10); do
  OUT="${RUN_DIR}/curl_webhook_${i}.json"
  http_code=$(curl -sS -L -o "${OUT}" -w "%{http_code}" \
    -X POST "${API}/api/v0.3/webhooks/payment/stub" \
    -H "Content-Type: application/json" -H "Accept: application/json" \
    -H "Authorization: Bearer ${TOKEN}" \
    -H "X-Org-Id: ${ORG_ID}" \
    -d "{\"provider_event_id\":\"${PROVIDER_EVENT_ID}\",\"order_no\":\"${ORDER_NO}\",\"external_trade_no\":\"trade_pr19\",\"amount_cents\":4990,\"currency\":\"USD\"}" || true)
  [[ "${http_code}" == "200" ]] || fail "webhook ${i} failed (http=${http_code})"
  sleep 0.1
  if [[ "${i}" -ne 1 && "${i}" -ne 10 ]]; then
    rm -f "${OUT}" || true
  fi
done

echo "provider_event_id=${PROVIDER_EVENT_ID}" >>"${ENV_TXT}"

CURL_WALLETS_BEFORE="${RUN_DIR}/curl_wallets_before.json"
http_code=$(curl -sS -L -o "${CURL_WALLETS_BEFORE}" -w "%{http_code}" \
  "${API}/api/v0.3/orgs/${ORG_ID}/wallets" \
  -H "Accept: application/json" \
  -H "Authorization: Bearer ${TOKEN}" \
  -H "X-Org-Id: ${ORG_ID}" || true)
[[ "${http_code}" == "200" ]] || fail "wallets before failed (http=${http_code})"

CURL_LEDGER="${RUN_DIR}/curl_wallet_ledger.json"
http_code=$(curl -sS -L -o "${CURL_LEDGER}" -w "%{http_code}" \
  "${API}/api/v0.3/orgs/${ORG_ID}/wallets/MBTI_CREDIT/ledger?limit=20" \
  -H "Accept: application/json" \
  -H "Authorization: Bearer ${TOKEN}" \
  -H "X-Org-Id: ${ORG_ID}" || true)
[[ "${http_code}" == "200" ]] || fail "ledger failed (http=${http_code})"

CURL_ATTEMPT_START="${RUN_DIR}/curl_attempt_start.json"
http_code=$(curl -sS -L -o "${CURL_ATTEMPT_START}" -w "%{http_code}" \
  -X POST "${API}/api/v0.3/attempts/start" \
  -H "Content-Type: application/json" -H "Accept: application/json" \
  -H "Authorization: Bearer ${TOKEN}" \
  -H "X-Org-Id: ${ORG_ID}" \
  -d '{"scale_code":"SIMPLE_SCORE_DEMO"}' || true)
[[ "${http_code}" == "200" ]] || fail "attempt start failed (http=${http_code})"
ATTEMPT_ID="$(php -r '$j=json_decode(file_get_contents($argv[1]), true); echo $j["attempt_id"] ?? "";' "${CURL_ATTEMPT_START}")"
[[ -n "${ATTEMPT_ID}" ]] || fail "missing attempt_id"

echo "attempt_id=${ATTEMPT_ID}" >>"${ENV_TXT}"

ANSWERS_PAYLOAD='{"attempt_id":"'"${ATTEMPT_ID}"'","answers":[{"question_id":"SS-001","code":"5"},{"question_id":"SS-002","code":"4"},{"question_id":"SS-003","code":"3"},{"question_id":"SS-004","code":"2"},{"question_id":"SS-005","code":"1"}],"duration_ms":120000}'

CURL_SUBMIT="${RUN_DIR}/curl_submit.json"
http_code=$(curl -sS -L -o "${CURL_SUBMIT}" -w "%{http_code}" \
  -X POST "${API}/api/v0.3/attempts/submit" \
  -H "Content-Type: application/json" -H "Accept: application/json" \
  -H "Authorization: Bearer ${TOKEN}" \
  -H "X-Org-Id: ${ORG_ID}" \
  -d "${ANSWERS_PAYLOAD}" || true)
[[ "${http_code}" == "200" ]] || fail "submit failed (http=${http_code})"

CURL_SUBMIT_DUP="${RUN_DIR}/curl_submit_dup.json"
http_code=$(curl -sS -L -o "${CURL_SUBMIT_DUP}" -w "%{http_code}" \
  -X POST "${API}/api/v0.3/attempts/submit" \
  -H "Content-Type: application/json" -H "Accept: application/json" \
  -H "Authorization: Bearer ${TOKEN}" \
  -H "X-Org-Id: ${ORG_ID}" \
  -d "${ANSWERS_PAYLOAD}" || true)
[[ "${http_code}" == "200" ]] || fail "submit duplicate failed (http=${http_code})"

CURL_WALLETS_AFTER="${RUN_DIR}/curl_wallets_after.json"
http_code=$(curl -sS -L -o "${CURL_WALLETS_AFTER}" -w "%{http_code}" \
  "${API}/api/v0.3/orgs/${ORG_ID}/wallets" \
  -H "Accept: application/json" \
  -H "Authorization: Bearer ${TOKEN}" \
  -H "X-Org-Id: ${ORG_ID}" || true)
[[ "${http_code}" == "200" ]] || fail "wallets after failed (http=${http_code})"

BAL_BEFORE=$(php -r '
$j=json_decode(file_get_contents($argv[1]), true);
$items=$j["items"] ?? [];
$bal="";
foreach ($items as $it) { if (($it["benefit_code"] ?? "") === "MBTI_CREDIT") { $bal=$it["balance"] ?? ""; break; } }
echo $bal;' "${CURL_WALLETS_BEFORE}")

BAL_AFTER=$(php -r '
$j=json_decode(file_get_contents($argv[1]), true);
$items=$j["items"] ?? [];
$bal="";
foreach ($items as $it) { if (($it["benefit_code"] ?? "") === "MBTI_CREDIT") { $bal=$it["balance"] ?? ""; break; } }
echo $bal;' "${CURL_WALLETS_AFTER}")

COUNTS_JSON="${RUN_DIR}/curl_counts.json"
run_artisan tinker --execute='
use Illuminate\Support\Facades\DB;
$payload = [
  "payment_events" => DB::table("payment_events")->count(),
  "ledger_topup" => DB::table("benefit_wallet_ledgers")->where("reason","topup")->count(),
  "ledger_consume" => DB::table("benefit_wallet_ledgers")->where("reason","consume")->count(),
  "consumptions" => DB::table("benefit_consumptions")->count(),
];
print(json_encode($payload));
' | tail -n 1 >"${COUNTS_JSON}"

LEDGER_TOPUP=$(php -r '$j=json_decode(file_get_contents($argv[1]), true); echo $j["ledger_topup"] ?? "";' "${COUNTS_JSON}")
LEDGER_CONSUME=$(php -r '$j=json_decode(file_get_contents($argv[1]), true); echo $j["ledger_consume"] ?? "";' "${COUNTS_JSON}")
CONSUME_COUNT=$(php -r '$j=json_decode(file_get_contents($argv[1]), true); echo $j["consumptions"] ?? "";' "${COUNTS_JSON}")

{
  echo "PR19 Commerce v2 Verify Summary";
  echo "api=${API}";
  echo "org_id=${ORG_ID}";
  echo "order_no=${ORDER_NO}";
  echo "provider_event_id=${PROVIDER_EVENT_ID}";
  echo "balance_before=${BAL_BEFORE}";
  echo "balance_after=${BAL_AFTER}";
  echo "ledger_topup_count=${LEDGER_TOPUP}";
  echo "ledger_consume_count=${LEDGER_CONSUME}";
  echo "consume_count=${CONSUME_COUNT}";
  echo "endpoints=skus,orders,webhooks,wallets,ledger,attempt_start,attempt_submit";
  echo "curl=\n  ${CURL_SKUS}\n  ${CURL_ORDER}\n  ${RUN_DIR}/curl_webhook_1.json\n  ${RUN_DIR}/curl_webhook_10.json\n  ${CURL_WALLETS_BEFORE}\n  ${CURL_LEDGER}\n  ${CURL_ATTEMPT_START}\n  ${CURL_SUBMIT}\n  ${CURL_SUBMIT_DUP}\n  ${CURL_WALLETS_AFTER}";
  echo "tables=skus,orders,payment_events,benefit_wallets,benefit_wallet_ledgers,benefit_consumptions";
} | tee "${RUN_DIR}/summary.txt"

echo "[PR19] verify complete ✅"
