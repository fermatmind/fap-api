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

REPO_DIR="$(cd "$(dirname "${BASH_SOURCE[0]}")/../.." && pwd)"
BACKEND_DIR="${REPO_DIR}/backend"
ART_DIR="${ART_DIR:-${BACKEND_DIR}/artifacts/pr60}"
SERVE_PORT="${SERVE_PORT:-1860}"
HOST="127.0.0.1"
API_BASE="http://${HOST}:${SERVE_PORT}"
SCALE_CODE="${SCALE_CODE:-MBTI}"
ANON_A="${ANON_A:-pr60-anon-a}"
ANON_B="${ANON_B:-pr60-anon-b}"

mkdir -p "${ART_DIR}"
exec > "${ART_DIR}/verify.log" 2>&1

fail() {
  echo "[PR60][VERIFY][FAIL] $*" >&2
  exit 1
}

cleanup_port() {
  local port="$1"
  lsof -nP -iTCP:"${port}" -sTCP:LISTEN || true
  local pid_list
  pid_list="$(lsof -ti tcp:"${port}" || true)"
  if [[ -n "${pid_list}" ]]; then
    echo "${pid_list}" | xargs kill -9 || true
  fi
  lsof -nP -iTCP:"${port}" -sTCP:LISTEN || true
}

wait_health() {
  local url="$1"
  local body_file="${ART_DIR}/healthz.body"
  local http_code=""

  for _ in $(seq 1 80); do
    http_code="$(curl -sS -o "${body_file}" -w "%{http_code}" "${url}" || true)"
    if [[ "${http_code}" == "200" ]]; then
      return 0
    fi
    sleep 0.25
  done

  echo "health_check_failed http=${http_code}" >&2
  cat "${body_file}" >&2 || true
  tail -n 120 "${ART_DIR}/server.log" >&2 || true
  return 1
}

expect_404() {
  local url="$1"
  local out="$2"
  local header_name="${3:-}"
  local header_val="${4:-}"
  local http_code

  if [[ -n "${header_name}" ]]; then
    http_code="$(curl -sS -o "${out}" -w "%{http_code}" -H "Accept: application/json" -H "${header_name}: ${header_val}" "${url}" || true)"
  else
    http_code="$(curl -sS -o "${out}" -w "%{http_code}" -H "Accept: application/json" "${url}" || true)"
  fi

  echo "http_code=${http_code}" > "${out}.meta"
  if [[ "${http_code}" != "404" ]]; then
    echo "idor_expected_404_got=${http_code} url=${url}" >&2
    cat "${out}" >&2 || true
    fail "expected 404 for ${url}"
  fi
}

cleanup() {
  if [[ -n "${SERVE_PID:-}" ]] && ps -p "${SERVE_PID}" >/dev/null 2>&1; then
    kill "${SERVE_PID}" >/dev/null 2>&1 || true
  fi

  if [[ -f "${ART_DIR}/server.pid" ]]; then
    local pid
    pid="$(cat "${ART_DIR}/server.pid" || true)"
    if [[ -n "${pid}" ]] && ps -p "${pid}" >/dev/null 2>&1; then
      kill "${pid}" >/dev/null 2>&1 || true
    fi
  fi

  cleanup_port "${SERVE_PORT}"
  cleanup_port 18000
}
trap cleanup EXIT

cleanup_port "${SERVE_PORT}"
cleanup_port 18000

cd "${BACKEND_DIR}"
php artisan serve --host="${HOST}" --port="${SERVE_PORT}" > "${ART_DIR}/server.log" 2>&1 &
SERVE_PID="$!"
echo "${SERVE_PID}" > "${ART_DIR}/server.pid"
cd "${REPO_DIR}"

wait_health "${API_BASE}/api/v0.2/healthz" || fail "healthz failed"
curl -sS "${API_BASE}/api/v0.2/healthz" > "${ART_DIR}/healthz.json"

cd "${BACKEND_DIR}"
php -r '
require "vendor/autoload.php";
$app = require "bootstrap/app.php";
$app->make(Illuminate\Contracts\Console\Kernel::class)->bootstrap();

$cfgPack = trim((string) config("content_packs.default_pack_id", ""));
$cfgDir = trim((string) config("content_packs.default_dir_version", ""));
$cfgScalesPack = trim((string) config("scales_registry.default_pack_id", ""));
$cfgScalesDir = trim((string) config("scales_registry.default_dir_version", ""));

if ($cfgPack === "" || $cfgDir === "") {
    fwrite(STDERR, "missing_content_pack_defaults\n");
    exit(1);
}

