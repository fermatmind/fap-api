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
ART_DIR="${ART_DIR:-${BACKEND_DIR}/artifacts/pr51}"
SERVE_PORT="${SERVE_PORT:-1851}"
HOST="127.0.0.1"
API_BASE="http://${HOST}:${SERVE_PORT}"
SCALE_CODE="${SCALE_CODE:-MBTI}"
ANON_ID="${ANON_ID:-pr51-verify-anon}"
IDEMPOTENCY_KEY="${IDEMPOTENCY_KEY:-pr51-idempotency-scope}"

export FAP_PACKS_ROOT="${FAP_PACKS_ROOT:-${REPO_DIR}/content_packages}"
export FAP_DEFAULT_REGION="${FAP_DEFAULT_REGION:-CN_MAINLAND}"
export FAP_DEFAULT_LOCALE="${FAP_DEFAULT_LOCALE:-zh-CN}"
export FAP_DEFAULT_PACK_ID="${FAP_DEFAULT_PACK_ID:-MBTI.cn-mainland.zh-CN.v0.3}"
export FAP_DEFAULT_DIR_VERSION="${FAP_DEFAULT_DIR_VERSION:-MBTI-CN-v0.3}"

mkdir -p "${ART_DIR}"
exec > "${ART_DIR}/verify.log" 2>&1

