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
export LANG=en_US.UTF-8
export BILLING_WEBHOOK_SECRET="${BILLING_WEBHOOK_SECRET:-billing_secret}"

REPO_DIR="$(cd "$(dirname "${BASH_SOURCE[0]}")/../.." && pwd)"
BACKEND_DIR="${REPO_DIR}/backend"
ART_DIR="${BACKEND_DIR}/artifacts/pr33"
SERVE_PORT="${SERVE_PORT:-18033}"
HOST="127.0.0.1"
API_BASE="http://${HOST}:${SERVE_PORT}"
DB_PATH="${DB_DATABASE:-/tmp/pr33.sqlite}"

mkdir -p "${ART_DIR}"
exec > "${ART_DIR}/verify.log" 2>&1

fail() { echo "[PR33][FAIL] $*" >&2; exit 1; }

cleanup_port() {
  local port="$1"
  lsof -nP -iTCP:"${port}" -sTCP:LISTEN || true
  pid_list="$(lsof -ti tcp:"${port}" || true)"
  if [[ -n "${pid_list}" ]]; then
    echo "${pid_list}" | xargs kill -9 || true
  fi
  lsof -nP -iTCP:"${port}" -sTCP:LISTEN || true
}

wait_health() {
  local url="$1"
  local code=""
  for _ in $(seq 1 60); do
    code="$(curl -sS -o "${ART_DIR}/health_body.txt" -w "%{http_code}" "${url}" || true)"
    if [[ "${code}" == "200" ]]; then
      return 0
    fi
    sleep 0.25
  done
  echo "health_check_failed http=${code}" >&2
  cat "${ART_DIR}/health_body.txt" >&2 || true
  tail -n 200 "${ART_DIR}/server.log" >&2 || true
  return 1
}

cleanup() {
  if [[ -n "${SERVE_PID:-}" ]] && kill -0 "${SERVE_PID}" >/dev/null 2>&1; then
    kill "${SERVE_PID}" >/dev/null 2>&1 || true
  fi
  cleanup_port "${SERVE_PORT}"
}
trap cleanup EXIT

cleanup_port "${SERVE_PORT}"
cleanup_port 18000

export APP_ENV=testing
export DB_CONNECTION=sqlite
export DB_DATABASE="${DB_PATH}"

mkdir -p "${BACKEND_DIR}/storage/framework/cache" \
  "${BACKEND_DIR}/storage/framework/sessions" \
  "${BACKEND_DIR}/storage/framework/views" \
  "${BACKEND_DIR}/storage/framework/testing" \
  "${BACKEND_DIR}/bootstrap/cache"

cd "${BACKEND_DIR}"
php artisan serve --host="${HOST}" --port="${SERVE_PORT}" > "${ART_DIR}/server.log" 2>&1 &
SERVE_PID="$!"
echo "${SERVE_PID}" > "${ART_DIR}/server.pid"
cd "${REPO_DIR}"

wait_health "${API_BASE}/api/v0.2/healthz" || fail "healthz failed"

# 1) pack/seed/config consistency
DEFAULT_PACK_ID_FILE="${ART_DIR}/default_pack_id.txt"
DEFAULT_DIR_VERSION_FILE="${ART_DIR}/default_dir_version.txt"
SCALES_PACK_ID_FILE="${ART_DIR}/scales_registry_default_pack_id.txt"
SCALES_DIR_VERSION_FILE="${ART_DIR}/scales_registry_default_dir_version.txt"
PACK_MANIFEST_FILE="${ART_DIR}/pack_manifest_path.txt"
PACK_QUESTIONS_FILE="${ART_DIR}/pack_questions_path.txt"

cd "${BACKEND_DIR}"
php -r 'require "vendor/autoload.php"; $app=require "bootstrap/app.php"; $app->make(Illuminate\Contracts\Console\Kernel::class)->bootstrap(); echo (string) config("content_packs.default_pack_id", "");' > "${DEFAULT_PACK_ID_FILE}"
php -r 'require "vendor/autoload.php"; $app=require "bootstrap/app.php"; $app->make(Illuminate\Contracts\Console\Kernel::class)->bootstrap(); echo (string) config("content_packs.default_dir_version", "");' > "${DEFAULT_DIR_VERSION_FILE}"