if (!Illuminate\Support\Facades\Schema::hasTable("scales_registry")) {
    fwrite(STDERR, "missing_scales_registry_table\n");
    exit(1);
}

$row = Illuminate\Support\Facades\DB::table("scales_registry")
    ->where("org_id", 0)
    ->where("code", "MBTI")
    ->first();
if (!$row) {
    fwrite(STDERR, "missing_scales_registry_mbti\n");
    exit(1);
}

$rowPack = trim((string) ($row->default_pack_id ?? ""));
$rowDir = trim((string) ($row->default_dir_version ?? ""));
if ($rowPack !== $cfgPack || $rowDir !== $cfgDir) {
    fwrite(STDERR, "pack_seed_config_mismatch\n");
    exit(1);
}

$index = app(App\Services\Content\ContentPacksIndex::class);
$found = $index->find($cfgPack, $cfgDir);
if (!($found["ok"] ?? false)) {
    fwrite(STDERR, "pack_not_found\n");
    exit(1);
}
$item = $found["item"] ?? [];
$manifestPath = (string) ($item["manifest_path"] ?? "");
$questionsPath = (string) ($item["questions_path"] ?? "");
$packDir = $manifestPath !== "" ? dirname($manifestPath) : "";
$versionPath = $packDir !== "" ? $packDir . DIRECTORY_SEPARATOR . "version.json" : "";

foreach ([$manifestPath, $questionsPath, $versionPath] as $path) {
    if ($path === "" || !is_file($path)) {
        fwrite(STDERR, "pack_file_missing:" . $path . "\n");
        exit(1);
    }
    $decoded = json_decode((string) file_get_contents($path), true);
    if (!is_array($decoded)) {
        fwrite(STDERR, "pack_json_invalid:" . $path . "\n");
        exit(1);
    }
}

echo "config_default_pack_id=" . $cfgPack . PHP_EOL;
echo "config_default_dir_version=" . $cfgDir . PHP_EOL;
echo "config_scales_registry_default_pack_id=" . $cfgScalesPack . PHP_EOL;
echo "config_scales_registry_default_dir_version=" . $cfgScalesDir . PHP_EOL;
echo "row_default_pack_id=" . $rowPack . PHP_EOL;
echo "row_default_dir_version=" . $rowDir . PHP_EOL;
echo "manifest_path=" . $manifestPath . PHP_EOL;
echo "questions_path=" . $questionsPath . PHP_EOL;
echo "version_path=" . $versionPath . PHP_EOL;
' > "${ART_DIR}/pack_seed_config.txt"
cd "${REPO_DIR}"

QUESTIONS_JSON="${ART_DIR}/questions.json"
QUESTION_HTTP="$(curl -sS -o "${QUESTIONS_JSON}" -w "%{http_code}" -H "Accept: application/json" "${API_BASE}/api/v0.3/scales/${SCALE_CODE}/questions" || true)"
if [[ "${QUESTION_HTTP}" != "200" ]]; then
  cat "${QUESTIONS_JSON}" >&2 || true
  tail -n 120 "${ART_DIR}/server.log" >&2 || true
  fail "questions endpoint failed http=${QUESTION_HTTP}"
fi

ANSWERS_JSON="${ART_DIR}/answers.json"
php -r '
$raw = file_get_contents("php://stdin");
$j = json_decode($raw, true);
if (!is_array($j)) { fwrite(STDERR, "questions_json_invalid\n"); exit(1); }
$items = $j["questions"]["items"] ?? $j["questions"] ?? $j["items"] ?? $j["data"] ?? $j;
if (!is_array($items)) { fwrite(STDERR, "questions_items_invalid\n"); exit(1); }
$answers = [];
foreach ($items as $item) {
  if (!is_array($item)) { continue; }
  $qid = (string) ($item["question_id"] ?? $item["id"] ?? "");
  if ($qid === "") { continue; }
  $code = "A";
  $options = $item["options"] ?? [];
  if (is_array($options) && isset($options[0]) && is_array($options[0])) {
    $code = (string) ($options[0]["code"] ?? $options[0]["option_code"] ?? "A");
  }
  if ($code === "") { $code = "A"; }
  $answers[] = ["question_id" => $qid, "code" => $code];
}
if (count($answers) === 0) { fwrite(STDERR, "answers_empty\n"); exit(1); }
echo json_encode($answers, JSON_UNESCAPED_UNICODE);
' < "${QUESTIONS_JSON}" > "${ANSWERS_JSON}"

