#!/usr/bin/env bash
set -euo pipefail

export CI=true
export FAP_NONINTERACTIVE=1
export COMPOSER_NO_INTERACTION=1
export GIT_TERMINAL_PROMPT=0
export NO_COLOR=1

export FAP_DEFAULT_PACK_ID="${FAP_DEFAULT_PACK_ID:-MBTI.cn-mainland.zh-CN.v0.2.1-TEST}"
export FAP_DEFAULT_DIR_VERSION="${FAP_DEFAULT_DIR_VERSION:-MBTI-CN-v0.2.1-TEST}"
export FAP_DEFAULT_REGION="${FAP_DEFAULT_REGION:-CN_MAINLAND}"
export FAP_DEFAULT_LOCALE="${FAP_DEFAULT_LOCALE:-zh-CN}"

export DB_CONNECTION="${DB_CONNECTION:-sqlite}"
export DB_DATABASE="${DB_DATABASE:-/tmp/pr25.sqlite}"

SERVE_PORT="${SERVE_PORT:-1825}"
API_BASE="http://127.0.0.1:${SERVE_PORT}"

SCRIPT_DIR="$(cd "$(dirname "${BASH_SOURCE[0]}")" && pwd)"
ROOT_DIR="$(cd "${SCRIPT_DIR}/../.." && pwd)"
BACKEND_DIR="${ROOT_DIR}/backend"
ART_DIR="${BACKEND_DIR}/artifacts/pr25"

mkdir -p "${ART_DIR}"

VERIFY_LOG="${ART_DIR}/verify.log"
SERVER_LOG="${ART_DIR}/server.log"
SERVER_PID_FILE="${ART_DIR}/server.pid"

exec > >(tee "${VERIFY_LOG}") 2>&1

echo "[pr25] api_base=${API_BASE}"

timestamp() {
  date +'%Y-%m-%d %H:%M:%S'
}

cleanup_port() {
  local port="$1"
  local pids
  pids="$(lsof -ti tcp:"${port}" 2>/dev/null || true)"
  if [[ -n "${pids}" ]]; then
    kill -9 ${pids} || true
  fi
}

cleanup_port "${SERVE_PORT}"
cleanup_port 18000

php "${BACKEND_DIR}/artisan" fap:scales:seed-default
php "${BACKEND_DIR}/artisan" fap:scales:sync-slugs

php -r '
require "'"${BACKEND_DIR}"'/vendor/autoload.php";
$app = require "'"${BACKEND_DIR}"'/bootstrap/app.php";
$app->make(Illuminate\Contracts\Console\Kernel::class)->bootstrap();
$packId = (string) config("content_packs.default_pack_id", "");
$dirVersion = (string) config("content_packs.default_dir_version", "");
echo "[pr25] default_pack_id={$packId}\n";
echo "[pr25] default_dir_version={$dirVersion}\n";
$scale = DB::table("scales_registry")->where("org_id", 0)->where("code", "MBTI")->first();
$scalePack = $scale->default_pack_id ?? "";
$scaleDir = $scale->default_dir_version ?? "";
if ($scalePack !== $packId) { fwrite(STDERR, "[pr25][fail] scales_registry.default_pack_id mismatch\n"); exit(1); }
if ($scaleDir !== $dirVersion) { fwrite(STDERR, "[pr25][fail] scales_registry.default_dir_version mismatch\n"); exit(1); }
$index = app(App\Services\Content\ContentPacksIndex::class);
$found = $index->find($packId, $dirVersion);
if (!($found["ok"] ?? false)) { fwrite(STDERR, "[pr25][fail] pack not found\n"); exit(1); }
$item = $found["item"] ?? [];
$questionsPath = (string) ($item["questions_path"] ?? "");
if ($questionsPath === "" || !file_exists($questionsPath)) { fwrite(STDERR, "[pr25][fail] questions.json missing\n"); exit(1); }
$versionPath = dirname($questionsPath) . "/version.json";
if (!file_exists($versionPath)) { fwrite(STDERR, "[pr25][fail] version.json missing\n"); exit(1); }
$questions = json_decode(file_get_contents($questionsPath), true);
$version = json_decode(file_get_contents($versionPath), true);
if (!is_array($questions) || !is_array($version)) { fwrite(STDERR, "[pr25][fail] questions/version invalid\n"); exit(1); }
echo "[pr25] pack_path=" . (string) ($item["pack_path"] ?? "") . "\n";
'