php -r '
require "vendor/autoload.php";
$app=require "bootstrap/app.php";
$app->make(Illuminate\Contracts\Console\Kernel::class)->bootstrap();
if (!Illuminate\Support\Facades\Schema::hasTable("scales_registry")) {
  fwrite(STDERR, "missing scales_registry table\n"); exit(1);
}
$packId = (string) config("content_packs.default_pack_id", "");
$dirVersion = (string) config("content_packs.default_dir_version", "");
$index = app(App\Services\Content\ContentPacksIndex::class);
$found = $index->find($packId, $dirVersion);
if (!($found["ok"] ?? false)) { fwrite(STDERR, "pack_not_found\n"); exit(1); }
$scaleCode = (string) ($found["item"]["scale_code"] ?? "");
if ($scaleCode === "") { fwrite(STDERR, "missing scale_code\n"); exit(1); }
$row = Illuminate\Support\Facades\DB::table("scales_registry")->where("org_id", 0)->where("code", $scaleCode)->first();
if (!$row) { fwrite(STDERR, "missing scales_registry row\n"); exit(1); }
echo (string) ($row->default_pack_id ?? "");
' > "${SCALES_PACK_ID_FILE}"

php -r '
require "vendor/autoload.php";
$app=require "bootstrap/app.php";
$app->make(Illuminate\Contracts\Console\Kernel::class)->bootstrap();
$packId = (string) config("content_packs.default_pack_id", "");
$dirVersion = (string) config("content_packs.default_dir_version", "");
$index = app(App\Services\Content\ContentPacksIndex::class);
$found = $index->find($packId, $dirVersion);
if (!($found["ok"] ?? false)) { fwrite(STDERR, "pack_not_found\n"); exit(1); }
$scaleCode = (string) ($found["item"]["scale_code"] ?? "");
if ($scaleCode === "") { fwrite(STDERR, "missing scale_code\n"); exit(1); }
$row = Illuminate\Support\Facades\DB::table("scales_registry")->where("org_id", 0)->where("code", $scaleCode)->first();
if (!$row) { fwrite(STDERR, "missing scales_registry row\n"); exit(1); }
echo (string) ($row->default_dir_version ?? "");
' > "${SCALES_DIR_VERSION_FILE}"

php -r '
require "vendor/autoload.php";
$app=require "bootstrap/app.php";
$app->make(Illuminate\Contracts\Console\Kernel::class)->bootstrap();
$packId = (string) config("content_packs.default_pack_id", "");
$dirVersion = (string) config("content_packs.default_dir_version", "");
$index = app(App\Services\Content\ContentPacksIndex::class);
$found = $index->find($packId, $dirVersion);
if (!($found["ok"] ?? false)) { fwrite(STDERR, "pack_not_found\n"); exit(1); }
$manifest = (string) ($found["item"]["manifest_path"] ?? "");
$questions = (string) ($found["item"]["questions_path"] ?? "");
if ($manifest === "" || !is_file($manifest)) { fwrite(STDERR, "manifest_missing\n"); exit(1); }
if ($questions === "" || !is_file($questions)) { fwrite(STDERR, "questions_missing\n"); exit(1); }
$manifestRaw = file_get_contents($manifest);
$manifestJson = json_decode($manifestRaw, true);
if (!is_array($manifestJson)) { fwrite(STDERR, "manifest_invalid\n"); exit(1); }
$questionsRaw = file_get_contents($questions);
$questionsJson = json_decode($questionsRaw, true);
if (!is_array($questionsJson)) { fwrite(STDERR, "questions_invalid\n"); exit(1); }
file_put_contents("'"${PACK_MANIFEST_FILE}"'", $manifest);
file_put_contents("'"${PACK_QUESTIONS_FILE}"'", $questions);
' > "${ART_DIR}/pack_parse.txt"
cd "${REPO_DIR}"

