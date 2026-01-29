#!/usr/bin/env bash
set -euo pipefail

ROOT_DIR=$(cd "$(dirname "$0")/.." && pwd)
ART_DIR="$ROOT_DIR/artifacts/pr20"
DB_FILE="/tmp/pr20_verify.sqlite"
PID_FILE="$ART_DIR/server.pid"
SERVER_LOG="$ART_DIR/server.log"
SUMMARY_FILE="$ART_DIR/summary.txt"

mkdir -p "$ART_DIR"

cd "$ROOT_DIR"

cleanup() {
  if [ -f "$PID_FILE" ]; then
    kill "$(cat "$PID_FILE")" >/dev/null 2>&1 || true
    rm -f "$PID_FILE"
  fi
  rm -f "$DB_FILE" >/dev/null 2>&1 || true
}
trap cleanup EXIT

rm -f "$DB_FILE"
touch "$DB_FILE"

export DB_CONNECTION=sqlite
export DB_DATABASE="$DB_FILE"

php artisan route:list > "$ART_DIR/routes.txt"

php artisan migrate --force
php artisan fap:scales:seed-default
php artisan fap:scales:sync-slugs
php artisan db:seed --class=Pr17SimpleScoreDemoSeeder
php artisan db:seed --class=Pr19CommerceSeeder

php artisan serve --host=127.0.0.1 --port=18000 > "$SERVER_LOG" 2>&1 &
SERVER_PID=$!
echo "$SERVER_PID" > "$PID_FILE"

sleep 2

if grep -q "Failed to listen" "$SERVER_LOG"; then
  cat <<EOF_SUM > "$SUMMARY_FILE"
pr20_verify_report_paywall
- error=server_bind_failed
- hint=check port 18000 availability/permissions
EOF_SUM
  exit 1
fi

start_resp=$(curl -sS -X POST "http://127.0.0.1:18000/api/v0.3/attempts/start" \
  -H "Content-Type: application/json" \
  -d '{"scale_code":"SIMPLE_SCORE_DEMO"}')

attempt_id=$(python3 - <<'PY'
import json,sys
resp=json.loads(sys.stdin.read())
print(resp.get('attempt_id',''))
PY
<<<"$start_resp")

if [ -z "$attempt_id" ]; then
  echo "attempt_id missing" >&2
  exit 1
fi

submit_payload=$(printf '{\"attempt_id\":\"%s\",\"answers\":[{\"question_id\":\"SS-001\",\"code\":\"5\"},{\"question_id\":\"SS-002\",\"code\":\"4\"},{\"question_id\":\"SS-003\",\"code\":\"3\"},{\"question_id\":\"SS-004\",\"code\":\"2\"},{\"question_id\":\"SS-005\",\"code\":\"1\"}],\"duration_ms\":120000}' \"$attempt_id\")

curl -sS -X POST "http://127.0.0.1:18000/api/v0.3/attempts/submit" \
  -H "Content-Type: application/json" \
  -d "$submit_payload" > /tmp/pr20_submit.json

curl -sS "http://127.0.0.1:18000/api/v0.3/attempts/${attempt_id}/report" > "$ART_DIR/curl_report_unpaid.json"

order_resp=$(curl -sS -X POST "http://127.0.0.1:18000/api/v0.3/orders" \
  -H "Content-Type: application/json" \
  -d "{\"sku\":\"MBTI_REPORT_FULL\",\"quantity\":1,\"target_attempt_id\":\"${attempt_id}\",\"provider\":\"stub\"}")

order_no=$(python3 - <<'PY'
import json,sys
resp=json.loads(sys.stdin.read())
print(resp.get('order_no',''))
PY
<<<"$order_resp")

if [ -z "$order_no" ]; then
  echo "order_no missing" >&2
  exit 1
fi

webhook_payload=$(cat <<JSON
{"provider_event_id":"evt_pr20_1","order_no":"${order_no}","external_trade_no":"trade_pr20_1","amount_cents":990,"currency":"USD"}
JSON
)

curl -sS -X POST "http://127.0.0.1:18000/api/v0.3/webhooks/payment/stub" \
  -H "Content-Type: application/json" \
  -d "$webhook_payload" > /tmp/pr20_webhook.json

curl -sS "http://127.0.0.1:18000/api/v0.3/attempts/${attempt_id}/report" > "$ART_DIR/curl_report_paid.json"

php artisan tinker --execute="\Illuminate\\Support\\Facades\\DB::table('scales_registry')->where('org_id',0)->where('code','SIMPLE_SCORE_DEMO')->update(['default_dir_version'=>'SIMPLE-SCORE-CN-v0.3.1-TEST','updated_at'=>now()]);"

curl -sS "http://127.0.0.1:18000/api/v0.3/attempts/${attempt_id}/report" > "$ART_DIR/curl_report_paid_after_update.json"

diff_result="diff=0"
if ! diff -q "$ART_DIR/curl_report_paid.json" "$ART_DIR/curl_report_paid_after_update.json" >/dev/null 2>&1; then
  diff_result="diff=1"
fi

if [ -f "$PID_FILE" ]; then
  kill "$(cat "$PID_FILE")" || true
fi

cat <<EOF_SUM > "$SUMMARY_FILE"
pr20_verify_report_paywall
- attempt_id=${attempt_id}
- order_no=${order_no}
- unpaid_locked=$(python3 - <<'PY'
import json,sys
resp=json.load(open('$ART_DIR/curl_report_unpaid.json'))
print(resp.get('locked'))
PY
)
- paid_locked=$(python3 - <<'PY'
import json,sys
resp=json.load(open('$ART_DIR/curl_report_paid.json'))
print(resp.get('locked'))
PY
)
- paid_after_update_locked=$(python3 - <<'PY'
import json,sys
resp=json.load(open('$ART_DIR/curl_report_paid_after_update.json'))
print(resp.get('locked'))
PY
)
- ${diff_result}
- smoke_url=http://127.0.0.1:18000/api/v0.3/attempts/${attempt_id}/report
- tables=report_snapshots,benefit_grants,benefit_consumptions,payment_events,orders
EOF_SUM

# sanitize absolute paths
perl -pi -e "s@${ROOT_DIR}@<REPO>@g; s@/Users/[^ ]+@<REPO>@g; s@/home/[^ ]+@<REPO>@g" \
  "$ART_DIR/routes.txt" \
  "$ART_DIR/curl_report_unpaid.json" \
  "$ART_DIR/curl_report_paid.json" \
  "$ART_DIR/curl_report_paid_after_update.json" \
  "$SUMMARY_FILE" \
  "$SERVER_LOG" || true