php "${BACKEND_DIR}/artisan" serve --host=127.0.0.1 --port="${SERVE_PORT}" >"${SERVER_LOG}" 2>&1 &
SERVER_PID=$!
echo "${SERVER_PID}" > "${SERVER_PID_FILE}"

echo "[pr25] server_pid=${SERVER_PID}"

HEALTH_OK=0
for _ in {1..30}; do
  if curl -sS "${API_BASE}/api/v0.2/health" >/dev/null 2>&1; then
    HEALTH_OK=1
    break
  fi
  sleep 0.5
done

if [[ "${HEALTH_OK}" -ne 1 ]]; then
  echo "[pr25][fail] health check failed"
  curl -sS "${API_BASE}/api/v0.2/health" || true
  tail -n 80 "${SERVER_LOG}" || true
  exit 1
fi

echo "[pr25] seed org + members + wallet"
php -r '
$pdo = new PDO("sqlite:" . getenv("DB_DATABASE"));
$pdo->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
$now = date("Y-m-d H:i:s");
$orgId = 2500;
$adminId = 9001;
$memberId = 9002;
$pdo->exec("INSERT INTO organizations (id, name, owner_user_id, created_at, updated_at) VALUES (" . (int)$orgId . ", " . $pdo->quote("PR25 Org") . ", " . (int)$adminId . ", " . $pdo->quote($now) . ", " . $pdo->quote($now) . ")");
$pdo->exec("INSERT INTO organization_members (org_id, user_id, role, joined_at, created_at, updated_at) VALUES (" . (int)$orgId . ", " . (int)$adminId . ", " . $pdo->quote("admin") . ", " . $pdo->quote($now) . ", " . $pdo->quote($now) . ", " . $pdo->quote($now) . ")");
$pdo->exec("INSERT INTO organization_members (org_id, user_id, role, joined_at, created_at, updated_at) VALUES (" . (int)$orgId . ", " . (int)$memberId . ", " . $pdo->quote("member") . ", " . $pdo->quote($now) . ", " . $pdo->quote($now) . ", " . $pdo->quote($now) . ")");
$adminToken = "fm_00000000-0000-4000-8000-000000000001";
$memberToken = "fm_11111111-1111-4111-8111-111111111111";
$pdo->exec("INSERT INTO fm_tokens (token, user_id, anon_id, expires_at, created_at, updated_at) VALUES (" . $pdo->quote($adminToken) . ", " . $pdo->quote((string)$adminId) . ", " . $pdo->quote("anon_admin") . ", " . $pdo->quote(date("Y-m-d H:i:s", strtotime("+1 day"))) . ", " . $pdo->quote($now) . ", " . $pdo->quote($now) . ")");
$pdo->exec("INSERT INTO fm_tokens (token, user_id, anon_id, expires_at, created_at, updated_at) VALUES (" . $pdo->quote($memberToken) . ", " . $pdo->quote((string)$memberId) . ", " . $pdo->quote("anon_member") . ", " . $pdo->quote(date("Y-m-d H:i:s", strtotime("+1 day"))) . ", " . $pdo->quote($now) . ", " . $pdo->quote($now) . ")");
$pdo->exec("INSERT INTO benefit_wallets (org_id, benefit_code, balance, created_at, updated_at) VALUES (" . (int)$orgId . ", " . $pdo->quote("B2B_ASSESSMENT_ATTEMPT_SUBMIT") . ", 10, " . $pdo->quote($now) . ", " . $pdo->quote($now) . ")");
'

ADMIN_TOKEN="fm_00000000-0000-4000-8000-000000000001"
MEMBER_TOKEN="fm_11111111-1111-4111-8111-111111111111"
ORG_ID=2500

CREATE_JSON="${ART_DIR}/create_assessment.json"
CREATE_BODY="$(php -r 'echo json_encode(["scale_code"=>"MBTI","title"=>"PR25 Team MBTI","due_at"=>date(DATE_ATOM, strtotime("+7 days"))], JSON_UNESCAPED_UNICODE);')"

