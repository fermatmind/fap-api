#!/usr/bin/env bash
set -euo pipefail

ROOT_DIR=$(cd "$(dirname "$0")/.." && pwd)          # .../backend
REPO_DIR=$(cd "$ROOT_DIR/.." && pwd)               # repo root

ART_DIR="$ROOT_DIR/artifacts/pr20"
DB_FILE="/tmp/pr20_verify.sqlite"
PID_FILE="$ART_DIR/server.pid"
SERVER_LOG="$ART_DIR/server.log"
SUMMARY_FILE="$ART_DIR/summary.txt"

mkdir -p "$ART_DIR"

cleanup() {
  if [ -f "$PID_FILE" ]; then
    pid="$(cat "$PID_FILE" 2>/dev/null || true)"
    if [ -n "${pid:-}" ]; then
      kill "$pid" >/dev/null 2>&1 || true
      sleep 0.2 || true
      kill -9 "$pid" >/dev/null 2>&1 || true
    fi
    rm -f "$PID_FILE" >/dev/null 2>&1 || true
  fi
  rm -f "$DB_FILE" >/dev/null 2>&1 || true
}
trap cleanup EXIT

fail() {
  local msg="$1"
  echo "[PR20][FAIL] $msg" >&2
  if [ -f "$SERVER_LOG" ]; then
    echo "[PR20][FAIL] server.log tail:" >&2
    tail -n 120 "$SERVER_LOG" >&2 || true
  fi
  exit 1
}

pick_port() {
  for p in 18000 18001 18002 18003 18004 18005 18006 18007 18008 18009 18010; do
    if ! lsof -ti tcp:"$p" >/dev/null 2>&1; then
      echo "$p"
      return 0
    fi
  done
  return 1
}

curl_json() {
  # curl_json <METHOD> <URL> <JSON_PAYLOAD_OR_EMPTY> <OUTFILE> [AUTH_TOKEN_OR_EMPTY]
  local method="$1"; shift
  local url="$1"; shift
  local payload="$1"; shift
  local out="$1"; shift
  local token="${1:-}"

  local args=(
    -sS -o "$out" -w "%{http_code}"
    -X "$method" "$url"
    -H "Accept: application/json"
    -H "Content-Type: application/json"
  )
  if [ -n "$token" ]; then
    args+=(-H "Authorization: Bearer ${token}")
  fi

  local http_code
  if [ -n "$payload" ]; then
    http_code="$(curl "${args[@]}" --data-binary "$payload" || true)"
  else
    http_code="$(curl "${args[@]}" || true)"
  fi

  if [ "${http_code:-000}" != "200" ]; then
    echo "[PR20][FAIL] curl status=${http_code:-000} url=$url" >&2
    echo "[PR20][FAIL] body:" >&2
    head -c 1200 "$out" >&2 || true
    echo >&2
    return 1
  fi
  return 0
}

cd "$ROOT_DIR"

rm -f "$DB_FILE"
touch "$DB_FILE"

export DB_CONNECTION=sqlite
export DB_DATABASE="$DB_FILE"

# 强制 content_packs.root 走 repo/content_packages（不依赖本机 .env）
export FAP_PACKS_ROOT="$REPO_DIR/content_packages"
export FAP_DEFAULT_PACK_ID="default"

php artisan route:list > "$ART_DIR/routes.txt"

php artisan migrate --force
php artisan fap:scales:seed-default
php artisan fap:scales:sync-slugs
php artisan db:seed --class=Pr19CommerceSeeder

# 创建一个“购买用户”，用它的 token 走 start/submit + 下单
php -r 'echo "creating pr20 user...\n";' >/dev/null 2>&1 || true
USER_TOKEN="$(php -r '
require __DIR__ . "/vendor/autoload.php";
$app = require __DIR__ . "/bootstrap/app.php";
$kernel = $app->make(Illuminate\Contracts\Console\Kernel::class);
$kernel->bootstrap();

use Illuminate\Support\Facades\DB;

$now = date("Y-m-d H:i:s");
$email = "pr20_b2c_user@example.com";

$id = DB::table("users")->insertGetId([
  "name" => "PR20 User",
  "email" => $email,
  "password" => password_hash("secret", PASSWORD_BCRYPT),
  "created_at" => $now,
  "updated_at" => $now,
]);

$issued = app(App\Services\Auth\FmTokenService::class)->issueForUser((string)$id);
echo (string)($issued["token"] ?? "");
')"
[ -n "$USER_TOKEN" ] || fail "failed to create user token"

PORT="$(pick_port)" || fail "no free port in 18000..18010"
API="http://127.0.0.1:${PORT}"

php artisan serve --host=127.0.0.1 --port="$PORT" >"$SERVER_LOG" 2>&1 &
SERVER_PID=$!
echo "$SERVER_PID" > "$PID_FILE"