php -r '$a=json_decode(file_get_contents($argv[1]), true); echo "answer_count=".(is_array($a)?count($a):0).PHP_EOL;' "${ANSWERS_JSON}" > "${ART_DIR}/answers_meta.txt"

START_JSON="${ART_DIR}/attempt_start_anon_a.json"
START_BODY="$(SCALE_CODE="${SCALE_CODE}" ANON_A="${ANON_A}" php -r '$p=["scale_code"=>getenv("SCALE_CODE"),"anon_id"=>getenv("ANON_A")]; echo json_encode($p, JSON_UNESCAPED_UNICODE);')"
START_HTTP="$(curl -sS -o "${START_JSON}" -w "%{http_code}" -X POST -H "Content-Type: application/json" -H "Accept: application/json" -H "X-Anon-Id: ${ANON_A}" --data "${START_BODY}" "${API_BASE}/api/v0.3/attempts/start" || true)"
if [[ "${START_HTTP}" != "200" ]]; then
  cat "${START_JSON}" >&2 || true
  tail -n 120 "${ART_DIR}/server.log" >&2 || true
  fail "attempt start failed http=${START_HTTP}"
fi

ATTEMPT_ID="$(php -r '$j=json_decode(file_get_contents($argv[1]), true); echo (string)($j["attempt_id"] ?? "");' "${START_JSON}")"
if [[ -z "${ATTEMPT_ID}" ]]; then
  fail "missing attempt_id"
fi
echo "${ATTEMPT_ID}" > "${ART_DIR}/attempt_id.txt"

echo "${ANON_A}" > "${ART_DIR}/anon_a.txt"
echo "${ANON_B}" > "${ART_DIR}/anon_b.txt"

SUBMIT_PAYLOAD="${ART_DIR}/submit_payload_anon_a.json"
ATTEMPT_ID="${ATTEMPT_ID}" ANSWERS_PATH="${ANSWERS_JSON}" php -r '
$answers = json_decode(file_get_contents(getenv("ANSWERS_PATH")), true);
if (!is_array($answers) || count($answers) === 0) { fwrite(STDERR, "answers_payload_invalid\n"); exit(1); }
$p = [
  "attempt_id" => getenv("ATTEMPT_ID"),
  "answers" => $answers,
  "duration_ms" => 120000,
];
echo json_encode($p, JSON_UNESCAPED_UNICODE);
' > "${SUBMIT_PAYLOAD}"

SUBMIT_A_JSON="${ART_DIR}/submit_anon_a.json"
SUBMIT_A_HTTP="$(curl -sS -o "${SUBMIT_A_JSON}" -w "%{http_code}" -X POST -H "Content-Type: application/json" -H "Accept: application/json" -H "X-Anon-Id: ${ANON_A}" --data-binary @"${SUBMIT_PAYLOAD}" "${API_BASE}/api/v0.3/attempts/submit" || true)"
if [[ "${SUBMIT_A_HTTP}" != "200" ]]; then
  cat "${SUBMIT_A_JSON}" >&2 || true
  tail -n 120 "${ART_DIR}/server.log" >&2 || true
  fail "submit anonA failed http=${SUBMIT_A_HTTP}"
fi

expect_404 "${API_BASE}/api/v0.3/attempts/${ATTEMPT_ID}/result" "${ART_DIR}/idor_result.txt" "X-Anon-Id" "${ANON_B}"
expect_404 "${API_BASE}/api/v0.3/attempts/${ATTEMPT_ID}/report" "${ART_DIR}/idor_report.txt" "X-Anon-Id" "${ANON_B}"

SUBMIT_B_JSON="${ART_DIR}/idor_submit.txt"
SUBMIT_B_HTTP="$(curl -sS -o "${SUBMIT_B_JSON}" -w "%{http_code}" -X POST -H "Content-Type: application/json" -H "Accept: application/json" -H "X-Anon-Id: ${ANON_B}" --data-binary @"${SUBMIT_PAYLOAD}" "${API_BASE}/api/v0.3/attempts/submit" || true)"
echo "http_code=${SUBMIT_B_HTTP}" > "${ART_DIR}/idor_submit.txt.meta"
if [[ "${SUBMIT_B_HTTP}" != "404" ]]; then
  cat "${SUBMIT_B_JSON}" >&2 || true
  fail "idor submit expected 404"
fi

ORDER_NO="ord_pr60_$(date +%s)"
cd "${BACKEND_DIR}"
ORDER_NO="${ORDER_NO}" ANON_A="${ANON_A}" php -r '
require "vendor/autoload.php";
$app = require "bootstrap/app.php";
$app->make(Illuminate\Contracts\Console\Kernel::class)->bootstrap();