if [[ "$(cat "${DEFAULT_PACK_ID_FILE}")" != "$(cat "${SCALES_PACK_ID_FILE}")" ]]; then
  fail "default_pack_id_mismatch"
fi
if [[ "$(cat "${DEFAULT_DIR_VERSION_FILE}")" != "$(cat "${SCALES_DIR_VERSION_FILE}")" ]]; then
  fail "default_dir_version_mismatch"
fi

echo "pack_consistency_ok" > "${ART_DIR}/pack_consistency.txt"

fetch_json() {
  local method="$1"
  local uri="$2"
  local out="$3"
  local body="${4:-}"
  if [[ "${method}" == "GET" ]]; then
    curl -sS -o "${out}" -w "%{http_code}" -H "Accept: application/json" -H "X-Org-Id: 0" "${API_BASE}${uri}" || true
  else
    curl -sS -o "${out}" -w "%{http_code}" -X "${method}" \
      -H "Content-Type: application/json" -H "Accept: application/json" -H "X-Org-Id: 0" \
      -d "${body}" "${API_BASE}${uri}" || true
  fi
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
  curl -sS -o "${out}" -w "%{http_code}" -X POST \
    -H "Content-Type: application/json" -H "Accept: application/json" -H "X-Org-Id: 0" \
    -H "X-Billing-Timestamp: ${ts}" \
    -H "X-Billing-Signature: ${sig}" \
    --data-binary "${body}" \
    "${API_BASE}/api/v0.3/webhooks/payment/billing" || true
}

# 2) Create attempt -> order -> webhook
QUESTIONS_JSON="${ART_DIR}/questions.json"
http_code="$(fetch_json GET "/api/v0.3/scales/MBTI/questions" "${QUESTIONS_JSON}")"
if [[ "${http_code}" != "200" ]]; then
  echo "questions_failed http=${http_code}" >&2
  cat "${QUESTIONS_JSON}" >&2 || true
  exit 1
fi

ANSWERS_JSON="${ART_DIR}/answers.json"
php -r '
$raw = file_get_contents("php://stdin");
$data = json_decode($raw, true);
if (!is_array($data)) { fwrite(STDERR, "questions json not array\n"); exit(1); }
$q = $data["questions"]["items"] ?? $data["questions"] ?? $data["data"] ?? $data;
if (!is_array($q)) { fwrite(STDERR, "questions payload invalid\n"); exit(1); }
$answers = [];
foreach ($q as $item) {
  if (!is_array($item)) { continue; }
  $qid = $item["question_id"] ?? ($item["id"] ?? null);
  if (!$qid) { continue; }
  $opts = $item["options"] ?? [];
  $code = "A";
  if (is_array($opts) && count($opts) > 0) {
    $code = (string) ($opts[0]["code"] ?? "A");
  }
  $answers[] = ["question_id" => $qid, "code" => $code];
}
if (count($answers) === 0) { fwrite(STDERR, "answers empty\n"); exit(1); }
echo json_encode($answers, JSON_UNESCAPED_UNICODE);
' < "${QUESTIONS_JSON}" > "${ANSWERS_JSON}"

ATTEMPT_START_JSON="${ART_DIR}/attempt_start.json"
http_code="$(fetch_json POST "/api/v0.3/attempts/start" "${ATTEMPT_START_JSON}" '{"scale_code":"MBTI"}')"
if [[ "${http_code}" != "200" ]]; then
  echo "attempt_start_failed http=${http_code}" >&2
  cat "${ATTEMPT_START_JSON}" >&2 || true
  exit 1
fi

ATTEMPT_ID="$(php -r '$j=json_decode(file_get_contents($argv[1]), true); echo $j["attempt_id"] ?? "";' "${ATTEMPT_START_JSON}")"
if [[ -z "${ATTEMPT_ID}" ]]; then
  fail "missing_attempt_id"
fi

echo "${ATTEMPT_ID}" > "${ART_DIR}/attempt_id.txt"

