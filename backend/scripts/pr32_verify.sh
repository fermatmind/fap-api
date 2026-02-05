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

SCRIPT_DIR="$(cd "$(dirname "${BASH_SOURCE[0]}")" && pwd)"
REPO_DIR="$(cd "${SCRIPT_DIR}/../.." && pwd)"
BACKEND_DIR="${REPO_DIR}/backend"

SERVE_PORT="${SERVE_PORT:-1832}"
API_BASE="http://127.0.0.1:${SERVE_PORT}"

ART_DIR="${ART_DIR:-${BACKEND_DIR}/artifacts/pr32}"
LOG_DIR="${ART_DIR}/logs"
VERIFY_LOG="${ART_DIR}/verify.log"
mkdir -p "${ART_DIR}" "${LOG_DIR}"

exec > >(tee "${VERIFY_LOG}") 2>&1

fail() {
  echo "[pr32][fail] $*" >&2
  exit 1
}

cleanup_port() {
  local port="$1"
  local pids
  pids="$(lsof -ti tcp:"${port}" 2>/dev/null || true)"
  if [[ -n "${pids}" ]]; then
    echo "${pids}" | xargs kill -9 || true
  fi
}

wait_health() {
  local url="$1"
  local tries="${2:-80}"
  for _ in $(seq 1 "${tries}"); do
    if curl -fsS "${url}" >/dev/null 2>&1; then
      return 0
    fi
    sleep 0.25
  done
  return 1
}

echo "[pr32] api_base=${API_BASE}"

cleanup_port "${SERVE_PORT}"
cleanup_port 18000

php "${BACKEND_DIR}/artisan" serve --host=127.0.0.1 --port="${SERVE_PORT}" > "${ART_DIR}/server.log" 2>&1 &
SERVER_PID=$!
echo "${SERVER_PID}" > "${ART_DIR}/server.pid"

if ! wait_health "${API_BASE}/api/healthz"; then
  echo "[pr32] healthz failed"
  curl -sS "${API_BASE}/api/healthz" || true
  tail -n 120 "${ART_DIR}/server.log" || true
  fail "healthz failed"
fi

php -r '
require "'"${BACKEND_DIR}"'/vendor/autoload.php";
$app = require "'"${BACKEND_DIR}"'/bootstrap/app.php";
$kernel = $app->make(Illuminate\Contracts\Console\Kernel::class);
$kernel->bootstrap();
$packId = (string) config("content_packs.default_pack_id", "");
$dirVersion = (string) config("content_packs.default_dir_version", "");
echo "config.default_pack_id={$packId}\n";
echo "config.default_dir_version={$dirVersion}\n";
$path = getenv("DB_DATABASE") ?: "/tmp/pr32.sqlite";
$pdo = new PDO("sqlite:" . $path);
$row = $pdo->query("select default_pack_id, default_dir_version from scales_registry where code=\"MBTI\" and org_id=0 limit 1")->fetch(PDO::FETCH_ASSOC);
if (!$row) {
  fwrite(STDERR, "MBTI row missing\n");
  exit(2);
}
$ok = $row["default_pack_id"] === $packId && $row["default_dir_version"] === $dirVersion;
if (!$ok) {
  fwrite(STDERR, "pack config mismatch\n");
  exit(3);
}
echo "db.default_pack_id=" . $row["default_pack_id"] . "\n";
echo "db.default_dir_version=" . $row["default_dir_version"] . "\n";
$packDir = "'"${REPO_DIR}"'/content_packages/default/CN_MAINLAND/zh-CN/" . $dirVersion;
$files = ["manifest.json", "questions.json", "scoring_spec.json"];
foreach ($files as $file) {
  $full = $packDir . "/" . $file;
  if (!is_file($full)) {
    fwrite(STDERR, "missing file: {$full}\n");
    exit(4);
  }
  $raw = file_get_contents($full);
  $json = json_decode($raw, true);
  if (!is_array($json)) {
    fwrite(STDERR, "invalid json: {$full}\n");
    exit(5);
  }
}
file_put_contents("'"${ART_DIR}"'/pack_consistency.txt", implode("\n", [
  "config.default_pack_id={$packId}",
  "config.default_dir_version={$dirVersion}",
  "db.default_pack_id=" . $row["default_pack_id"],
  "db.default_dir_version=" . $row["default_dir_version"],
  "pack_dir={$packDir}",
]) . "\n");
' || fail "pack/seed/config consistency failed"

