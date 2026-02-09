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
export FAP_PAYMENT_FALLBACK_PROVIDER=billing
export BILLING_WEBHOOK_SECRET="${BILLING_WEBHOOK_SECRET:-billing_secret}"

REPO_DIR="$(cd "$(dirname "${BASH_SOURCE[0]}")/../.." && pwd)"
BACKEND_DIR="${REPO_DIR}/backend"
ART_DIR="${BACKEND_DIR}/artifacts/pr29"
LOG_DIR="${ART_DIR}/logs"
SERVE_PORT="${SERVE_PORT:-1829}"
HOST="127.0.0.1"
API="http://${HOST}:${SERVE_PORT}"
DB_PATH="${DB_DATABASE:-/tmp/pr29.sqlite}"

mkdir -p "${ART_DIR}" "${LOG_DIR}"
mkdir -p "${BACKEND_DIR}/storage/framework/cache" \
  "${BACKEND_DIR}/storage/framework/sessions" \
  "${BACKEND_DIR}/storage/framework/views" \
  "${BACKEND_DIR}/storage/framework/testing" \
  "${BACKEND_DIR}/bootstrap/cache"

fail() { echo "[PR29][FAIL] $*" >&2; exit 1; }

cleanup_port() {
  local port="$1"
  lsof -ti tcp:"${port}" | xargs -r kill -9 || true
}

wait_health() {
  local url="$1"
  for _ in $(seq 1 80); do
    if curl -fsS "${url}" >/dev/null 2>&1; then
      return 0
    fi
    sleep 0.2
  done
  return 1
}

billing_sig() {
  local ts="$1"
  local body="$2"
  printf "%s.%s" "$ts" "$body" | openssl dgst -sha256 -hmac "$BILLING_WEBHOOK_SECRET" | awk '{print $2}'
}

post_billing_webhook() {
  local body="$1"
  local out="$2"
  local ts
  ts="$(date +%s)"
  local sig
  sig="$(billing_sig "$ts" "$body")"
  curl -sS -L -o "$out" -w "%{http_code}" \
    -X POST "${API}/api/v0.3/webhooks/payment/billing" \
    -H "Content-Type: application/json" -H "Accept: application/json" \
    -H "X-Billing-Timestamp: ${ts}" \
    -H "X-Billing-Signature: ${sig}" \
    --data-binary "$body"
}

cleanup() {
  cp -f "${BACKEND_DIR}/storage/logs/laravel.log" "${LOG_DIR}/laravel.log" 2>/dev/null || true
  if [[ -n "${SERVE_PID:-}" ]] && kill -0 "${SERVE_PID}" >/dev/null 2>&1; then
    kill "${SERVE_PID}" >/dev/null 2>&1 || true
  fi
}
trap cleanup EXIT

table_exists() {
  local tbl="$1"
  sqlite3 "$DB_PATH" "SELECT name FROM sqlite_master WHERE type='table' AND name='${tbl}' LIMIT 1;" \
    | tr -d '\r' | grep -qx "${tbl}"
}

col_exists() {
  local tbl="$1"
  local col="$2"
  sqlite3 "$DB_PATH" "PRAGMA table_info(${tbl});" \
    | awk -F'|' '{print $2}' | tr -d '\r' | grep -qx "${col}"
}

# Always treat attempt id as string (UUID) in sqlite
sql_quote() {
  # escape single quotes for sqlite
  printf "%s" "$1" | sed "s/'/''/g"
}