SUBMIT_PAYLOAD="${ART_DIR}/submit_payload.json"
ATTEMPT_ID="${ATTEMPT_ID}" ANSWERS_PATH="${ANSWERS_JSON}" php -r '
$answers = json_decode(file_get_contents(getenv("ANSWERS_PATH")), true);
if (!is_array($answers)) { fwrite(STDERR, "answers invalid\n"); exit(1); }
$payload = [
  "attempt_id" => getenv("ATTEMPT_ID"),
  "answers" => $answers,
  "duration_ms" => 120000,
];
file_put_contents("php://stdout", json_encode($payload, JSON_UNESCAPED_UNICODE));
' > "${SUBMIT_PAYLOAD}"

SUBMIT_JSON="${ART_DIR}/submit.json"
SUBMIT_BODY="$(cat "${SUBMIT_PAYLOAD}")"
http_code="$(fetch_json POST "/api/v0.3/attempts/submit" "${SUBMIT_JSON}" "${SUBMIT_BODY}")"
if [[ "${http_code}" != "200" ]]; then
  echo "submit_failed http=${http_code}" >&2
  cat "${SUBMIT_JSON}" >&2 || true
  exit 1
fi

OK_FLAG="$(php -r '$j=json_decode(file_get_contents($argv[1]), true); echo (($j["ok"] ?? false) ? "ok" : "");' "${SUBMIT_JSON}")"
if [[ "${OK_FLAG}" != "ok" ]]; then
  fail "submit_not_ok"
fi

ORDER_PAYLOAD="${ART_DIR}/order_payload.json"
ATTEMPT_ID="${ATTEMPT_ID}" php -r '
$payload = [
  "sku" => "MBTI_REPORT_FULL",
  "quantity" => 1,
  "target_attempt_id" => getenv("ATTEMPT_ID"),
  "provider" => "billing",
];
file_put_contents("php://stdout", json_encode($payload, JSON_UNESCAPED_UNICODE));
' > "${ORDER_PAYLOAD}"

ORDER_JSON="${ART_DIR}/order.json"
ORDER_BODY="$(cat "${ORDER_PAYLOAD}")"
http_code="$(fetch_json POST "/api/v0.3/orders" "${ORDER_JSON}" "${ORDER_BODY}")"
if [[ "${http_code}" != "200" ]]; then
  echo "order_create_failed http=${http_code}" >&2
  cat "${ORDER_JSON}" >&2 || true
  exit 1
fi

ORDER_NO="$(php -r '$j=json_decode(file_get_contents($argv[1]), true); echo $j["order_no"] ?? "";' "${ORDER_JSON}")"
if [[ -z "${ORDER_NO}" ]]; then
  fail "missing_order_no"
fi

echo "${ORDER_NO}" > "${ART_DIR}/order_no.txt"

WEBHOOK_PAYLOAD="${ART_DIR}/webhook_payload.json"
ORDER_NO="${ORDER_NO}" php -r '
$payload = [
  "provider_event_id" => "evt_pr33_1",
  "order_no" => getenv("ORDER_NO"),
  "external_trade_no" => "trade_pr33_1",
  "amount_cents" => 199,
  "currency" => "CNY",
];
file_put_contents("php://stdout", json_encode($payload, JSON_UNESCAPED_UNICODE));
' > "${WEBHOOK_PAYLOAD}"

WEBHOOK_JSON="${ART_DIR}/webhook.json"
WEBHOOK_BODY="$(cat "${WEBHOOK_PAYLOAD}")"
http_code="$(post_billing_webhook "${WEBHOOK_BODY}" "${WEBHOOK_JSON}")"
if [[ "${http_code}" != "200" ]]; then
  echo "webhook_failed http=${http_code}" >&2
  cat "${WEBHOOK_JSON}" >&2 || true
  exit 1
fi

ORDER_CHECK_JSON="${ART_DIR}/order_check.json"
http_code="$(fetch_json GET "/api/v0.3/orders/${ORDER_NO}" "${ORDER_CHECK_JSON}")"
if [[ "${http_code}" != "200" ]]; then
  echo "order_check_failed http=${http_code}" >&2
  cat "${ORDER_CHECK_JSON}" >&2 || true
  exit 1
fi

