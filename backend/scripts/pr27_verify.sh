#!/usr/bin/env bash
set -euo pipefail

export CI=true
export FAP_NONINTERACTIVE=1
export COMPOSER_NO_INTERACTION=1
export GIT_TERMINAL_PROMPT=0
export NO_COLOR=1
export FAP_DEFAULT_PACK_ID=MBTI.cn-mainland.zh-CN.v0.2.2
export FAP_DEFAULT_DIR_VERSION=MBTI-CN-v0.2.2
export FAP_DEFAULT_REGION=CN_MAINLAND
export FAP_DEFAULT_LOCALE=zh-CN

REPO_DIR="$(cd "$(dirname "${BASH_SOURCE[0]}")/../.." && pwd)"
BACKEND_DIR="${REPO_DIR}/backend"
ART_DIR="${BACKEND_DIR}/artifacts/pr27"
SERVE_PORT="${SERVE_PORT:-1827}"
API_BASE="http://127.0.0.1:${SERVE_PORT}"

mkdir -p "${ART_DIR}"

# Force port cleanup
for p in "${SERVE_PORT}" 18000; do
  lsof -ti tcp:${p} | xargs -r kill -9 || true
done

cd "${BACKEND_DIR}"
if [[ ! -f ".env" ]]; then
  cp -a .env.example .env
fi
php artisan key:generate --force >/dev/null 2>&1 || true

SERVER_PID=""
cleanup() {
  if [[ -n "${SERVER_PID}" ]]; then
    kill "${SERVER_PID}" >/dev/null 2>&1 || true
    sleep 0.2
    if kill -0 "${SERVER_PID}" >/dev/null 2>&1; then
      kill -9 "${SERVER_PID}" >/dev/null 2>&1 || true
    fi
  fi
  lsof -nP -iTCP:${SERVE_PORT} -sTCP:LISTEN >/dev/null 2>&1 && lsof -nP -iTCP:${SERVE_PORT} -sTCP:LISTEN || true
}
trap cleanup EXIT

# Config + seed consistency
DEFAULT_PACK_ID_FILE="${ART_DIR}/default_pack_id.txt"
DEFAULT_DIR_VERSION_FILE="${ART_DIR}/default_dir_version.txt"
DEFAULT_REGION_FILE="${ART_DIR}/default_region.txt"
DEFAULT_LOCALE_FILE="${ART_DIR}/default_locale.txt"
SCALES_PACK_ID_FILE="${ART_DIR}/scales_registry_default_pack_id.txt"
SCALES_DIR_VERSION_FILE="${ART_DIR}/scales_registry_default_dir_version.txt"
PACK_DIR_FILE="${ART_DIR}/pack_dir.txt"

php -r 'require "vendor/autoload.php"; $app=require "bootstrap/app.php"; $app->make(Illuminate\Contracts\Console\Kernel::class)->bootstrap(); echo (string) config("content_packs.default_pack_id", "");' > "${DEFAULT_PACK_ID_FILE}"
php -r 'require "vendor/autoload.php"; $app=require "bootstrap/app.php"; $app->make(Illuminate\Contracts\Console\Kernel::class)->bootstrap(); echo (string) config("content_packs.default_dir_version", "");' > "${DEFAULT_DIR_VERSION_FILE}"
php -r 'require "vendor/autoload.php"; $app=require "bootstrap/app.php"; $app->make(Illuminate\Contracts\Console\Kernel::class)->bootstrap(); echo (string) config("content_packs.default_region", "");' > "${DEFAULT_REGION_FILE}"
php -r 'require "vendor/autoload.php"; $app=require "bootstrap/app.php"; $app->make(Illuminate\Contracts\Console\Kernel::class)->bootstrap(); echo (string) config("content_packs.default_locale", "");' > "${DEFAULT_LOCALE_FILE}"

php -r 'require "vendor/autoload.php"; $app=require "bootstrap/app.php"; $app->make(Illuminate\Contracts\Console\Kernel::class)->bootstrap(); if (!Illuminate\Support\Facades\Schema::hasTable("scales_registry")) { fwrite(STDERR, "missing scales_registry table\n"); exit(1);} $row=Illuminate\Support\Facades\DB::table("scales_registry")->where("org_id",0)->where("code","MBTI")->first(); if (!$row) { fwrite(STDERR, "missing scales_registry row for MBTI\n"); exit(1);} echo (string) ($row->default_pack_id ?? "");' > "${SCALES_PACK_ID_FILE}"
php -r 'require "vendor/autoload.php"; $app=require "bootstrap/app.php"; $app->make(Illuminate\Contracts\Console\Kernel::class)->bootstrap(); $row=Illuminate\Support\Facades\DB::table("scales_registry")->where("org_id",0)->where("code","MBTI")->first(); if (!$row) { fwrite(STDERR, "missing scales_registry row for MBTI\n"); exit(1);} echo (string) ($row->default_dir_version ?? "");' > "${SCALES_DIR_VERSION_FILE}"