delete_attempt_scoped_rows() {
  local tbl="$1"
  local org_id="$2"
  local attempt_id="$3"
  local benefit_code="$4"

  table_exists "${tbl}" || return 0

  local aid
  aid="$(sql_quote "${attempt_id}")"

  local clauses=()

  if col_exists "${tbl}" "scope" && col_exists "${tbl}" "scope_id"; then
    clauses+=("scope='attempt'")
    clauses+=("scope_id='${aid}'")
  elif col_exists "${tbl}" "attempt_id"; then
    clauses+=("attempt_id='${aid}'")
  elif col_exists "${tbl}" "target_attempt_id"; then
    clauses+=("target_attempt_id='${aid}'")
  else
    fail "table ${tbl} has no attempt scope column"
  fi

  if col_exists "${tbl}" "org_id"; then
    clauses+=("org_id=${org_id}")
  fi
  if col_exists "${tbl}" "benefit_code"; then
    clauses+=("benefit_code='${benefit_code}'")
  fi

  local cond=""
  local i=0
  for c in "${clauses[@]}"; do
    if [[ $i -eq 0 ]]; then
      cond="${c}"
    else
      cond="${cond} AND ${c}"
    fi
    i=$((i+1))
  done

  sqlite3 "$DB_PATH" "
PRAGMA busy_timeout=5000;
DELETE FROM ${tbl} WHERE ${cond};
SELECT changes();
"
}

topup_wallet() {
  local org_id="$1"
  local benefit_code="$2"
  local amount="$3"
  table_exists "benefit_wallets" || fail "missing benefit_wallets table"

  sqlite3 "$DB_PATH" "
PRAGMA busy_timeout=5000;
INSERT INTO benefit_wallets (org_id, benefit_code, balance, created_at, updated_at)
VALUES (${org_id}, '${benefit_code}', ${amount}, datetime('now'), datetime('now'))
ON CONFLICT(org_id, benefit_code) DO UPDATE SET
  balance=excluded.balance,
  updated_at=datetime('now');
"
}

reset_wallet_zero() {
  local org_id="$1"
  local benefit_code="$2"
  table_exists "benefit_wallets" || return 0

  sqlite3 "$DB_PATH" "
PRAGMA busy_timeout=5000;
UPDATE benefit_wallets
SET balance=0, updated_at=datetime('now')
WHERE org_id=${org_id} AND benefit_code='${benefit_code}';
SELECT changes();
"
}

cleanup_port "${SERVE_PORT}"
cleanup_port 18000

export APP_ENV=testing
export DB_CONNECTION=sqlite
export DB_DATABASE="${DB_PATH}"

rm -f "${DB_DATABASE}"
touch "${DB_DATABASE}"

cd "${BACKEND_DIR}"

php artisan migrate --force
php artisan db:seed --force --class=Database\\Seeders\\ScaleRegistrySeeder
php artisan db:seed --force --class=Database\\Seeders\\Pr17SimpleScoreDemoSeeder
php artisan db:seed --force --class=Database\\Seeders\\Pr19CommerceSeeder

# Ensure SIMPLE_SCORE_DEMO uses MBTI_REPORT_FULL as report benefit gate
php artisan tinker --execute='
use Illuminate\Support\Facades\DB;
$row = DB::table("scales_registry")->where("org_id", 0)->where("code", "SIMPLE_SCORE_DEMO")->first();
if (!$row) { throw new RuntimeException("missing scales_registry SIMPLE_SCORE_DEMO"); }
$commercial = $row->commercial_json ?? null;
if (is_string($commercial)) {
  $decoded = json_decode($commercial, true);
  $commercial = is_array($decoded) ? $decoded : null;
}
if (!is_array($commercial)) { $commercial = []; }
$commercial["report_benefit_code"] = "MBTI_REPORT_FULL";
DB::table("scales_registry")
  ->where("org_id", 0)
  ->where("code", "SIMPLE_SCORE_DEMO")
  ->update([
    "commercial_json" => json_encode($commercial, JSON_UNESCAPED_UNICODE),
    "updated_at" => now(),
  ]);
' >/dev/null

