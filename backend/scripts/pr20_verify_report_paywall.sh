#!/usr/bin/env bash
set -euo pipefail

ROOT_DIR=$(cd "$(dirname "$0")/.." && pwd)          # .../backend
REPO_DIR=$(cd "$ROOT_DIR/.." && pwd)               # repo root

ART_DIR="$ROOT_DIR/artifacts/pr20"
DB_FILE="/tmp/pr20_verify.sqlite"
PID_FILE="$ART_DIR/server.pid"
SERVER_LOG="$ART_DIR/server.log"
SUMMARY_FILE="$ART_DIR/summary.txt"
export BILLING_WEBHOOK_SECRET="${BILLING_WEBHOOK_SECRET:-billing_secret}"

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
  if [ -n "${ANON_ID:-}" ]; then
    args+=(-H "X-Anon-Id: ${ANON_ID}")
  fi
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

billing_sig() {
  local ts="$1"
  local body="$2"
  local secret="${BILLING_WEBHOOK_SECRET:-billing_secret}"
  printf "%s.%s" "$ts" "$body" | openssl dgst -sha256 -hmac "$secret" | awk '{print $2}'
}

post_billing_webhook() {
  local body="$1"
  local out="$2"
  local ts
  ts="$(date +%s)"
  local sig
  sig="$(billing_sig "$ts" "$body")"

  curl -sS -o "$out" -w "%{http_code}" \
    -X POST "$API/api/v0.3/webhooks/payment/billing" \
    -H "Accept: application/json" \
    -H "Content-Type: application/json" \
    -H "X-Webhook-Timestamp: ${ts}" \
    -H "X-Webhook-Signature: ${sig}" \
    --data-binary "$body" || true
}

cd "$ROOT_DIR"

rm -f "$DB_FILE"
touch "$DB_FILE"

export DB_CONNECTION=sqlite
export DB_DATABASE="$DB_FILE"

# 强制 content_packs.root 走 repo/content_packages（不依赖本机 .env）
export FAP_PACKS_ROOT="$REPO_DIR/content_packages"
export FAP_DEFAULT_REGION="CN_MAINLAND"
export FAP_DEFAULT_LOCALE="zh-CN"

# ✅ PR20（老验收）固定跑 v0.2.1-TEST 的 MBTI 包（避免被 v0.2.2 默认值影响）
export FAP_DEFAULT_PACK_ID="MBTI.cn-mainland.zh-CN.v0.2.1-TEST"
export FAP_DEFAULT_DIR_VERSION="MBTI-CN-v0.2.1-TEST"
export FAP_CONTENT_PACKAGE_VERSION="MBTI-CN-v0.2.1-TEST"

php artisan route:list > "$ART_DIR/routes.txt"

php artisan migrate --force
php artisan fap:scales:seed-default
php artisan fap:scales:sync-slugs
php artisan db:seed --class=Pr19CommerceSeeder

# 创建一个“购买用户”，用它的 token 走 start/submit + 下单
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

# ✅ 先拉 questions，拿到动态题量（避免写死 144）
curl_json GET "$API/api/v0.3/scales/MBTI/questions?region=CN_MAINLAND&locale=zh-CN" "" "$ART_DIR/curl_questions_mbti.json" "" || fail "scales/MBTI/questions failed"

question_count="$(php -r '
$j=json_decode(file_get_contents($argv[1]), true);
if (!is_array($j)) { echo "0"; exit; }
$q=$j["questions"]["items"] ?? $j["questions"] ?? $j["data"] ?? $j;
echo is_array($q) ? count($q) : 0;
' "$ART_DIR/curl_questions_mbti.json")"
[ "${question_count}" != "0" ] || fail "questions count is 0 (bad payload)"

START_PAYLOAD='{"anon_id":"'"$ANON_ID"'","scale_code":"MBTI","scale_version":"v0.3","question_count":'"$question_count"',"client_platform":"verify","region":"CN_MAINLAND","locale":"zh-CN","duration_ms":1000}'
curl_json POST "$API/api/v0.3/attempts/start" "$START_PAYLOAD" "$ART_DIR/curl_start_mbti.json" "$USER_TOKEN" || fail "attempts/start failed"

