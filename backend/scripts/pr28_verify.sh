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

REPO_DIR="$(cd "$(dirname "${BASH_SOURCE[0]}")/../.." && pwd)"
BACKEND_DIR="${REPO_DIR}/backend"
ART_DIR="${BACKEND_DIR}/artifacts/pr28"
SERVE_PORT="${SERVE_PORT:-18028}"
API_BASE="http://127.0.0.1:${SERVE_PORT}"

mkdir -p "${ART_DIR}"

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

: > "${ART_DIR}/server.log"
php artisan serve --host=127.0.0.1 --port="${SERVE_PORT}" >> "${ART_DIR}/server.log" 2>&1 &
SERVER_PID=$!
echo "${SERVER_PID}" > "${ART_DIR}/server.pid"

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

HEALTH_OK=0
set +e
for i in $(seq 1 60); do
  curl -fsS "${API_BASE}/api/healthz" >/dev/null 2>&1 && HEALTH_OK=1 && break
  sleep 0.2
done
set -e

if [[ "${HEALTH_OK}" == "1" ]]; then
  curl -fsS "${API_BASE}/api/healthz" > "${ART_DIR}/health.json"
else
  http_code="$(internal_http GET "/api/healthz" "" "${ART_DIR}/health.json")"
  if [[ "${http_code}" != "200" ]]; then
    echo "healthz_failed" >&2
    exit 1
  fi
fi

# Config + seed consistency (config <-> scales_registry <-> pack dir)
DEFAULT_PACK_ID_FILE="${ART_DIR}/default_pack_id.txt"
DEFAULT_DIR_VERSION_FILE="${ART_DIR}/default_dir_version.txt"
SCALES_PACK_ID_FILE="${ART_DIR}/scales_registry_default_pack_id.txt"
SCALES_DIR_VERSION_FILE="${ART_DIR}/scales_registry_default_dir_version.txt"
PACK_DIR_FILE="${ART_DIR}/pack_dir.txt"

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
$root = (string) config("content_packs.root", "");
$packId = (string) config("content_packs.default_pack_id", "");
$dirVersion = (string) config("content_packs.default_dir_version", "");
$index = app(App\Services\Content\ContentPacksIndex::class);
$found = $index->find($packId, $dirVersion);
if (!($found["ok"] ?? false)) { fwrite(STDERR, "pack_not_found\n"); exit(1); }
$manifestPath = (string) ($found["item"]["manifest_path"] ?? "");
if ($manifestPath === "") { fwrite(STDERR, "manifest_path_missing\n"); exit(1); }
$baseDir = dirname($manifestPath);
$rel = $baseDir;
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

fetch_json() {
  local method="$1"
  local uri="$2"
  local out="$3"
  local body="${4:-}"
  if [[ "${HEALTH_OK}" == "1" ]]; then
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

SCALES_JSON="${ART_DIR}/scales.json"
http_code="$(fetch_json GET "/api/v0.3/scales" "${SCALES_JSON}")"
if [[ "${http_code}" != "200" ]]; then
  echo "scales_failed http=${http_code}" >&2
  cat "${SCALES_JSON}" >&2 || true
  exit 1
fi

SCALE_CODE="$(php -r '$j=json_decode(file_get_contents($argv[1]), true); $items=$j["items"] ?? []; $code=""; if (is_array($items) && count($items)>0) { $first=$items[0]; if (is_array($first)) { $code=$first["code"] ?? ""; } } echo $code;' "${SCALES_JSON}")"
if [[ -z "${SCALE_CODE}" ]]; then
  echo "scale_code_missing" >&2
  exit 1
fi

QUESTIONS_JSON="${ART_DIR}/questions.json"
http_code="$(fetch_json GET "/api/v0.3/scales/${SCALE_CODE}/questions" "${QUESTIONS_JSON}")"
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
http_code="$(fetch_json POST "/api/v0.3/attempts/start" "${ATTEMPT_START_JSON}" "{\"scale_code\":\"${SCALE_CODE}\"}")"
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
http_code="$(fetch_json POST "/api/v0.3/attempts/submit" "${SUBMIT_JSON}" "$(cat "${SUBMIT_PAYLOAD}")")"
if [[ "${http_code}" != "200" ]]; then
  echo "submit_failed http=${http_code}" >&2
  cat "${SUBMIT_JSON}" >&2 || true
  exit 1
fi

REPORT_JSON="${ART_DIR}/report.json"
http_code="$(fetch_json GET "/api/v0.3/attempts/${ATTEMPT_ID}/report" "${REPORT_JSON}")"
if [[ "${http_code}" != "200" ]]; then
  echo "report_failed http=${http_code}" >&2
  cat "${REPORT_JSON}" >&2 || true
  exit 1
fi