USER_JSON="${ART_DIR}/user.json"
php artisan tinker --execute='
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
$uid = 9029;
if (!DB::table("users")->where("id", $uid)->exists()) {
  DB::table("users")->insert([
    "id" => $uid,
    "name" => "PR29 User",
    "email" => "pr29_user@example.com",
    "password" => "secret",
    "created_at" => now(),
    "updated_at" => now(),
  ]);
}
$orgId = 2929;
if (!DB::table("organizations")->where("id", $orgId)->exists()) {
  DB::table("organizations")->insert([
    "id" => $orgId,
    "name" => "PR29 Org",
    "owner_user_id" => $uid,
    "created_at" => now(),
    "updated_at" => now(),
  ]);
}
if (!DB::table("organization_members")->where("org_id", $orgId)->where("user_id", $uid)->exists()) {
  DB::table("organization_members")->insert([
    "org_id" => $orgId,
    "user_id" => $uid,
    "role" => "owner",
    "joined_at" => now(),
    "created_at" => now(),
    "updated_at" => now(),
  ]);
}
$token = "fm_" . (string) Str::uuid();
DB::table("fm_tokens")->insert([
  "token" => $token,
  "anon_id" => "anon_pr29",
  "user_id" => $uid,
  "expires_at" => now()->addDays(1),
  "created_at" => now(),
  "updated_at" => now(),
]);
print(json_encode(["user_id" => $uid, "org_id" => $orgId, "token" => $token]));
' | tail -n 1 >"${USER_JSON}"

TOKEN="$(php -r '$j=json_decode(file_get_contents($argv[1]), true); echo $j["token"] ?? "";' "${USER_JSON}")"
ORG_ID="$(php -r '$j=json_decode(file_get_contents($argv[1]), true); echo $j["org_id"] ?? "";' "${USER_JSON}")"
[[ -n "${TOKEN}" ]] || fail "missing fm_token"
[[ -n "${ORG_ID}" ]] || fail "missing org_id"
echo "${ORG_ID}" > "${ART_DIR}/org_id.txt"

php artisan serve --host="${HOST}" --port="${SERVE_PORT}" >"${LOG_DIR}/server.log" 2>&1 &
SERVE_PID=$!
wait_health "${API}/api/v0.2/health" || fail "server not healthy on ${API}"

SKUS_JSON="${ART_DIR}/skus.json"
http_code=$(curl -sS -L -o "${SKUS_JSON}" -w "%{http_code}" \
  "${API}/api/v0.3/skus?scale=MBTI" \
  -H "Accept: application/json" \
  -H "Authorization: Bearer ${TOKEN}" \
  -H "X-Org-Id: ${ORG_ID}" || true)
[[ "${http_code}" == "200" ]] || fail "skus failed (http=${http_code})"

ATTEMPT_START_JSON="${ART_DIR}/attempt_start.json"
http_code=$(curl -sS -L -o "${ATTEMPT_START_JSON}" -w "%{http_code}" \
  -X POST "${API}/api/v0.3/attempts/start" \
  -H "Content-Type: application/json" -H "Accept: application/json" \
  -H "Authorization: Bearer ${TOKEN}" \
  -H "X-Org-Id: ${ORG_ID}" \
  -d '{"scale_code":"SIMPLE_SCORE_DEMO"}' || true)
[[ "${http_code}" == "200" ]] || fail "attempt start failed (http=${http_code})"
ATTEMPT_ID="$(php -r '$j=json_decode(file_get_contents($argv[1]), true); echo $j["attempt_id"] ?? "";' "${ATTEMPT_START_JSON}")"
[[ -n "${ATTEMPT_ID}" ]] || fail "missing attempt_id"
echo "${ATTEMPT_ID}" > "${ART_DIR}/attempt_id.txt"

ANSWERS_PAYLOAD='{"attempt_id":"'"${ATTEMPT_ID}"'","answers":[{"question_id":"SS-001","code":"5"},{"question_id":"SS-002","code":"4"},{"question_id":"SS-003","code":"3"},{"question_id":"SS-004","code":"2"},{"question_id":"SS-005","code":"1"}],"duration_ms":120000}'

# Topup credits ONLY to pass submit gate
topup_wallet "${ORG_ID}" "MBTI_CREDIT" 999999
sqlite3 "$DB_PATH" "SELECT org_id, benefit_code, balance FROM benefit_wallets WHERE org_id=${ORG_ID} AND benefit_code='MBTI_CREDIT' LIMIT 1;" \
  > "${ART_DIR}/wallet_before_submit.txt" || true

SUBMIT_JSON="${ART_DIR}/submit.json"
http_code=$(curl -sS -L -o "${SUBMIT_JSON}" -w "%{http_code}" \
  -X POST "${API}/api/v0.3/attempts/submit" \
  -H "Content-Type: application/json" -H "Accept: application/json" \
  -H "Authorization: Bearer ${TOKEN}" \
  -H "X-Org-Id: ${ORG_ID}" \
  -d "${ANSWERS_PAYLOAD}" || true)
