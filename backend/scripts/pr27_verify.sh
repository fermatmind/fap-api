#!/usr/bin/env bash
set -euo pipefail

export CI=true
export FAP_NONINTERACTIVE=1
export COMPOSER_NO_INTERACTION=1
export GIT_TERMINAL_PROMPT=0
export NO_COLOR=1

SCRIPT_DIR="$(cd "$(dirname "${BASH_SOURCE[0]}")" && pwd)"
BACKEND_DIR="$(cd "${SCRIPT_DIR}/.." && pwd)"
REPO_DIR="$(cd "${BACKEND_DIR}/.." && pwd)"

SERVE_PORT="${SERVE_PORT:-1827}"
ART_DIR="${ART_DIR:-${BACKEND_DIR}/artifacts/pr27}"
LOG_DIR="${ART_DIR}/logs"
API_BASE="http://127.0.0.1:${SERVE_PORT}"

export FAP_PACKS_DRIVER=local
export FAP_PACKS_ROOT="${REPO_DIR}/content_packages"
export FAP_DEFAULT_REGION=CN_MAINLAND
export FAP_DEFAULT_LOCALE=zh-CN
export FAP_DEFAULT_PACK_ID="${FAP_DEFAULT_PACK_ID:-MBTI.cn-mainland.zh-CN.v0.2.2}"
export FAP_DEFAULT_DIR_VERSION="${FAP_DEFAULT_DIR_VERSION:-MBTI-CN-v0.2.2}"

mkdir -p "${LOG_DIR}"

SERVER_LOG="${LOG_DIR}/server.log"
HEALTH_JSON="${ART_DIR}/health.json"
QUESTIONS_JSON="${ART_DIR}/questions.json"
ANSWERS_JSON="${ART_DIR}/answers_payload.json"
ATTEMPT_JSON="${ART_DIR}/attempt_submit.json"
RESULT_JSON="${ART_DIR}/result.json"
CONFIG_LOG="${ART_DIR}/config_check.txt"
PACK_LOG="${ART_DIR}/pack_validate.log"

cleanup_port() {
  local p="$1"
  lsof -ti tcp:"${p}" | xargs -r kill -9 || true
}

cleanup_port "${SERVE_PORT}"
cleanup_port 18000

cleanup() {
  if [[ -n "${SERVER_PID:-}" ]]; then
    kill "${SERVER_PID}" >/dev/null 2>&1 || true
  fi
  cleanup_port "${SERVE_PORT}"
}

trap cleanup EXIT

cd "${BACKEND_DIR}"

if [[ ! -f ".env" ]]; then
  cp -a .env.example .env
fi

# start server
php artisan serve --host=127.0.0.1 --port="${SERVE_PORT}" >"${SERVER_LOG}" 2>&1 &
SERVER_PID=$!

echo "${SERVER_PID}" > "${ART_DIR}/server.pid"

# health check
ok=0
for i in $(seq 1 30); do
  if curl -s "${API_BASE}/api/v0.2/healthz" >"${HEALTH_JSON}" 2>/dev/null; then
    ok=1
    break
  fi
  sleep 1
done

if [[ "${ok}" != "1" ]]; then
  echo "[FAIL] health check failed" >&2
  if [[ -f "${HEALTH_JSON}" ]]; then
    cat "${HEALTH_JSON}" >&2 || true
  fi
  tail -n 50 "${SERVER_LOG}" >&2 || true
  cleanup_port "${SERVE_PORT}"
  exit 1
fi

# config + scales_registry consistency
php -r 'require "vendor/autoload.php"; $app=require "bootstrap/app.php"; $app->make(Illuminate\Contracts\Console\Kernel::class)->bootstrap();
$cfgPack=config("content_packs.default_pack_id");
$cfgDir=config("content_packs.default_dir_version");
$row=Illuminate\Support\Facades\DB::table("scales_registry")->where("org_id",0)->where("code","MBTI")->first();
$regPack=$row->default_pack_id ?? ""; $regDir=$row->default_dir_version ?? "";
file_put_contents("'"${CONFIG_LOG}"'", "config_pack_id={$cfgPack}\nconfig_dir_version={$cfgDir}\nregistry_pack_id={$regPack}\nregistry_dir_version={$regDir}\n");
if ($cfgPack!==$regPack || $cfgDir!==$regDir) { fwrite(STDERR, "pack/config mismatch\n"); exit(2);}';