if [[ "$(cat "${DEFAULT_PACK_ID_FILE}")" != "$(cat "${SCALES_PACK_ID_FILE}")" ]]; then
  echo "default_pack_id_mismatch" >&2
  echo "config=$(cat "${DEFAULT_PACK_ID_FILE}")" >&2
  echo "scales_registry=$(cat "${SCALES_PACK_ID_FILE}")" >&2
  exit 1
fi

if [[ "$(cat "${DEFAULT_DIR_VERSION_FILE}")" != "$(cat "${SCALES_DIR_VERSION_FILE}")" ]]; then
  echo "default_dir_version_mismatch" >&2
  echo "config=$(cat "${DEFAULT_DIR_VERSION_FILE}")" >&2
  echo "scales_registry=$(cat "${SCALES_DIR_VERSION_FILE}")" >&2
  exit 1
fi

PACK_DIR_REL="$(php -r '
require "vendor/autoload.php";
$app=require "bootstrap/app.php";
$app->make(Illuminate\Contracts\Console\Kernel::class)->bootstrap();
$root=(string) config("content_packs.root", "");
$region=(string) config("content_packs.default_region", "");
$locale=(string) config("content_packs.default_locale", "");
$dir=(string) config("content_packs.default_dir_version", "");
$packId=(string) config("content_packs.default_pack_id", "");
if ($root === "" || $region === "" || $locale === "" || $dir === "" || $packId === "") {
  fwrite(STDERR, "missing config for pack path\n"); exit(1);
}
$paths = glob($root . "/*/" . $region . "/" . $locale . "/" . $dir);
if (!$paths) { fwrite(STDERR, "pack_dir_not_found\n"); exit(1); }
$matched = "";
foreach ($paths as $path) {
  $manifest = $path . "/manifest.json";
  if (!is_file($manifest)) { continue; }
  $j = json_decode(file_get_contents($manifest), true);
  if (!is_array($j)) { continue; }
  if (($j["pack_id"] ?? "") === $packId) { $matched = $path; break; }
}
if ($matched === "") { fwrite(STDERR, "pack_dir_manifest_pack_id_mismatch\n"); exit(1); }
$rel = $matched;
$prefix = rtrim($root, DIRECTORY_SEPARATOR) . DIRECTORY_SEPARATOR;
if (str_starts_with($rel, $prefix)) { $rel = substr($rel, strlen($prefix)); }
echo $rel;
')"

if [[ -z "${PACK_DIR_REL}" ]]; then
  echo "pack_dir_resolve_failed" >&2
  exit 1
fi

echo "${PACK_DIR_REL}" > "${PACK_DIR_FILE}"
PACK_DIR_ABS="$(php -r 'require "vendor/autoload.php"; $app=require "bootstrap/app.php"; $app->make(Illuminate\Contracts\Console\Kernel::class)->bootstrap(); $root=(string) config("content_packs.root", ""); echo rtrim($root, DIRECTORY_SEPARATOR) . DIRECTORY_SEPARATOR . $argv[1];' "${PACK_DIR_REL}")"

php -r '$p=$argv[1]; $j=json_decode(file_get_contents($p), true); if(!is_array($j)){fwrite(STDERR,"json parse failed: $p\n"); exit(1);} echo "OK $p\n";' "${PACK_DIR_ABS}/version.json" > "${ART_DIR}/pack_version_parse.txt"
php -r '$p=$argv[1]; $j=json_decode(file_get_contents($p), true); if(!is_array($j)){fwrite(STDERR,"json parse failed: $p\n"); exit(1);} echo "OK $p\n";' "${PACK_DIR_ABS}/questions.json" > "${ART_DIR}/pack_questions_parse.txt"

# Start server
: > "${ART_DIR}/server.log"
php artisan serve --host=127.0.0.1 --port="${SERVE_PORT}" >> "${ART_DIR}/server.log" 2>&1 &
SERVER_PID=$!
echo "${SERVER_PID}" > "${ART_DIR}/server.pid"