fail() {
  echo "[PR51][FAIL] $*" >&2
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

cleanup() {
  if [[ -n "${SERVE_PID:-}" ]] && ps -p "${SERVE_PID}" >/dev/null 2>&1; then
    kill "${SERVE_PID}" >/dev/null 2>&1 || true
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

wait_health "${API_BASE}/api/healthz" || fail "healthz failed"
curl -sS "${API_BASE}/api/healthz" > "${ART_DIR}/healthz.json"

cd "${BACKEND_DIR}"
php -r '
require "vendor/autoload.php";
$app = require "bootstrap/app.php";
$app->make(Illuminate\Contracts\Console\Kernel::class)->bootstrap();

$configPackId = (string) config("content_packs.default_pack_id", "");
$configDirVersion = (string) config("content_packs.default_dir_version", "");
$region = (string) config("content_packs.default_region", "");
$locale = (string) config("content_packs.default_locale", "");
$root = (string) config("content_packs.root", "");

echo "config_default_pack_id=".$configPackId.PHP_EOL;
echo "config_default_dir_version=".$configDirVersion.PHP_EOL;
if ($configPackId === "" || $configDirVersion === "" || $root === "") {
    fwrite(STDERR, "content_pack_config_missing\n");
    exit(1);
}

if (!Illuminate\Support\Facades\Schema::hasTable("scales_registry")) {
    fwrite(STDERR, "missing_scales_registry\n");
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

$registryPackId = (string) ($row->default_pack_id ?? "");
echo "registry_default_pack_id=".$registryPackId.PHP_EOL;
if ($registryPackId !== $configPackId) {
    fwrite(STDERR, "default_pack_id_mismatch\n");
    exit(1);
}

$registryDirVersion = "";
if (Illuminate\Support\Facades\Schema::hasColumn("scales_registry", "default_dir_version")) {
    $registryDirVersion = (string) ($row->default_dir_version ?? "");
}
if ($registryDirVersion !== "") {
    echo "registry_default_dir_version=".$registryDirVersion.PHP_EOL;
    if ($registryDirVersion !== $configDirVersion) {
        fwrite(STDERR, "default_dir_version_mismatch\n");
        exit(1);
    }
}

$candidates = [
    $root."/default/".$region."/".$locale."/".$configDirVersion,
    $root."/".$configDirVersion,
];
$packDir = "";
foreach ($candidates as $candidate) {
    if (is_dir($candidate)) {
        $packDir = $candidate;
        break;
    }
}
if ($packDir === "") {
    fwrite(STDERR, "pack_dir_missing\n");
    exit(1);
}

$versionPath = $packDir."/version.json";
$questionsPath = $packDir."/questions.json";
$manifestPath = $packDir."/manifest.json";
foreach ([$versionPath, $questionsPath, $manifestPath] as $path) {
    if (!is_file($path)) {
        fwrite(STDERR, "pack_file_missing:".$path."\n");
        exit(1);
    }
    $decoded = json_decode((string) file_get_contents($path), true);
    if (!is_array($decoded)) {
        fwrite(STDERR, "pack_file_invalid_json:".$path."\n");
        exit(1);
    }
}

echo "pack_dir=".$packDir.PHP_EOL;
echo "pack_manifest=".$manifestPath.PHP_EOL;
echo "pack_questions=".$questionsPath.PHP_EOL;
echo "pack_version=".$versionPath.PHP_EOL;
' > "${ART_DIR}/pack_seed_config.txt"
cd "${REPO_DIR}"

grep -E "^config_default_pack_id=" "${ART_DIR}/pack_seed_config.txt" | sed "s/^config_default_pack_id=//" > "${ART_DIR}/config_default_pack_id.txt"

QUESTIONS_JSON="${ART_DIR}/questions.json"
http_code="$(curl -sS -o "${QUESTIONS_JSON}" -w "%{http_code}" \
  -H "Accept: application/json" \
  "${API_BASE}/api/v0.3/scales/${SCALE_CODE}/questions" || true)"
if [[ "${http_code}" != "200" ]]; then
  echo "questions_failed http=${http_code}" >&2
  cat "${QUESTIONS_JSON}" >&2 || true
  tail -n 120 "${ART_DIR}/server.log" >&2 || true
  fail "questions endpoint failed"
fi

ANSWERS_JSON="${ART_DIR}/answers.json"
php -r '
$raw = file_get_contents("php://stdin");
$data = json_decode($raw, true);
if (!is_array($data)) {
    fwrite(STDERR, "questions_json_invalid\n");
    exit(1);
}
$items = $data["questions"]["items"] ?? $data["questions"] ?? $data["items"] ?? $data["data"] ?? $data;
if (!is_array($items)) {
    fwrite(STDERR, "questions_items_invalid\n");
    exit(1);
}
$answers = [];
foreach ($items as $item) {
    if (!is_array($item)) {
        continue;
    }
    $questionId = (string) ($item["question_id"] ?? $item["id"] ?? "");
    if ($questionId === "") {
        continue;
    }
    $options = $item["options"] ?? [];
    $code = "A";
    if (is_array($options) && isset($options[0]) && is_array($options[0])) {
        $code = (string) ($options[0]["code"] ?? $options[0]["option_code"] ?? "A");
    }
    if ($code === "") {
        $code = "A";
    }
    $answers[] = [
        "question_id" => $questionId,
        "code" => $code,
    ];
}
if (count($answers) === 0) {
    fwrite(STDERR, "answers_empty\n");
    exit(1);
}
echo json_encode($answers, JSON_UNESCAPED_UNICODE);
' < "${QUESTIONS_JSON}" > "${ANSWERS_JSON}"

QUESTION_COUNT="$(php -r '
$data = json_decode(file_get_contents($argv[1]), true);
if (!is_array($data)) { echo "0"; exit(0); }
echo (string) count($data);
' "${ANSWERS_JSON}")"
if [[ "${QUESTION_COUNT}" == "0" ]]; then
  fail "dynamic answers generation produced zero answers"
fi
echo "${QUESTION_COUNT}" > "${ART_DIR}/question_count.txt"

ATTEMPT_START_JSON="${ART_DIR}/attempt_start.json"
ATTEMPT_START_BODY="$(SCALE_CODE="${SCALE_CODE}" ANON_ID="${ANON_ID}" php -r '
$payload = [
    "scale_code" => getenv("SCALE_CODE"),
    "anon_id" => getenv("ANON_ID"),
];
echo json_encode($payload, JSON_UNESCAPED_UNICODE);
')"
http_code="$(curl -sS -o "${ATTEMPT_START_JSON}" -w "%{http_code}" \
  -X POST -H "Content-Type: application/json" -H "Accept: application/json" \
  --data "${ATTEMPT_START_BODY}" \
  "${API_BASE}/api/v0.3/attempts/start" || true)"
if [[ "${http_code}" != "200" ]]; then
  echo "attempt_start_failed http=${http_code}" >&2
  cat "${ATTEMPT_START_JSON}" >&2 || true
  tail -n 120 "${ART_DIR}/server.log" >&2 || true
  fail "attempt start failed"
fi

ATTEMPT_ID="$(php -r '$j=json_decode(file_get_contents($argv[1]), true); echo (string)($j["attempt_id"] ?? "");' "${ATTEMPT_START_JSON}")"
if [[ -z "${ATTEMPT_ID}" ]]; then
  fail "missing attempt_id"
fi
echo "${ATTEMPT_ID}" > "${ART_DIR}/attempt_id.txt"
echo "${ANON_ID}" > "${ART_DIR}/anon_id.txt"

SUBMIT_PAYLOAD="${ART_DIR}/submit_payload.json"
ATTEMPT_ID="${ATTEMPT_ID}" ANSWERS_PATH="${ANSWERS_JSON}" php -r '
$answers = json_decode((string) file_get_contents(getenv("ANSWERS_PATH")), true);
if (!is_array($answers) || count($answers) === 0) {
    fwrite(STDERR, "answers_payload_invalid\n");
    exit(1);
}
$payload = [
    "attempt_id" => getenv("ATTEMPT_ID"),
    "answers" => $answers,
    "duration_ms" => 120000,
];
echo json_encode($payload, JSON_UNESCAPED_UNICODE);
' > "${SUBMIT_PAYLOAD}"

ATTEMPT_SUBMIT_JSON="${ART_DIR}/attempt_submit.json"
http_code="$(curl -sS -o "${ATTEMPT_SUBMIT_JSON}" -w "%{http_code}" \
  -X POST -H "Content-Type: application/json" -H "Accept: application/json" \
  --data @"${SUBMIT_PAYLOAD}" \
  "${API_BASE}/api/v0.3/attempts/submit" || true)"
if [[ "${http_code}" != "200" ]]; then
  echo "attempt_submit_failed http=${http_code}" >&2
  cat "${ATTEMPT_SUBMIT_JSON}" >&2 || true
  tail -n 120 "${ART_DIR}/server.log" >&2 || true
  fail "attempt submit failed"
fi

ORDER_PAYLOAD="${ART_DIR}/order_payload.json"
cat > "${ORDER_PAYLOAD}" <<'JSON'
{"sku":"MBTI_CREDIT","quantity":1}
JSON

ORDER_STRIPE_1_JSON="${ART_DIR}/order_stripe_1.json"
http_code="$(curl -sS -o "${ORDER_STRIPE_1_JSON}" -w "%{http_code}" \
  -X POST -H "Content-Type: application/json" -H "Accept: application/json" \
  -H "X-Org-Id: 0" -H "Idempotency-Key: ${IDEMPOTENCY_KEY}" \
  --data @"${ORDER_PAYLOAD}" \
  "${API_BASE}/api/v0.3/orders/stripe" || true)"
if [[ "${http_code}" != "200" ]]; then
  echo "order_stripe_failed http=${http_code}" >&2
  cat "${ORDER_STRIPE_1_JSON}" >&2 || true
  tail -n 120 "${ART_DIR}/server.log" >&2 || true
  fail "stripe order create failed"
fi

ORDER_BILLING_1_JSON="${ART_DIR}/order_billing_1.json"
http_code="$(curl -sS -o "${ORDER_BILLING_1_JSON}" -w "%{http_code}" \
  -X POST -H "Content-Type: application/json" -H "Accept: application/json" \
  -H "X-Org-Id: 0" -H "Idempotency-Key: ${IDEMPOTENCY_KEY}" \
  --data @"${ORDER_PAYLOAD}" \
  "${API_BASE}/api/v0.3/orders/billing" || true)"
if [[ "${http_code}" != "200" ]]; then
  echo "order_billing_failed http=${http_code}" >&2
  cat "${ORDER_BILLING_1_JSON}" >&2 || true
  tail -n 120 "${ART_DIR}/server.log" >&2 || true
  fail "billing order create failed"
fi

ORDER_BILLING_2_JSON="${ART_DIR}/order_billing_2.json"
http_code="$(curl -sS -o "${ORDER_BILLING_2_JSON}" -w "%{http_code}" \
  -X POST -H "Content-Type: application/json" -H "Accept: application/json" \
  -H "X-Org-Id: 0" -H "Idempotency-Key: ${IDEMPOTENCY_KEY}" \
  --data @"${ORDER_PAYLOAD}" \
  "${API_BASE}/api/v0.3/orders/billing" || true)"
if [[ "${http_code}" != "200" ]]; then
  echo "order_billing_repeat_failed http=${http_code}" >&2
  cat "${ORDER_BILLING_2_JSON}" >&2 || true
  tail -n 120 "${ART_DIR}/server.log" >&2 || true
  fail "billing repeat order create failed"
fi

STRIPE_ORDER_NO="$(php -r '$j=json_decode(file_get_contents($argv[1]), true); echo (string)($j["order_no"] ?? "");' "${ORDER_STRIPE_1_JSON}")"
BILLING_ORDER_NO_1="$(php -r '$j=json_decode(file_get_contents($argv[1]), true); echo (string)($j["order_no"] ?? "");' "${ORDER_BILLING_1_JSON}")"
BILLING_ORDER_NO_2="$(php -r '$j=json_decode(file_get_contents($argv[1]), true); echo (string)($j["order_no"] ?? "");' "${ORDER_BILLING_2_JSON}")"

if [[ -z "${STRIPE_ORDER_NO}" || -z "${BILLING_ORDER_NO_1}" || -z "${BILLING_ORDER_NO_2}" ]]; then
  fail "missing order_no in idempotency responses"
fi
if [[ "${STRIPE_ORDER_NO}" == "${BILLING_ORDER_NO_1}" ]]; then
  fail "idempotency key not scoped by provider"
fi
if [[ "${BILLING_ORDER_NO_1}" != "${BILLING_ORDER_NO_2}" ]]; then
  fail "same provider idempotency replay returned different order_no"
fi

echo "${STRIPE_ORDER_NO}" > "${ART_DIR}/order_stripe_no.txt"
echo "${BILLING_ORDER_NO_1}" > "${ART_DIR}/order_billing_no.txt"

cd "${BACKEND_DIR}"
IDEMPOTENCY_KEY="${IDEMPOTENCY_KEY}" php -r '
require "vendor/autoload.php";
$app = require "bootstrap/app.php";
$app->make(Illuminate\Contracts\Console\Kernel::class)->bootstrap();
$key = (string) getenv("IDEMPOTENCY_KEY");
$stripeCount = Illuminate\Support\Facades\DB::table("orders")
    ->where("org_id", 0)
    ->where("provider", "stripe")
    ->where("idempotency_key", $key)
    ->count();
$billingCount = Illuminate\Support\Facades\DB::table("orders")
    ->where("org_id", 0)
    ->where("provider", "billing")
    ->where("idempotency_key", $key)
    ->count();
echo "stripe_count=".$stripeCount.PHP_EOL;
echo "billing_count=".$billingCount.PHP_EOL;
if ((int) $stripeCount !== 1 || (int) $billingCount !== 1) {
    fwrite(STDERR, "idempotency_scope_db_assertion_failed\n");
    exit(1);
}
' > "${ART_DIR}/idempotency_scope_check.txt"
cd "${REPO_DIR}"

echo "[PR51][OK] verify complete"