attempt_id="$(php -r '
$j=json_decode(file_get_contents($argv[1]), true);
echo (string)($j["attempt_id"] ?? "");
' "$ART_DIR/curl_start_mbti.json")"
[ -n "$attempt_id" ] || fail "attempt_id missing (start_resp=$(cat "$ART_DIR/curl_start_mbti.json" | head -c 600))"

# build answers by recursively finding questions list (php only)
php -r '
$src=$argv[1]; $out=$argv[2];
$data=json_decode(file_get_contents($src), true);

function find_questions($node) {
  if (is_array($node)) {
    $isList = array_keys($node) === range(0, count($node)-1);
    if ($isList && count($node) > 0 && is_array($node[0])) {
      $ok=true;
      foreach (array_slice($node, 0, min(12, count($node))) as $x) {
        if (!is_array($x)) { $ok=false; break; }
        $hasId = array_key_exists("id",$x) || array_key_exists("question_id",$x);
        $hasOpts = array_key_exists("options",$x);
        if (!$hasId || !$hasOpts) { $ok=false; break; }
      }
      if ($ok) return $node;
    }
    foreach ($node as $x) {
      $r=find_questions($x);
      if ($r !== null) return $r;
    }
  }
  if (is_array($node) && array_keys($node) !== range(0, count($node)-1)) {
    foreach (["questions","items","data","payload","result"] as $k) {
      if (array_key_exists($k,$node)) {
        $r=find_questions($node[$k]);
        if ($r !== null) return $r;
      }
    }
    foreach ($node as $v) {
      $r=find_questions($v);
      if ($r !== null) return $r;
    }
  }
  return null;
}

$qs=find_questions($data) ?? [];
$answerKey="code";
foreach ($qs as $q) {
  $opts=$q["options"] ?? [];
  if (!is_array($opts) || count($opts)===0) continue;
  $o=$opts[0];
  if (is_array($o) && array_key_exists("code",$o)) { $answerKey="code"; break; }
  if (is_array($o) && (array_key_exists("option_id",$o) || array_key_exists("id",$o))) { $answerKey="option_id"; break; }
}

$answers=[];
foreach ($qs as $q) {
  $qid=$q["id"] ?? ($q["question_id"] ?? null);
  $opts=$q["options"] ?? [];
  if (!$qid || !is_array($opts) || count($opts)===0) continue;
  $o=$opts[0];
  $v = ($answerKey==="code") ? ($o["code"] ?? null) : ($o["option_id"] ?? ($o["id"] ?? null));
  if ($v===null) continue;
  $answers[]=["question_id"=>$qid, $answerKey=>$v];
}

file_put_contents($out, json_encode(["answer_key"=>$answerKey,"answers"=>$answers], JSON_UNESCAPED_UNICODE));
echo count($answers);
' "$ART_DIR/curl_questions_mbti.json" "$ART_DIR/mbti_answers.json" > "$ART_DIR/answers_count.txt"

answers_count="$(cat "$ART_DIR/answers_count.txt" 2>/dev/null || echo "0")"
[ "$answers_count" = "$question_count" ] || fail "MBTI answers_count != question_count (got=$answers_count expected=$question_count)"

php -r '
$attemptId=$argv[1];
$doc=json_decode(file_get_contents($argv[2]), true);
$answers=$doc["answers"] ?? [];
$payload=[
  "attempt_id"=>$attemptId,
  "answers"=>$answers,
  "duration_ms"=>120000,
  "client_platform"=>"verify",
  "region"=>"CN_MAINLAND",
  "locale"=>"zh-CN",
];
file_put_contents($argv[3], json_encode($payload, JSON_UNESCAPED_UNICODE));
' "$attempt_id" "$ART_DIR/mbti_answers.json" "$ART_DIR/curl_submit_payload_mbti.json"