$now = now();
$orderNo = getenv("ORDER_NO");
$anonA = getenv("ANON_A");
$row = [
  "id" => (string) Illuminate\Support\Str::uuid(),
  "order_no" => $orderNo,
  "org_id" => 0,
  "user_id" => null,
  "anon_id" => $anonA,
  "sku" => "MBTI_CREDIT",
  "quantity" => 1,
  "target_attempt_id" => null,
  "amount_cents" => 1990,
  "currency" => "USD",
  "status" => "created",
  "provider" => "billing",
  "external_trade_no" => null,
  "paid_at" => null,
  "created_at" => $now,
  "updated_at" => $now,
];
if (Illuminate\Support\Facades\Schema::hasColumn("orders", "amount_total")) { $row["amount_total"] = 1990; }
if (Illuminate\Support\Facades\Schema::hasColumn("orders", "amount_refunded")) { $row["amount_refunded"] = 0; }
if (Illuminate\Support\Facades\Schema::hasColumn("orders", "item_sku")) { $row["item_sku"] = "MBTI_CREDIT"; }
if (Illuminate\Support\Facades\Schema::hasColumn("orders", "provider_order_id")) { $row["provider_order_id"] = null; }
if (Illuminate\Support\Facades\Schema::hasColumn("orders", "device_id")) { $row["device_id"] = null; }
if (Illuminate\Support\Facades\Schema::hasColumn("orders", "request_id")) { $row["request_id"] = null; }
if (Illuminate\Support\Facades\Schema::hasColumn("orders", "created_ip")) { $row["created_ip"] = null; }
if (Illuminate\Support\Facades\Schema::hasColumn("orders", "fulfilled_at")) { $row["fulfilled_at"] = null; }
if (Illuminate\Support\Facades\Schema::hasColumn("orders", "refunded_at")) { $row["refunded_at"] = null; }
if (Illuminate\Support\Facades\Schema::hasColumn("orders", "refund_amount_cents")) { $row["refund_amount_cents"] = null; }
if (Illuminate\Support\Facades\Schema::hasColumn("orders", "refund_reason")) { $row["refund_reason"] = null; }
Illuminate\Support\Facades\DB::table("orders")->insert($row);
echo "order_no=" . $orderNo . PHP_EOL;
' > "${ART_DIR}/order_insert.txt"

ATTEMPT_ID="${ATTEMPT_ID}" php -r '
require "vendor/autoload.php";
$app = require "bootstrap/app.php";
$app->make(Illuminate\Contracts\Console\Kernel::class)->bootstrap();

$attemptId = getenv("ATTEMPT_ID");
Illuminate\Support\Facades\DB::table("attempts")
  ->where("id", $attemptId)
  ->update([
    "calculation_snapshot_json" => json_encode([
      "stats" => ["score" => 42],
      "norm" => ["version" => "test"],
    ], JSON_UNESCAPED_UNICODE),
  ]);

Illuminate\Support\Facades\DB::table("attempt_quality")->insert([
  "attempt_id" => $attemptId,
  "checks_json" => json_encode([["id" => "consistency", "ok" => true]], JSON_UNESCAPED_UNICODE),
  "grade" => "A",
  "created_at" => now(),
]);

echo "psychometrics_seeded_attempt_id=" . $attemptId . PHP_EOL;
' > "${ART_DIR}/psychometrics_seed.txt"
cd "${REPO_DIR}"

echo "${ORDER_NO}" > "${ART_DIR}/order_no.txt"
expect_404 "${API_BASE}/api/v0.3/orders/${ORDER_NO}" "${ART_DIR}/order_idor.txt" "X-Anon-Id" "${ANON_B}"
expect_404 "${API_BASE}/api/v0.2/attempts/${ATTEMPT_ID}/stats" "${ART_DIR}/psy_idor_stats.txt" "X-Anon-Id" "${ANON_B}"
expect_404 "${API_BASE}/api/v0.2/attempts/${ATTEMPT_ID}/quality" "${ART_DIR}/psy_idor_quality.txt" "X-Anon-Id" "${ANON_B}"

if [[ -n "${SERVE_PID:-}" ]] && ps -p "${SERVE_PID}" >/dev/null 2>&1; then
  kill "${SERVE_PID}" >/dev/null 2>&1 || true
fi
cleanup_port "${SERVE_PORT}"
cleanup_port 18000
unset SERVE_PID

echo "verify_done" > "${ART_DIR}/verify_done.txt"
echo "[PR60][VERIFY] pass"