curl -sS -X POST "${API_BASE}/api/v0.4/orgs/${ORG_ID}/assessments" \
  -H "Content-Type: application/json" -H "Accept: application/json" \
  -H "Authorization: Bearer ${ADMIN_TOKEN}" \
  -d "${CREATE_BODY}" > "${CREATE_JSON}"

ASSESSMENT_ID="$(php -r '$j=json_decode(stream_get_contents(STDIN), true); echo $j["assessment"]["id"] ?? "";' < "${CREATE_JSON}")"
if [[ -z "${ASSESSMENT_ID}" ]]; then
  echo "[pr25][fail] assessment create failed"
  cat "${CREATE_JSON}"
  exit 1
fi

INVITE_JSON="${ART_DIR}/invite.json"
INVITE_BODY="$(php -r '$subjects=[]; for ($i=0;$i<10;$i++){ $subjects[]=["subject_type"=>"email","subject_value"=>"member{$i}@example.com"]; } echo json_encode(["subjects"=>$subjects], JSON_UNESCAPED_UNICODE);')"

curl -sS -X POST "${API_BASE}/api/v0.4/orgs/${ORG_ID}/assessments/${ASSESSMENT_ID}/invite" \
  -H "Content-Type: application/json" -H "Accept: application/json" \
  -H "Authorization: Bearer ${ADMIN_TOKEN}" \
  -d "${INVITE_BODY}" > "${INVITE_JSON}"

php -r '$j=json_decode(stream_get_contents(STDIN), true); $inv=$j["invites"] ?? []; $tokens=[]; foreach ($inv as $it){ $tokens[]=$it["invite_token"] ?? ""; } echo json_encode($tokens, JSON_UNESCAPED_UNICODE);' < "${INVITE_JSON}" > "${ART_DIR}/invite_tokens.json"

if [[ ! -s "${ART_DIR}/invite_tokens.json" ]]; then
  echo "[pr25][fail] invite tokens missing"
  cat "${INVITE_JSON}"
  exit 1
fi

QUESTIONS_JSON="${ART_DIR}/questions.json"
curl -sS "${API_BASE}/api/v0.3/scales/MBTI/questions" \
  -H "Accept: application/json" \
  -H "Authorization: Bearer ${ADMIN_TOKEN}" \
  -H "X-Org-Id: ${ORG_ID}" > "${QUESTIONS_JSON}"

ANSWERS_JSON="${ART_DIR}/answers.json"
php -r '
$j=json_decode(stream_get_contents(STDIN), true);
$items=$j["questions"]["items"] ?? [];
$answers=[];
foreach ($items as $item) {
  if (!is_array($item)) continue;
  $qid=$item["question_id"] ?? "";
  $opts=$item["options"] ?? [];
  if ($qid === "" || !is_array($opts) || count($opts) === 0) continue;
  $code=$opts[0]["code"] ?? "";
  if ($code === "") { $code="A"; }
  $answers[]=["question_id"=>$qid, "code"=>$code];
}
echo json_encode($answers, JSON_UNESCAPED_UNICODE);
' < "${QUESTIONS_JSON}" > "${ANSWERS_JSON}"

if [[ ! -s "${ANSWERS_JSON}" ]]; then
  echo "[pr25][fail] answers json empty"
  exit 1
fi