# Health (fallback to internal dispatch if server cannot bind)
SERVER_OK=0
set +e
for i in $(seq 1 60); do
  curl -fsS "${API_BASE}/up" >/dev/null 2>&1 && SERVER_OK=1 && break
  sleep 0.2
done
set -e
if [[ "${SERVER_OK}" == "1" ]]; then
  curl -fsS "${API_BASE}/up" > "${ART_DIR}/health.json"
else
  echo '{"ok":false,"error":"serve_failed","fallback":"internal"}' > "${ART_DIR}/health.json"
fi

internal_http() {
  local method="$1"
  local uri="$2"
  local body="$3"
  local out="$4"
  local status_file="${out}.status"
  REQ_METHOD="${method}" REQ_URI="${uri}" REQ_BODY="${body}" REQ_OUT="${out}" REQ_STATUS="${status_file}" php -r '
require "vendor/autoload.php";
$app=require "bootstrap/app.php";
$kernel=$app->make(Illuminate\Contracts\Http\Kernel::class);
$method=getenv("REQ_METHOD") ?: "GET";
$uri=getenv("REQ_URI") ?: "/";
$body=getenv("REQ_BODY") ?: "";
$server=["CONTENT_TYPE"=>"application/json","HTTP_ACCEPT"=>"application/json"];
$request=Illuminate\Http\Request::create($uri, $method, [], [], [], $server, $body);
$response=$kernel->handle($request);
file_put_contents(getenv("REQ_OUT"), (string) $response->getContent());
file_put_contents(getenv("REQ_STATUS"), (string) $response->getStatusCode());
$kernel->terminate($request, $response);
';
  if [[ -f "${status_file}" ]]; then
    cat "${status_file}"
    rm -f "${status_file}"
  else
    echo "500"
  fi
}

fetch_json() {
  local method="$1"
  local uri="$2"
  local out="$3"
  local body="${4:-}"
  if [[ "${SERVER_OK}" == "1" ]]; then
    if [[ "${method}" == "GET" ]]; then
      curl -sS -o "${out}" -w "%{http_code}" "${API_BASE}${uri}" || true
    else
      curl -sS -o "${out}" -w "%{http_code}" -X "${method}" \
        -H "Content-Type: application/json" -H "Accept: application/json" \
        -d "${body}" "${API_BASE}${uri}" || true
    fi
  else
    internal_http "${method}" "${uri}" "${body}" "${out}"
  fi
}

# Questions -> dynamic answers
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

# Attempt start
ATTEMPT_START_JSON="${ART_DIR}/attempt_start.json"
http_code="$(fetch_json POST "/api/v0.3/attempts/start" "${ATTEMPT_START_JSON}" '{"scale_code":"MBTI"}')"
if [[ "${http_code}" != "200" ]]; then
  echo "attempt_start_failed http=${http_code}" >&2
  cat "${ATTEMPT_START_JSON}" >&2 || true
  exit 1
fi

ATTEMPT_ID="$(php -r '$j=json_decode(file_get_contents($argv[1]), true); echo $j["attempt_id"] ?? "";' "${ATTEMPT_START_JSON}")"
if [[ -z "${ATTEMPT_ID}" ]]; then
  echo "missing_attempt_id" >&2
  exit 1
fi

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
  echo "submit_not_ok" >&2
  exit 1
fi

REPORT_JSON="${ART_DIR}/report.json"
http_code="$(fetch_json GET "/api/v0.3/attempts/${ATTEMPT_ID}/report" "${REPORT_JSON}")"
if [[ "${http_code}" != "200" ]]; then
  echo "report_failed http=${http_code}" >&2
  cat "${REPORT_JSON}" >&2 || true
  exit 1
fi

php -r '$j=json_decode(file_get_contents($argv[1]), true); if (!is_array($j)) { fwrite(STDERR, "report json invalid\n"); exit(1);} echo "ok\n";' "${REPORT_JSON}" >/dev/null

# Cleanup
kill "${SERVER_PID}" >/dev/null 2>&1 || true
sleep 0.2
if lsof -nP -iTCP:${SERVE_PORT} -sTCP:LISTEN >/dev/null 2>&1; then
  lsof -nP -iTCP:${SERVE_PORT} -sTCP:LISTEN || true
  echo "port_${SERVE_PORT}_still_listening" >&2
  exit 1
fi

echo "[PR27] verify complete"
