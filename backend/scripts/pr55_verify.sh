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
ART_DIR="${ART_DIR:-${BACKEND_DIR}/artifacts/pr55}"
SERVE_PORT="${SERVE_PORT:-1855}"
HOST="127.0.0.1"
API_BASE="http://${HOST}:${SERVE_PORT}"
SCALE_CODE="${SCALE_CODE:-MBTI}"
ANON_ID="${ANON_ID:-pr55-verify-anon}"
FAP_ADMIN_TOKEN="${FAP_ADMIN_TOKEN:-pr55-admin-token}"

mkdir -p "${ART_DIR}"
exec > "${ART_DIR}/verify.log" 2>&1

fail() {
  echo "[PR55][FAIL] $*" >&2
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

$configPackId = trim((string) config("content_packs.default_pack_id", ""));
$configDirVersion = trim((string) config("content_packs.default_dir_version", ""));
$configRegion = trim((string) config("content_packs.default_region", ""));
$configLocale = trim((string) config("content_packs.default_locale", ""));

echo "config_default_pack_id=".$configPackId.PHP_EOL;
echo "config_default_dir_version=".$configDirVersion.PHP_EOL;
echo "config_default_region=".$configRegion.PHP_EOL;
echo "config_default_locale=".$configLocale.PHP_EOL;

if ($configPackId === "" || $configDirVersion === "" || $configRegion === "" || $configLocale === "") {
    fwrite(STDERR, "content_pack_defaults_missing\n");
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

$registryPackId = trim((string) ($row->default_pack_id ?? ""));
$registryDirVersion = trim((string) ($row->default_dir_version ?? ""));

echo "registry_default_pack_id=".$registryPackId.PHP_EOL;
echo "registry_default_dir_version=".$registryDirVersion.PHP_EOL;

if ($registryPackId !== $configPackId) {
    fwrite(STDERR, "default_pack_id_mismatch\n");
    exit(1);
}
if ($registryDirVersion !== $configDirVersion) {
    fwrite(STDERR, "default_dir_version_mismatch\n");
    exit(1);
}

$index = app(App\Services\Content\ContentPacksIndex::class);
$found = $index->find($configPackId, $configDirVersion);
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
$version = json_decode((string) file_get_contents($versionPath), true);
$questions = json_decode((string) file_get_contents($questionsPath), true);

if (!is_array($manifest) || !is_array($version) || !is_array($questions)) {
    fwrite(STDERR, "pack_json_invalid\n");
    exit(1);
}
if (trim((string) ($manifest["pack_id"] ?? "")) !== $configPackId) {
    fwrite(STDERR, "manifest_pack_id_mismatch\n");
    exit(1);
}
if (trim((string) ($version["pack_id"] ?? "")) !== $configPackId) {
    fwrite(STDERR, "version_pack_id_mismatch\n");
    exit(1);
}
if (trim((string) ($version["dir_version"] ?? "")) !== $configDirVersion) {
    fwrite(STDERR, "version_dir_version_mismatch\n");
    exit(1);
}

echo "pack_dir=".$packDir.PHP_EOL;
echo "pack_manifest=".$manifestPath.PHP_EOL;
echo "pack_questions=".$questionsPath.PHP_EOL;
echo "pack_version=".$versionPath.PHP_EOL;
' > "${ART_DIR}/pack_seed_config.txt"
cd "${REPO_DIR}"

grep -E "^config_default_pack_id=" "${ART_DIR}/pack_seed_config.txt" | sed "s/^config_default_pack_id=//" > "${ART_DIR}/config_default_pack_id.txt"
grep -E "^config_default_dir_version=" "${ART_DIR}/pack_seed_config.txt" | sed "s/^config_default_dir_version=//" > "${ART_DIR}/config_default_dir_version.txt"

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
if (!is_array($data)) {
    echo "0";
    exit(0);
}
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
  -X POST -H "Content-Type: application/json" -H "Accept: application/json" -H "X-Anon-Id: ${ANON_ID}" \
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
  -X POST -H "Content-Type: application/json" -H "Accept: application/json" -H "X-Anon-Id: ${ANON_ID}" \
  --data @"${SUBMIT_PAYLOAD}" \
  "${API_BASE}/api/v0.3/attempts/submit" || true)"
if [[ "${http_code}" != "200" ]]; then
  echo "attempt_submit_failed http=${http_code}" >&2
  cat "${ATTEMPT_SUBMIT_JSON}" >&2 || true
  tail -n 120 "${ART_DIR}/server.log" >&2 || true
  fail "attempt submit failed"
fi

cd "${BACKEND_DIR}"
php -r '
require "vendor/autoload.php";
$app = require "bootstrap/app.php";
$app->make(Illuminate\Contracts\Console\Kernel::class)->bootstrap();

App\Support\Database\SchemaIndex::logIndexAction(
    "create_index",
    "migration_index_audits",
    "migration_index_audits_migration_idx",
    null,
    [
        "phase" => "up",
        "status" => "logged",
        "reason" => "pr55_verify",
    ]
);

$total = (int) Illuminate\Support\Facades\DB::table("migration_index_audits")->count();
echo "audit_total=".$total.PHP_EOL;
' > "${ART_DIR}/schema_index_log_seed.txt"
cd "${REPO_DIR}"

AUDIT_TOTAL="$(grep -E '^audit_total=' "${ART_DIR}/schema_index_log_seed.txt" | sed 's/^audit_total=//')"
if [[ -z "${AUDIT_TOTAL}" || "${AUDIT_TOTAL}" == "0" ]]; then
  fail "schema index audit seed failed"
fi

echo "${AUDIT_TOTAL}" > "${ART_DIR}/audit_total.txt"

MIGRATION_OBS_JSON="${ART_DIR}/migration_observability.json"
http_code="$(curl -sS -o "${MIGRATION_OBS_JSON}" -w "%{http_code}" \
  -H "Accept: application/json" \
  -H "X-FAP-Admin-Token: ${FAP_ADMIN_TOKEN}" \
  "${API_BASE}/api/v0.2/admin/migrations/observability?limit=5" || true)"
if [[ "${http_code}" != "200" ]]; then
  echo "migration_observability_failed http=${http_code}" >&2
  cat "${MIGRATION_OBS_JSON}" >&2 || true
  tail -n 120 "${ART_DIR}/server.log" >&2 || true
  fail "migration observability endpoint failed"
fi

INDEX_AUDIT_TOTAL="$(php -r '$j=json_decode(file_get_contents($argv[1]), true); echo (string)($j["data"]["index_audit"]["total"] ?? "0");' "${MIGRATION_OBS_JSON}")"
if [[ "${INDEX_AUDIT_TOTAL}" == "0" ]]; then
  fail "migration observability index_audit total is zero"
fi
echo "${INDEX_AUDIT_TOTAL}" > "${ART_DIR}/index_audit_total.txt"

ROLLBACK_PREVIEW_JSON="${ART_DIR}/migration_rollback_preview.json"
http_code="$(curl -sS -o "${ROLLBACK_PREVIEW_JSON}" -w "%{http_code}" \
  -H "Accept: application/json" \
  -H "X-FAP-Admin-Token: ${FAP_ADMIN_TOKEN}" \
  "${API_BASE}/api/v0.2/admin/migrations/rollback-preview?steps=2" || true)"
if [[ "${http_code}" != "200" ]]; then
  echo "rollback_preview_failed http=${http_code}" >&2
  cat "${ROLLBACK_PREVIEW_JSON}" >&2 || true
  tail -n 120 "${ART_DIR}/server.log" >&2 || true
  fail "rollback preview endpoint failed"
fi

ROLLBACK_COUNT="$(php -r '$j=json_decode(file_get_contents($argv[1]), true); $items=$j["data"]["items"] ?? []; echo (string)(is_array($items) ? count($items) : 0);' "${ROLLBACK_PREVIEW_JSON}")"
if [[ "${ROLLBACK_COUNT}" == "0" ]]; then
  fail "rollback preview returned no items"
fi
echo "${ROLLBACK_COUNT}" > "${ART_DIR}/rollback_preview_count.txt"

cd "${BACKEND_DIR}"
php artisan test --filter AdminMigrationObservabilityTest > "${ART_DIR}/phpunit_admin_migration_observability.txt" 2>&1
php artisan test --filter SchemaIndexTest > "${ART_DIR}/phpunit_schema_index.txt" 2>&1
cd "${REPO_DIR}"

echo "verify_done" > "${ART_DIR}/verify_done.txt"