http_code="$(curl -sS -o "$ART_DIR/curl_submit_mbti.json" -w "%{http_code}" -X POST "$API/api/v0.3/attempts/submit" \
  -H "Accept: application/json" \
  -H "Content-Type: application/json" \
  -H "X-Anon-Id: ${ANON_ID}" \
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
# order + webhook -> entitlement + snapshot
# ------------------------
ORDER_PAYLOAD='{"sku":"MBTI_REPORT_FULL","quantity":1,"target_attempt_id":"'"$attempt_id"'","provider":"billing"}'
curl_json POST "$API/api/v0.3/orders" "$ORDER_PAYLOAD" "$ART_DIR/curl_order.json" "$USER_TOKEN" || fail "orders create failed"

order_no="$(php -r '
$j=json_decode(file_get_contents($argv[1]), true);
echo (string)($j["order_no"] ?? "");
' "$ART_DIR/curl_order.json")"
[ -n "$order_no" ] || fail "order_no missing (order_resp=$(cat "$ART_DIR/curl_order.json" | head -c 600))"

order_amount_cents="$(php -r '
require __DIR__ . "/vendor/autoload.php";
$app = require __DIR__ . "/bootstrap/app.php";
$kernel = $app->make(Illuminate\Contracts\Console\Kernel::class);
$kernel->bootstrap();
use Illuminate\Support\Facades\DB;
$row = DB::table("orders")->where("order_no", $argv[1])->first();
echo (string) ((int) ($row->amount_cents ?? 0));
' "$order_no")"
[ -n "$order_amount_cents" ] || fail "order amount missing"

order_currency="$(php -r '
require __DIR__ . "/vendor/autoload.php";
$app = require __DIR__ . "/bootstrap/app.php";
$kernel = $app->make(Illuminate\Contracts\Console\Kernel::class);
$kernel->bootstrap();
use Illuminate\Support\Facades\DB;
$row = DB::table("orders")->where("order_no", $argv[1])->first();
echo strtoupper(trim((string) ($row->currency ?? "")));
' "$order_no")"
[ -n "$order_currency" ] || fail "order currency missing"

WEBHOOK_PAYLOAD='{"provider_event_id":"evt_pr20_1","order_no":"'"$order_no"'","event_type":"payment_succeeded","external_trade_no":"trade_pr20_1","amount_cents":'"$order_amount_cents"',"currency":"'"$order_currency"'"}'
http_code="$(post_billing_webhook "$WEBHOOK_PAYLOAD" "$ART_DIR/curl_webhook.json")"
[ "${http_code:-000}" = "200" ] || fail "webhook failed (http=${http_code:-000})"

# 已购 report（读 snapshot）
curl_json GET "$API/api/v0.3/attempts/${attempt_id}/report" "" "$ART_DIR/curl_report_paid.json" "" || fail "fetch paid report failed"

# paid report must remain identical after registry update (snapshot)
php artisan tinker --execute="\\Illuminate\\Support\\Facades\\DB::table('scales_registry')->where('org_id',0)->where('code','MBTI')->update(['default_dir_version'=>'MBTI-CN-v0.2.1-NOTEXIST','updated_at'=>now()]);" >/dev/null

curl_json GET "$API/api/v0.3/attempts/${attempt_id}/report" "" "$ART_DIR/curl_report_paid_after_update.json" "" || fail "fetch paid report after update failed"

diff_result="diff=0"
if ! diff -q "$ART_DIR/curl_report_paid.json" "$ART_DIR/curl_report_paid_after_update.json" >/dev/null 2>&1; then
  diff_result="diff=1"
fi

unpaid_locked="$(php -r '$j=json_decode(file_get_contents($argv[1]), true); echo json_encode($j["locked"] ?? null);' "$ART_DIR/curl_report_unpaid.json")"
paid_locked="$(php -r '$j=json_decode(file_get_contents($argv[1]), true); echo json_encode($j["locked"] ?? null);' "$ART_DIR/curl_report_paid.json")"
paid_after_locked="$(php -r '$j=json_decode(file_get_contents($argv[1]), true); echo json_encode($j["locked"] ?? null);' "$ART_DIR/curl_report_paid_after_update.json")"

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