for i in 0 1 2; do
  START_JSON="${ART_DIR}/attempt_start_${i}.json"
  START_BODY='{"scale_code":"MBTI"}'
  curl -sS -X POST "${API_BASE}/api/v0.3/attempts/start" \
    -H "Content-Type: application/json" -H "Accept: application/json" \
    -H "Authorization: Bearer ${ADMIN_TOKEN}" \
    -H "X-Org-Id: ${ORG_ID}" \
    -d "${START_BODY}" > "${START_JSON}"

  ATTEMPT_ID="$(php -r '$j=json_decode(stream_get_contents(STDIN), true); echo $j["attempt_id"] ?? "";' < "${START_JSON}")"
  if [[ -z "${ATTEMPT_ID}" ]]; then
    echo "[pr25][fail] attempt start failed"
    cat "${START_JSON}"
    exit 1
  fi

  INVITE_TOKEN="$(php -r '$list=json_decode(stream_get_contents(STDIN), true); echo $list['"${i}"'] ?? "";' < "${ART_DIR}/invite_tokens.json")"
  SUBMIT_JSON="${ART_DIR}/attempt_submit_${i}.json"
  SUBMIT_BODY="$(INVITE_TOKEN="${INVITE_TOKEN}" ATTEMPT_ID="${ATTEMPT_ID}" ANSWERS_PATH="${ANSWERS_JSON}" php -r '$answers=json_decode(file_get_contents(getenv("ANSWERS_PATH")), true); $token=getenv("INVITE_TOKEN"); $payload=["attempt_id"=>getenv("ATTEMPT_ID"),"answers"=>$answers,"duration_ms"=>120000,"invite_token"=>$token]; echo json_encode($payload, JSON_UNESCAPED_UNICODE);')"

  curl -sS -X POST "${API_BASE}/api/v0.3/attempts/submit" \
    -H "Content-Type: application/json" -H "Accept: application/json" \
    -H "Authorization: Bearer ${ADMIN_TOKEN}" \
    -H "X-Org-Id: ${ORG_ID}" \
    -d "${SUBMIT_BODY}" > "${SUBMIT_JSON}"

  OK_FLAG="$(php -r '$j=json_decode(stream_get_contents(STDIN), true); echo ($j["ok"] ?? false) ? "ok" : "";' < "${SUBMIT_JSON}")"
  if [[ "${OK_FLAG}" != "ok" ]]; then
    echo "[pr25][fail] attempt submit failed"
    cat "${SUBMIT_JSON}"
    exit 1
  fi

  BALANCE="$(php -r '$db=new PDO("sqlite:" . getenv("DB_DATABASE")); $stmt=$db->prepare("select balance from benefit_wallets where org_id=? and benefit_code=?"); $stmt->execute([2500, "B2B_ASSESSMENT_ATTEMPT_SUBMIT"]); $row=$stmt->fetch(PDO::FETCH_ASSOC); echo $row["balance"] ?? "";')"
  EXPECTED=$((10 - i - 1))
  if [[ "${BALANCE}" != "${EXPECTED}" ]]; then
    echo "[pr25][fail] credits balance expected ${EXPECTED}, got ${BALANCE}"
    exit 1
  fi
  echo "[pr25] credits_balance_after_${i}=${BALANCE}"

done

PROGRESS_JSON="${ART_DIR}/progress.json"
curl -sS "${API_BASE}/api/v0.4/orgs/${ORG_ID}/assessments/${ASSESSMENT_ID}/progress" \
  -H "Accept: application/json" \
  -H "Authorization: Bearer ${ADMIN_TOKEN}" > "${PROGRESS_JSON}"

php -r '$j=json_decode(stream_get_contents(STDIN), true); $c=$j["completed"] ?? null; $t=$j["total"] ?? null; $p=$j["pending"] ?? null; if ($c!==3 || $t!==10 || $p!==7) { fwrite(STDERR, "[pr25][fail] progress counts mismatch\n"); exit(1);} echo "[pr25] progress ok\n";' < "${PROGRESS_JSON}"

SUMMARY_JSON="${ART_DIR}/summary.json"
curl -sS "${API_BASE}/api/v0.4/orgs/${ORG_ID}/assessments/${ASSESSMENT_ID}/summary" \
  -H "Accept: application/json" \
  -H "Authorization: Bearer ${ADMIN_TOKEN}" > "${SUMMARY_JSON}"

php -r '$j=json_decode(stream_get_contents(STDIN), true); $s=$j["summary"] ?? []; $need=["completion_rate","due_at","window","score_distribution","dimension_means"]; foreach ($need as $k){ if (!array_key_exists($k, $s)){ fwrite(STDERR, "[pr25][fail] summary missing {$k}\n"); exit(1);} } echo "[pr25] summary ok\n";' < "${SUMMARY_JSON}"

RBAC_PROGRESS_JSON="${ART_DIR}/rbac_progress.json"
RBAC_STATUS="$(curl -sS -o "${RBAC_PROGRESS_JSON}" -w "%{http_code}" "${API_BASE}/api/v0.4/orgs/${ORG_ID}/assessments/${ASSESSMENT_ID}/progress" \
  -H "Accept: application/json" \
  -H "Authorization: Bearer ${MEMBER_TOKEN}")"
if [[ "${RBAC_STATUS}" != "404" ]]; then
  echo "[pr25][fail] member progress should be 404"
  cat "${RBAC_PROGRESS_JSON}"
  exit 1