[[ "${http_code}" == "200" ]] || fail "submit failed (http=${http_code})"

# Force baseline: locked before payment
# 1) zero wallet
reset_wallet_zero "${ORG_ID}" "MBTI_CREDIT" > "${ART_DIR}/wallet_reset_changes.txt" || true
sqlite3 "$DB_PATH" "SELECT org_id, benefit_code, balance FROM benefit_wallets WHERE org_id=${ORG_ID} AND benefit_code='MBTI_CREDIT' LIMIT 1;" \
  > "${ART_DIR}/wallet_after_submit.txt" || true

# 2) delete auto-granted report unlock + the consumed credit entry for this attempt
delete_attempt_scoped_rows "benefit_grants" "${ORG_ID}" "${ATTEMPT_ID}" "MBTI_REPORT_FULL" \
  > "${ART_DIR}/deleted_benefit_grants.txt" || true
delete_attempt_scoped_rows "benefit_consumptions" "${ORG_ID}" "${ATTEMPT_ID}" "MBTI_CREDIT" \
  > "${ART_DIR}/deleted_benefit_consumptions.txt" || true

# Dump proof after cleanup
table_exists "benefit_grants" && sqlite3 "$DB_PATH" "SELECT * FROM benefit_grants WHERE org_id=${ORG_ID};" \
  > "${ART_DIR}/benefit_grants_after_cleanup.txt" || true
table_exists "benefit_consumptions" && sqlite3 "$DB_PATH" "SELECT * FROM benefit_consumptions WHERE org_id=${ORG_ID};" \
  > "${ART_DIR}/benefit_consumptions_after_cleanup.txt" || true

REPORT_BEFORE_JSON="${ART_DIR}/report_before.json"
http_code=$(curl -sS -L -o "${REPORT_BEFORE_JSON}" -w "%{http_code}" \
  "${API}/api/v0.3/attempts/${ATTEMPT_ID}/report" \
  -H "Accept: application/json" \
  -H "Authorization: Bearer ${TOKEN}" \
  -H "X-Org-Id: ${ORG_ID}" || true)
[[ "${http_code}" == "200" ]] || fail "report before failed (http=${http_code})"
LOCKED_BEFORE="$(php -r '$j=json_decode(file_get_contents($argv[1]), true); echo !empty($j["locked"]) ? "1" : "0";' "${REPORT_BEFORE_JSON}")"
[[ "${LOCKED_BEFORE}" == "1" ]] || fail "expected report locked before payment"
echo "${LOCKED_BEFORE}" > "${ART_DIR}/locked_before.txt"

IDEMPOTENCY_KEY="idem_pr29_1"
ORDER_JSON="${ART_DIR}/order_create_1.json"
http_code=$(curl -sS -L -o "${ORDER_JSON}" -w "%{http_code}" \
  -X POST "${API}/api/v0.3/orders" \
  -H "Content-Type: application/json" -H "Accept: application/json" \
  -H "Authorization: Bearer ${TOKEN}" \
  -H "X-Org-Id: ${ORG_ID}" \
  -H "Idempotency-Key: ${IDEMPOTENCY_KEY}" \
  -d '{"sku":"MBTI_REPORT_FULL_199","quantity":1,"provider":"billing","target_attempt_id":"'"${ATTEMPT_ID}"'"}' || true)
[[ "${http_code}" == "200" ]] || fail "create order failed (http=${http_code})"
ORDER_NO="$(php -r '$j=json_decode(file_get_contents($argv[1]), true); echo $j["order_no"] ?? "";' "${ORDER_JSON}")"
[[ -n "${ORDER_NO}" ]] || fail "missing order_no"
echo "${ORDER_NO}" > "${ART_DIR}/order_no.txt"