smoke_scale() {
  local scale="$1"
  local prefix="$2"

  curl -sS -H "Accept: application/json" -H "X-Region: CN_MAINLAND" -H "Accept-Language: zh-CN" \
    "${API_BASE}/api/v0.3/scales/${scale}/questions" > "${ART_DIR}/${prefix}_questions.json"

  curl -sS -X POST \
    -H "Accept: application/json" -H "Content-Type: application/json" \
    -H "X-Region: CN_MAINLAND" -H "Accept-Language: zh-CN" \
    --data "{\"scale_code\":\"${scale}\",\"anon_id\":\"pr32-${prefix}\"}" \
    "${API_BASE}/api/v0.3/attempts/start" > "${ART_DIR}/${prefix}_attempt_start.json"

  local attempt_id
  attempt_id="$(php -r '$d=json_decode(file_get_contents("'"${ART_DIR}"'/'"${prefix}"'_attempt_start.json"), true); echo $d["attempt_id"] ?? "";')"
  if [[ -z "${attempt_id}" ]]; then
    fail "${scale} attempt start failed"
  fi
  echo "${attempt_id}" > "${ART_DIR}/${prefix}_attempt_id.txt"

  php -r '
$q=json_decode(file_get_contents("'"${ART_DIR}"'/'"${prefix}"'_questions.json"), true);
$items=$q["questions"]["items"] ?? $q["items"] ?? ($q["questions"] ?? []);
$answers=[];
foreach ($items as $item) {
  if (!is_array($item)) { continue; }
  $qid=$item["question_id"] ?? "";
  if ($qid === "") { continue; }
  $opts=$item["options"] ?? [];
  $code="";
  if (is_array($opts) && isset($opts[0]) && is_array($opts[0])) {
    $code=(string) ($opts[0]["code"] ?? $opts[0]["option_code"] ?? "");
  }
  if ($code === "") { $code="A"; }
  $answers[]=["question_id"=>$qid,"code"=>$code];
}
file_put_contents("'"${ART_DIR}"'/'"${prefix}"'_answers.json", json_encode($answers, JSON_UNESCAPED_UNICODE));
' || fail "${scale} build answers failed"

  php -r '
$answers=json_decode(file_get_contents("'"${ART_DIR}"'/'"${prefix}"'_answers.json"), true);
if (!is_array($answers)) { $answers=[]; }
$payload=[
  "attempt_id"=>"'"${attempt_id}"'",
  "duration_ms"=>120000,
  "answers"=>$answers,
];
file_put_contents("'"${ART_DIR}"'/'"${prefix}"'_submit.json", json_encode($payload, JSON_UNESCAPED_UNICODE));
'

  curl -sS -X POST \
    -H "Accept: application/json" -H "Content-Type: application/json" \
    -H "X-Region: CN_MAINLAND" -H "Accept-Language: zh-CN" \
    --data-binary "@${ART_DIR}/${prefix}_submit.json" \
    "${API_BASE}/api/v0.3/attempts/submit" > "${ART_DIR}/${prefix}_submit_resp.json"

  php -r '
$d=json_decode(file_get_contents("'"${ART_DIR}"'/'"${prefix}"'_submit_resp.json"), true);
if (!is_array($d) || !($d["ok"] ?? false)) {
  fwrite(STDERR, "submit ok=false\n");
  exit(2);
}
' || fail "${scale} submit failed"
}

smoke_scale "MBTI" "mbti"
smoke_scale "BIG5" "big5"

if [[ -f "${ART_DIR}/server.pid" ]]; then
  PID="$(cat "${ART_DIR}/server.pid" || true)"
  if [[ -n "${PID}" ]] && ps -p "${PID}" >/dev/null 2>&1; then
    kill "${PID}" >/dev/null 2>&1 || true
  fi
fi

cleanup_port "${SERVE_PORT}"
cleanup_port 18000

if lsof -nP -iTCP:"${SERVE_PORT}" -sTCP:LISTEN >/dev/null 2>&1; then
  fail "port ${SERVE_PORT} still in use"
fi

echo "[pr32] verify ok"