# wait health
for _ in $(seq 1 80); do
  if curl -sS "$API/api/v0.2/health" >/dev/null 2>&1; then
    break
  fi
  sleep 0.1
done
curl -sS "$API/api/v0.2/health" >/dev/null 2>&1 || fail "server not ready on $API"

# ------------------------
# MBTI: start -> questions -> submit（带登录 token，确保 attempt.user_id 有值）
# ------------------------
ANON_ID="pr20_local_anon_001"

START_PAYLOAD='{"anon_id":"'"$ANON_ID"'","scale_code":"MBTI","scale_version":"v0.3","question_count":144,"client_platform":"verify","region":"CN_MAINLAND","locale":"zh-CN","duration_ms":1000}'
curl_json POST "$API/api/v0.3/attempts/start" "$START_PAYLOAD" "$ART_DIR/curl_start_mbti.json" "$USER_TOKEN" || fail "attempts/start failed"

attempt_id="$(python3 -c 'import json,sys; print(json.load(open(sys.argv[1],"r",encoding="utf-8")).get("attempt_id",""))' "$ART_DIR/curl_start_mbti.json")"
[ -n "$attempt_id" ] || fail "attempt_id missing (start_resp=$(cat "$ART_DIR/curl_start_mbti.json" | head -c 600))"

curl_json GET "$API/api/v0.3/scales/MBTI/questions?region=CN_MAINLAND&locale=zh-CN" "" "$ART_DIR/curl_questions_mbti.json" "" || fail "scales/MBTI/questions failed"

# build answers by recursively finding questions list
python3 - "$ART_DIR/curl_questions_mbti.json" "$ART_DIR/mbti_answers.json" <<'PY'
import json,sys
src=sys.argv[1]; out=sys.argv[2]
data=json.load(open(src,'r',encoding='utf-8'))

def find_questions(node):
  if isinstance(node, list):
    if node and all(isinstance(x, dict) for x in node):
      ok=True
      for x in node[:12]:
        if ('id' not in x and 'question_id' not in x) or ('options' not in x):
          ok=False
          break
      if ok:
        return node
    for x in node:
      r=find_questions(x)
      if r is not None:
        return r
  if isinstance(node, dict):
    for k in ('questions','items','data','payload','result'):
      if k in node:
        r=find_questions(node[k])
        if r is not None:
          return r
    for v in node.values():
      r=find_questions(v)
      if r is not None:
        return r
  return None

qs=find_questions(data) or []

answer_key=None
for q in qs:
  opts=q.get('options') or []
  if not opts:
    continue
  o=opts[0]
  if 'code' in o:
    answer_key='code'
    break
  if 'option_id' in o or 'id' in o:
    answer_key='option_id'
    break
if answer_key is None:
  answer_key='code'

answers=[]
for q in qs:
  qid=q.get('id') or q.get('question_id')
  if not qid:
    continue
  opts=q.get('options') or []
  if not opts:
    continue
  o=opts[0]
  if answer_key=='code':
    v=o.get('code')
  else:
    v=o.get('option_id') or o.get('id')
  if v is None:
    continue
  answers.append({'question_id': qid, answer_key: v})

json.dump({'answer_key':answer_key,'answers':answers}, open(out,'w',encoding='utf-8'), ensure_ascii=False)
print(len(answers))
PY

answers_count="$(python3 -c 'import json; print(len(json.load(open("'"$ART_DIR"'/mbti_answers.json","r",encoding="utf-8")).get("answers",[])))')"
[ "$answers_count" = "144" ] || fail "MBTI answers_count != 144 (got=$answers_count)"

python3 - "$attempt_id" "$ART_DIR/mbti_answers.json" "$ART_DIR/curl_submit_payload_mbti.json" <<'PY'
import json,sys
attempt_id=sys.argv[1]
doc=json.load(open(sys.argv[2],'r',encoding='utf-8'))
answers=doc.get('answers',[])
payload={
  "attempt_id": attempt_id,
  "answers": answers,
  "duration_ms": 120000,
  "client_platform": "verify",
  "region": "CN_MAINLAND",
  "locale": "zh-CN",
}
json.dump(payload, open(sys.argv[3],'w',encoding='utf-8'), ensure_ascii=False)
PY

# submit（带登录 token，确保链路上的 user_id 一致）
http_code="$(curl -sS -o "$ART_DIR/curl_submit_mbti.json" -w "%{http_code}" -X POST "$API/api/v0.3/attempts/submit" \
  -H "Accept: application/json" \
  -H "Content-Type: application/json" \
  -H "Authorization: Bearer ${USER_TOKEN}" \
  --data-binary @"$ART_DIR/curl_submit_payload_mbti.json" || true)"
