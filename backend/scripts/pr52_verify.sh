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
ART_DIR="${ART_DIR:-${BACKEND_DIR}/artifacts/pr52}"
SERVE_PORT="${SERVE_PORT:-1852}"
HOST="127.0.0.1"
API_BASE="http://${HOST}:${SERVE_PORT}"
SCALE_CODE="${SCALE_CODE:-MBTI}"
ANON_ID="${ANON_ID:-pr52-verify-anon}"

export FAP_PACKS_ROOT="${FAP_PACKS_ROOT:-${REPO_DIR}/content_packages}"
export FAP_DEFAULT_REGION="${FAP_DEFAULT_REGION:-CN_MAINLAND}"
export FAP_DEFAULT_LOCALE="${FAP_DEFAULT_LOCALE:-zh-CN}"
export FAP_DEFAULT_PACK_ID="${FAP_DEFAULT_PACK_ID:-MBTI.cn-mainland.zh-CN.v0.2.2}"
export FAP_DEFAULT_DIR_VERSION="${FAP_DEFAULT_DIR_VERSION:-MBTI-CN-v0.2.2}"

mkdir -p "${ART_DIR}"
exec > "${ART_DIR}/verify.log" 2>&1

fail() {
  echo "[PR52][FAIL] $*" >&2
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

# pack/seed/config consistency
cd "${BACKEND_DIR}"
php -r '
require "vendor/autoload.php";
$app=require "bootstrap/app.php";
$app->make(Illuminate\Contracts\Console\Kernel::class)->bootstrap();
echo (string) config("content_packs.default_pack_id", "");
' > "${ART_DIR}/config_default_pack_id.txt"

php -r '
require "vendor/autoload.php";
$app=require "bootstrap/app.php";
$app->make(Illuminate\Contracts\Console\Kernel::class)->bootstrap();
$packId = (string) config("content_packs.default_pack_id", "");
$dirVersion = (string) config("content_packs.default_dir_version", "");
echo "config_default_pack_id=".$packId.PHP_EOL;
echo "config_default_dir_version=".$dirVersion.PHP_EOL;
if ($packId === "" || $dirVersion === "") {
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
$registryPackId = (string) ($row->default_pack_id ?? "");
$registryDirVersion = (string) ($row->default_dir_version ?? "");
echo "registry_default_pack_id=".$registryPackId.PHP_EOL;
echo "registry_default_dir_version=".$registryDirVersion.PHP_EOL;
if ($registryPackId !== $packId) {
    fwrite(STDERR, "default_pack_id_mismatch\n");
    exit(1);
}
if ($registryDirVersion !== $dirVersion) {
    fwrite(STDERR, "default_dir_version_mismatch\n");
    exit(1);
}
$index = app(App\Services\Content\ContentPacksIndex::class);
$found = $index->find($packId, $dirVersion);
if (!($found["ok"] ?? false)) {
    fwrite(STDERR, "pack_not_found\n");
    exit(1);
}
$item = $found["item"] ?? [];
$manifestPath = (string) ($item["manifest_path"] ?? "");
$questionsPath = (string) ($item["questions_path"] ?? "");
if ($manifestPath === "" || !is_file($manifestPath)) {
    fwrite(STDERR, "manifest_missing\n");
    exit(1);
}
if ($questionsPath === "" || !is_file($questionsPath)) {
    fwrite(STDERR, "questions_missing\n");
    exit(1);
}
$packDir = dirname($manifestPath);
$versionPath = $packDir.DIRECTORY_SEPARATOR."version.json";
if (!is_file($versionPath)) {
    fwrite(STDERR, "version_missing\n");
    exit(1);
}
$manifest = json_decode((string) file_get_contents($manifestPath), true);
$questions = json_decode((string) file_get_contents($questionsPath), true);
$version = json_decode((string) file_get_contents($versionPath), true);
if (!is_array($manifest) || !is_array($questions) || !is_array($version)) {
    fwrite(STDERR, "pack_json_invalid\n");
    exit(1);
}
echo "pack_manifest=".$manifestPath.PHP_EOL;
echo "pack_questions=".$questionsPath.PHP_EOL;
echo "pack_version=".$versionPath.PHP_EOL;
' > "${ART_DIR}/pack_seed_config.txt"
cd "${REPO_DIR}"

# error contract checks
SHARE_INVALID_JSON="${ART_DIR}/share_invalid_uuid.json"
http_code="$(curl -sS -o "${SHARE_INVALID_JSON}" -w "%{http_code}" -X POST "${API_BASE}/api/v0.3/shares/not-a-uuid/click" || true)"
[[ "${http_code}" == "404" ]] || fail "invalid share uuid must return 404"
php -r '$j=json_decode(file_get_contents($argv[1]), true); if (!is_array($j) || (($j["error_code"] ?? "") !== "NOT_FOUND")) {fwrite(STDERR,"share_invalid_uuid_error_code_mismatch\n"); exit(1);} ' "${SHARE_INVALID_JSON}"

ME_ATTEMPTS_JSON="${ART_DIR}/me_attempts_unauthorized.json"
http_code="$(curl -sS -o "${ME_ATTEMPTS_JSON}" -w "%{http_code}" "${API_BASE}/api/v0.3/me/attempts" || true)"
[[ "${http_code}" == "401" ]] || fail "me attempts without token must return 401"
php -r '$j=json_decode(file_get_contents($argv[1]), true); if (!is_array($j) || (($j["error_code"] ?? "") !== "UNAUTHORIZED")) {fwrite(STDERR,"me_attempts_error_code_mismatch\n"); exit(1);} ' "${ME_ATTEMPTS_JSON}"

ORG_404_JSON="${ART_DIR}/org_not_found.json"
http_code="$(curl -sS -o "${ORG_404_JSON}" -w "%{http_code}" -H "X-Org-Id: 999999" "${API_BASE}/api/v0.3/scales" || true)"
[[ "${http_code}" == "404" ]] || fail "non-member org context must return 404"
php -r '$j=json_decode(file_get_contents($argv[1]), true); if (!is_array($j) || (($j["error_code"] ?? "") !== "ORG_NOT_FOUND")) {fwrite(STDERR,"org_not_found_error_code_mismatch\n"); exit(1);} ' "${ORG_404_JSON}"

V03_MISS_JSON="${ART_DIR}/v03_missing_route.json"
http_code="$(curl -sS -o "${V03_MISS_JSON}" -w "%{http_code}" "${API_BASE}/api/v0.3/__missing" || true)"
[[ "${http_code}" == "404" ]] || fail "missing v0.3 route must return 404"
php -r '$j=json_decode(file_get_contents($argv[1]), true); if (!is_array($j) || (($j["error_code"] ?? "") !== "NOT_FOUND")) {fwrite(STDERR,"v03_missing_error_code_mismatch\n"); exit(1);} ' "${V03_MISS_JSON}"

QUESTIONS_JSON="${ART_DIR}/questions.json"
http_code="$(curl -sS -o "${QUESTIONS_JSON}" -w "%{http_code}" \
  -H "Accept: application/json" \
  "${API_BASE}/api/v0.3/scales/${SCALE_CODE}/questions" || true)"
if [[ "${http_code}" != "200" ]]; then
  echo "questions_failed http=${http_code}" >&2
  cat "${QUESTIONS_JSON}" >&2 || true
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
$answers = json_decode(file_get_contents(getenv("ANSWERS_PATH")), true);
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
  fail "attempt submit failed"
fi

cd "${BACKEND_DIR}"
php artisan test --filter ApiExceptionRendererTest > "${ART_DIR}/phpunit_api_exception_renderer.txt" 2>&1
php artisan test --filter UuidRouteParamsMiddlewareTest > "${ART_DIR}/phpunit_uuid_route_params.txt" 2>&1
php artisan test --filter OrgContextMiddlewareTest > "${ART_DIR}/phpunit_org_context.txt" 2>&1
php artisan test --filter MeAttemptsAuthContractTest > "${ART_DIR}/phpunit_me_attempts_contract.txt" 2>&1
php artisan test --filter ApiErrorContractMiddlewareTest > "${ART_DIR}/phpunit_error_contract_middleware.txt" 2>&1
cd "${REPO_DIR}"

echo "verify_done" > "${ART_DIR}/verify_done.txt"