ORDER_JSON_2="${ART_DIR}/order_create_2.json"
http_code=$(curl -sS -L -o "${ORDER_JSON_2}" -w "%{http_code}" \
  -X POST "${API}/api/v0.3/orders" \
  -H "Content-Type: application/json" -H "Accept: application/json" \
  -H "Authorization: Bearer ${TOKEN}" \
  -H "X-Org-Id: ${ORG_ID}" \
  -H "Idempotency-Key: ${IDEMPOTENCY_KEY}" \
  -d '{"sku":"MBTI_REPORT_FULL_199","quantity":1,"provider":"billing","target_attempt_id":"'"${ATTEMPT_ID}"'"}' || true)
[[ "${http_code}" == "200" ]] || fail "create order idempotent failed (http=${http_code})"
ORDER_NO_2="$(php -r '$j=json_decode(file_get_contents($argv[1]), true); echo $j["order_no"] ?? "";' "${ORDER_JSON_2}")"
[[ "${ORDER_NO_2}" == "${ORDER_NO}" ]] || fail "idempotency_key mismatch"

WEBHOOK_PAID_JSON="${ART_DIR}/webhook_paid.json"
WEBHOOK_PAID_BODY='{"provider_event_id":"evt_pr29_paid","order_no":"'"${ORDER_NO}"'","external_trade_no":"trade_pr29","amount_cents":199,"currency":"CNY","event_type":"payment_succeeded"}'
http_code="$(post_billing_webhook "${WEBHOOK_PAID_BODY}" "${WEBHOOK_PAID_JSON}")"
[[ "${http_code}" == "200" ]] || fail "webhook paid failed (http=${http_code})"

REPORT_AFTER_PAID_JSON="${ART_DIR}/report_after_paid.json"
http_code=$(curl -sS -L -o "${REPORT_AFTER_PAID_JSON}" -w "%{http_code}" \
  "${API}/api/v0.3/attempts/${ATTEMPT_ID}/report" \
  -H "Accept: application/json" \
  -H "Authorization: Bearer ${TOKEN}" \
  -H "X-Org-Id: ${ORG_ID}" || true)
[[ "${http_code}" == "200" ]] || fail "report after paid failed (http=${http_code})"
LOCKED_AFTER_PAID="$(php -r '$j=json_decode(file_get_contents($argv[1]), true); echo !empty($j["locked"]) ? "1" : "0";' "${REPORT_AFTER_PAID_JSON}")"
[[ "${LOCKED_AFTER_PAID}" == "0" ]] || fail "expected report unlocked after payment"
echo "${LOCKED_AFTER_PAID}" > "${ART_DIR}/locked_after_paid.txt"

WEBHOOK_REFUND_JSON="${ART_DIR}/webhook_refund.json"
WEBHOOK_REFUND_BODY='{"provider_event_id":"evt_pr29_refund","order_no":"'"${ORDER_NO}"'","event_type":"refund_succeeded","refund_amount_cents":199,"refund_reason":"requested_by_customer"}'
http_code="$(post_billing_webhook "${WEBHOOK_REFUND_BODY}" "${WEBHOOK_REFUND_JSON}")"
[[ "${http_code}" == "200" ]] || fail "webhook refund failed (http=${http_code})"

REPORT_AFTER_REFUND_JSON="${ART_DIR}/report_after_refund.json"
http_code=$(curl -sS -L -o "${REPORT_AFTER_REFUND_JSON}" -w "%{http_code}" \
  "${API}/api/v0.3/attempts/${ATTEMPT_ID}/report" \
  -H "Accept: application/json" \
  -H "Authorization: Bearer ${TOKEN}" \
  -H "X-Org-Id: ${ORG_ID}" || true)
[[ "${http_code}" == "200" ]] || fail "report after refund failed (http=${http_code})"
LOCKED_AFTER_REFUND="$(php -r '$j=json_decode(file_get_contents($argv[1]), true); echo !empty($j["locked"]) ? "1" : "0";' "${REPORT_AFTER_REFUND_JSON}")"
[[ "${LOCKED_AFTER_REFUND}" == "1" ]] || fail "expected report locked after refund"
echo "${LOCKED_AFTER_REFUND}" > "${ART_DIR}/locked_after_refund.txt"

bash -n "${BACKEND_DIR}/scripts/pr29_verify.sh"