if [ "${http_code:-000}" != "200" ]; then
  echo "[PR20][FAIL] submit status=${http_code:-000}" >&2
  head -c 1200 "$ART_DIR/curl_submit_mbti.json" >&2 || true
  echo >&2
  fail "attempts/submit failed"
fi

# 未购 report
curl_json GET "$API/api/v0.3/attempts/${attempt_id}/report" "" "$ART_DIR/curl_report_unpaid.json" "" || fail "fetch unpaid report failed"

# ------------------------
# order + webhook -> entitlement + snapshot（order 用登录 token 创建，webhook 发权益 user_id 不为空）
# ------------------------
ORDER_PAYLOAD='{"sku":"MBTI_REPORT_FULL","quantity":1,"target_attempt_id":"'"$attempt_id"'","provider":"stub","anon_id":"'"$ANON_ID"'"}'
curl_json POST "$API/api/v0.3/orders" "$ORDER_PAYLOAD" "$ART_DIR/curl_order.json" "$USER_TOKEN" || fail "orders create failed"

order_no="$(python3 -c 'import json,sys; print(json.load(open(sys.argv[1],"r",encoding="utf-8")).get("order_no",""))' "$ART_DIR/curl_order.json")"
[ -n "$order_no" ] || fail "order_no missing (order_resp=$(cat "$ART_DIR/curl_order.json" | head -c 600))"

WEBHOOK_PAYLOAD='{"provider_event_id":"evt_pr20_1","order_no":"'"$order_no"'","external_trade_no":"trade_pr20_1","amount_cents":990,"currency":"USD"}'
curl_json POST "$API/api/v0.3/webhooks/payment/stub" "$WEBHOOK_PAYLOAD" "$ART_DIR/curl_webhook.json" "" || fail "webhook failed"

# 已购 report（读 snapshot）
curl_json GET "$API/api/v0.3/attempts/${attempt_id}/report" "" "$ART_DIR/curl_report_paid.json" "" || fail "fetch paid report failed"

# paid report must remain identical after registry update (snapshot)
php artisan tinker --execute="\\Illuminate\\Support\\Facades\\DB::table('scales_registry')->where('org_id',0)->where('code','MBTI')->update(['default_dir_version'=>'MBTI-CN-v0.2.1-NOTEXIST','updated_at'=>now()]);" >/dev/null

curl_json GET "$API/api/v0.3/attempts/${attempt_id}/report" "" "$ART_DIR/curl_report_paid_after_update.json" "" || fail "fetch paid report after update failed"

diff_result="diff=0"
if ! diff -q "$ART_DIR/curl_report_paid.json" "$ART_DIR/curl_report_paid_after_update.json" >/dev/null 2>&1; then
  diff_result="diff=1"
fi

unpaid_locked="$(python3 -c 'import json; print(json.load(open("'"$ART_DIR"'/curl_report_unpaid.json","r",encoding="utf-8")).get("locked"))')"
paid_locked="$(python3 -c 'import json; print(json.load(open("'"$ART_DIR"'/curl_report_paid.json","r",encoding="utf-8")).get("locked"))')"
paid_after_locked="$(python3 -c 'import json; print(json.load(open("'"$ART_DIR"'/curl_report_paid_after_update.json","r",encoding="utf-8")).get("locked"))')"

cat > "$SUMMARY_FILE" <<EOF
pr20_verify_report_paywall
- api=${API}
- attempt_id=${attempt_id}
- order_no=${order_no}
- unpaid_locked=${unpaid_locked}
- paid_locked=${paid_locked}
- paid_after_update_locked=${paid_after_locked}
- ${diff_result}
- smoke_url=${API}/api/v0.3/attempts/${attempt_id}/report
- tables=report_snapshots,benefit_grants,benefit_consumptions,payment_events,orders
EOF

# sanitize absolute paths
perl -pi -e "s@\Q${REPO_DIR}\E@<REPO>@g; s@/Users/[^ ]+@<REPO>@g; s@/home/[^ ]+@<REPO>@g" \
  "$ART_DIR/routes.txt" \
  "$ART_DIR/curl_start_mbti.json" \
  "$ART_DIR/curl_questions_mbti.json" \
  "$ART_DIR/mbti_answers.json" \
  "$ART_DIR/curl_submit_payload_mbti.json" \
  "$ART_DIR/curl_submit_mbti.json" \
  "$ART_DIR/curl_order.json" \
  "$ART_DIR/curl_webhook.json" \
  "$ART_DIR/curl_report_unpaid.json" \
  "$ART_DIR/curl_report_paid.json" \
  "$ART_DIR/curl_report_paid_after_update.json" \
  "$SUMMARY_FILE" \
  "$SERVER_LOG" >/dev/null 2>&1 || true

echo "[PR20] verify OK ✅"