# fetch questions
curl -sS "${API_BASE}/api/v0.2/scales/MBTI/questions?region=CN_MAINLAND&locale=zh-CN" > "${QUESTIONS_JSON}"

# build answers payload
php -r '$q=json_decode(file_get_contents($argv[1]), true); $items=$q["items"] ?? $q; if (!is_array($items)) {fwrite(STDERR,"invalid questions\n"); exit(2);} $answers=[]; foreach ($items as $it){ $qid=$it["question_id"] ?? null; if(!$qid) continue; $opts=$it["options"] ?? []; $code=$opts[0]["code"] ?? "A"; $answers[]=["question_id"=>$qid,"code"=>$code]; } if (count($answers)<=0){fwrite(STDERR,"no answers\n"); exit(3);} $payload=["anon_id"=>"pr27-verify","scale_code"=>"MBTI","scale_version"=>"v0.2.2","region"=>"CN_MAINLAND","locale"=>"zh-CN","client_platform"=>"pr27_verify","answers"=>$answers]; echo json_encode($payload, JSON_UNESCAPED_UNICODE);' "${QUESTIONS_JSON}" > "${ANSWERS_JSON}"

# submit attempt
curl -sS -X POST "${API_BASE}/api/v0.2/attempts" -H "Content-Type: application/json" -d @"${ANSWERS_JSON}" > "${ATTEMPT_JSON}"

ATTEMPT_ID=$(php -r '$d=json_decode(file_get_contents($argv[1]), true); if (!is_array($d) || !($d["ok"] ?? false)) {fwrite(STDERR,"submit failed\n"); exit(2);} echo (string)($d["attempt_id"] ?? "");' "${ATTEMPT_JSON}")
if [[ -z "${ATTEMPT_ID}" ]]; then
  echo "[FAIL] attempt_id missing" >&2
  tail -n 50 "${SERVER_LOG}" >&2 || true
  cleanup_port "${SERVE_PORT}"
  exit 1
fi

echo "${ATTEMPT_ID}" > "${ART_DIR}/attempt_id.txt"

RESULT_ID=$(php -r '$d=json_decode(file_get_contents($argv[1]), true); echo (string)($d["result_id"] ?? "");' "${ATTEMPT_JSON}")
if [[ -n "${RESULT_ID}" ]]; then
  echo "${RESULT_ID}" > "${ART_DIR}/result_id.txt"
fi

# fetch result
curl -sS "${API_BASE}/api/v0.2/attempts/${ATTEMPT_ID}/result" > "${RESULT_JSON}"

php -r '$d=json_decode(file_get_contents($argv[1]), true); if (!is_array($d) || !($d["ok"] ?? false)) {fwrite(STDERR,"result failed\n"); exit(2);} $type=$d["type_code"] ?? ""; if ($type === "") {fwrite(STDERR,"type_code missing\n"); exit(3);} $scores=$d["scores_pct"] ?? null; if (!is_array($scores)) {fwrite(STDERR,"scores_pct missing\n"); exit(4);} foreach (["EI","SN","TF","JP","AT"] as $dim){ if (!array_key_exists($dim, $scores)) {fwrite(STDERR,"scores_pct missing {$dim}\n"); exit(5);} } echo $type;' "${RESULT_JSON}" > "${ART_DIR}/type_code.txt"

# pack validation
php "${BACKEND_DIR}/scripts/validate_mbti_pack_v022.php" "${REPO_DIR}/content_packages/default/CN_MAINLAND/zh-CN/MBTI-CN-v0.2.2" > "${PACK_LOG}"

# shutdown
kill "${SERVER_PID}" || true
sleep 1
cleanup_port "${SERVE_PORT}"