fi

RBAC_SUMMARY_JSON="${ART_DIR}/rbac_summary.json"
RBAC_SUMMARY_STATUS="$(curl -sS -o "${RBAC_SUMMARY_JSON}" -w "%{http_code}" "${API_BASE}/api/v0.4/orgs/${ORG_ID}/assessments/${ASSESSMENT_ID}/summary" \
  -H "Accept: application/json" \
  -H "Authorization: Bearer ${MEMBER_TOKEN}")"
if [[ "${RBAC_SUMMARY_STATUS}" != "404" ]]; then
  echo "[pr25][fail] member summary should be 404"
  cat "${RBAC_SUMMARY_JSON}"
  exit 1
fi

php -r '$db=new PDO("sqlite:" . getenv("DB_DATABASE")); $db->exec("UPDATE benefit_wallets SET balance=0, updated_at=strftime(\"%Y-%m-%d %H:%M:%S\", \"now\") WHERE org_id=2500 AND benefit_code=\"B2B_ASSESSMENT_ATTEMPT_SUBMIT\"");'

START_JSON="${ART_DIR}/attempt_start_insufficient.json"
curl -sS -X POST "${API_BASE}/api/v0.3/attempts/start" \
  -H "Content-Type: application/json" -H "Accept: application/json" \
  -H "Authorization: Bearer ${ADMIN_TOKEN}" \
  -H "X-Org-Id: ${ORG_ID}" \
  -d '{"scale_code":"MBTI"}' > "${START_JSON}"
ATTEMPT_ID="$(php -r '$j=json_decode(stream_get_contents(STDIN), true); echo $j["attempt_id"] ?? "";' < "${START_JSON}")"
INVITE_TOKEN="$(php -r '$list=json_decode(stream_get_contents(STDIN), true); echo $list[3] ?? "";' < "${ART_DIR}/invite_tokens.json")"

SUBMIT_JSON="${ART_DIR}/attempt_submit_insufficient.json"
SUBMIT_BODY="$(ATTEMPT_ID="${ATTEMPT_ID}" INVITE_TOKEN="${INVITE_TOKEN}" ANSWERS_PATH="${ANSWERS_JSON}" php -r '$answers=json_decode(file_get_contents(getenv("ANSWERS_PATH")), true); $payload=["attempt_id"=>getenv("ATTEMPT_ID"),"answers"=>$answers,"duration_ms"=>120000,"invite_token"=>getenv("INVITE_TOKEN")]; echo json_encode($payload, JSON_UNESCAPED_UNICODE);')"

SUBMIT_STATUS="$(curl -sS -o "${SUBMIT_JSON}" -w "%{http_code}" -X POST "${API_BASE}/api/v0.3/attempts/submit" \
  -H "Content-Type: application/json" -H "Accept: application/json" \
  -H "Authorization: Bearer ${ADMIN_TOKEN}" \
  -H "X-Org-Id: ${ORG_ID}" \
  -d "${SUBMIT_BODY}")"

if [[ "${SUBMIT_STATUS}" != "402" ]]; then
  echo "[pr25][fail] insufficient credits status mismatch"
  cat "${SUBMIT_JSON}"
  exit 1
fi

php -r '$j=json_decode(stream_get_contents(STDIN), true); $code=$j["error"]["code"] ?? ""; if ($code !== "CREDITS_INSUFFICIENT") { fwrite(STDERR, "[pr25][fail] insufficient code mismatch\n"); exit(1);} echo "[pr25] insufficient ok\n";' < "${SUBMIT_JSON}"

if [[ -f "${SERVER_PID_FILE}" ]]; then
  SERVER_PID="$(cat "${SERVER_PID_FILE}")"
  if [[ -n "${SERVER_PID}" ]] && ps -p "${SERVER_PID}" >/dev/null 2>&1; then
    kill "${SERVER_PID}" >/dev/null 2>&1 || true
  fi
fi

cleanup_port "${SERVE_PORT}"
cleanup_port 18000

if lsof -nP -iTCP:"${SERVE_PORT}" -sTCP:LISTEN >/dev/null 2>&1; then
  echo "[pr25][fail] port ${SERVE_PORT} still in use"
  exit 1
fi

echo "[pr25] verify ok"