ORDER_STATUS="$(php -r '$j=json_decode(file_get_contents($argv[1]), true); echo $j["order"]["status"] ?? "";' "${ORDER_CHECK_JSON}")"
if [[ "${ORDER_STATUS}" != "paid" && "${ORDER_STATUS}" != "fulfilled" ]]; then
  fail "order_status_not_paid"
fi

REPORT_JSON="${ART_DIR}/report.json"
http_code="$(fetch_json GET "/api/v0.3/attempts/${ATTEMPT_ID}/report" "${REPORT_JSON}")"
if [[ "${http_code}" != "200" ]]; then
  echo "report_failed http=${http_code}" >&2
  cat "${REPORT_JSON}" >&2 || true
  exit 1
fi

LOCKED_FLAG="$(php -r '$j=json_decode(file_get_contents($argv[1]), true); echo (($j["locked"] ?? null) ? "true" : "false");' "${REPORT_JSON}")"
if [[ "${LOCKED_FLAG}" == "true" ]]; then
  fail "report_should_be_unlocked"
fi

# 3) Orphan regression
ORPHAN_ORDER_NO="ord_orphan_pr33"
ORPHAN_PAYLOAD="${ART_DIR}/webhook_orphan_payload.json"
ORDER_NO="${ORPHAN_ORDER_NO}" php -r '
$payload = [
  "provider_event_id" => "evt_orphan_pr33",
  "order_no" => getenv("ORDER_NO"),
  "external_trade_no" => "trade_orphan_pr33",
  "amount_cents" => 199,
  "currency" => "CNY",
];
file_put_contents("php://stdout", json_encode($payload, JSON_UNESCAPED_UNICODE));
' > "${ORPHAN_PAYLOAD}"

ORPHAN_JSON="${ART_DIR}/webhook_orphan.json"
ORPHAN_BODY="$(cat "${ORPHAN_PAYLOAD}")"
http_code="$(post_billing_webhook "${ORPHAN_BODY}" "${ORPHAN_JSON}")"
if [[ "${http_code}" == "200" ]]; then
  echo "orphan_webhook_unexpected_200" >&2
  cat "${ORPHAN_JSON}" >&2 || true
  exit 1
fi

ORPHAN_ORDER_NO="${ORPHAN_ORDER_NO}" ATTEMPT_ID="${ATTEMPT_ID}" php -r '
require "vendor/autoload.php";
$app=require "bootstrap/app.php";
$app->make(Illuminate\Contracts\Console\Kernel::class)->bootstrap();
$orderNo = getenv("ORPHAN_ORDER_NO");
$attemptId = getenv("ATTEMPT_ID");
$now = now();
Illuminate\Support\Facades\DB::table("orders")->insert([
  "id" => (string) Illuminate\Support\Str::uuid(),
  "order_no" => $orderNo,
  "org_id" => 0,
  "user_id" => null,
  "anon_id" => "anon_test",
  "sku" => "MBTI_REPORT_FULL",
  "quantity" => 1,
  "target_attempt_id" => $attemptId,
  "amount_cents" => 199,
  "currency" => "CNY",
  "status" => "created",
  "provider" => "billing",
  "external_trade_no" => null,
  "paid_at" => null,
  "created_at" => $now,
  "updated_at" => $now,
  "amount_total" => 199,
  "amount_refunded" => 0,
  "item_sku" => "MBTI_REPORT_FULL",
  "provider_order_id" => null,
  "device_id" => null,
  "request_id" => null,
  "created_ip" => null,
  "fulfilled_at" => null,
  "refunded_at" => null,
]);
'

http_code="$(post_billing_webhook "${ORPHAN_BODY}" "${ORPHAN_JSON}")"
if [[ "${http_code}" != "200" ]]; then
  echo "orphan_retry_failed http=${http_code}" >&2
  cat "${ORPHAN_JSON}" >&2 || true
  exit 1
fi

echo "${ORPHAN_ORDER_NO}" > "${ART_DIR}/orphan_order_no.txt"

echo "verify_ok" > "${ART_DIR}/verify_ok.txt"
