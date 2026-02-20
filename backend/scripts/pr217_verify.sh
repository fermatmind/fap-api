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

SERVE_PORT="${SERVE_PORT:-18217}"
ART_DIR="${ART_DIR:-${BACKEND_DIR}/artifacts/pr217}"
mkdir -p "${ART_DIR}"
API="http://127.0.0.1:${SERVE_PORT}"

redact() {
  sed -E \
    -e 's#(/Users|/home)/[^ ]+#/REDACTED#g' \
    -e 's#Authorization: Bearer [^[:space:]]+#Authorization: Bearer REDACTED#g' \
    -e 's#(FAP_ADMIN_TOKEN|DB_PASSWORD|password)=([^[:space:]]+)#\1=REDACTED#g'
}

fail() {
  local code=$?
  set +e
  echo "[FAIL] pr217_verify failed"
  if [ -f "${ART_DIR}/server.log" ]; then
    echo "--- server.log (tail) ---"
    tail -n 200 "${ART_DIR}/server.log" | redact
  fi
  if [ -f "${BACKEND_DIR}/storage/logs/laravel.log" ]; then
    echo "--- laravel.log (tail) ---"
    tail -n 200 "${BACKEND_DIR}/storage/logs/laravel.log" | redact
  fi
  exit "${code}"
}
trap fail ERR

cd "${BACKEND_DIR}"

# 1) fetch questions (v0.3)
curl -fsS -H "Accept: application/json" -H "X-Region: CN_MAINLAND" -H "Accept-Language: zh-CN" \
  "${API}/api/v0.3/scales/MBTI/questions" > "${ART_DIR}/questions.json"

# 2) create attempt
curl -fsS -X POST \
  -H "Accept: application/json" -H "Content-Type: application/json" \
  -H "X-Region: CN_MAINLAND" -H "Accept-Language: zh-CN" \
  --data '{"scale_code":"MBTI","anon_id":"pr217-cli"}' \
  "${API}/api/v0.3/attempts/start" > "${ART_DIR}/attempt_start.json"

ATTEMPT_ID="$(php -r ' $d=json_decode(file_get_contents("'"${ART_DIR}/attempt_start.json"'"),true); echo $d["attempt_id"]??""; ')"
test -n "${ATTEMPT_ID}"
echo "${ATTEMPT_ID}" > "${ART_DIR}/attempt_id.txt"

# 3) build answers dynamically
php -r '
$q=json_decode(file_get_contents("'"${ART_DIR}/questions.json"'"),true);
$items=$q["questions"]["items"] ?? $q["items"] ?? ($q["questions"] ?? []);
$ans=[];
foreach($items as $it){
  $qid=$it["question_id"] ?? "";
  if($qid==="") continue;
  $ans[$qid] = ["question_id"=>$qid,"option_code"=>"C"];
}
file_put_contents("'"${ART_DIR}/submit.json"'", json_encode([
  "attempt_id"=>"'"${ATTEMPT_ID}"'",
  "duration_ms"=>45000,
  "answers"=>$ans,
], JSON_UNESCAPED_UNICODE));
'

# 4) submit
curl -fsS -X POST \
  -H "Accept: application/json" -H "Content-Type: application/json" \
  -H "X-Region: CN_MAINLAND" -H "Accept-Language: zh-CN" \
  --data-binary @"${ART_DIR}/submit.json" \
  "${API}/api/v0.3/attempts/submit" > "${ART_DIR}/submit_resp.json"

# 5) share (v0.2)
curl -fsS -H "Accept: application/json" -H "X-Region: CN_MAINLAND" -H "Accept-Language: zh-CN" \
  "${API}/api/v0.3/attempts/${ATTEMPT_ID}/share" > "${ART_DIR}/share.json"

# 6) assert response (no jq)
php -r '
$d=json_decode(file_get_contents("'"${ART_DIR}/share.json"'"),true);
if(!is_array($d) || !($d["ok"]??false)) { fwrite(STDERR,"share ok=false\n"); exit(2); }
$shareId = $d["share_id"] ?? "";
$typeName = $d["type_name"] ?? "";
if(!is_string($shareId) || $shareId==="") { fwrite(STDERR,"share_id empty\n"); exit(3); }
if(!is_string($typeName) || trim($typeName)==="") { fwrite(STDERR,"type_name empty\n"); exit(4); }
echo "share_id=".$shareId.PHP_EOL;
echo "type_name=".$typeName.PHP_EOL;
' > "${ART_DIR}/assert_share.txt"

SHARE_ID="$(grep -E '^share_id=' "${ART_DIR}/assert_share.txt" | sed -E 's/^share_id=//')"
test -n "${SHARE_ID}"

# 7) verify shares table persisted (tinker non-interactive)
HOME=/tmp XDG_CONFIG_HOME=/tmp ATTEMPT_ID="${ATTEMPT_ID}" SHARE_ID="${SHARE_ID}" \
php artisan tinker --execute='
use Illuminate\Support\Facades\DB;

$attemptId=getenv("ATTEMPT_ID");
$shareId=getenv("SHARE_ID");

$cnt=DB::table("shares")->count();
$row=DB::table("shares")->where("attempt_id",$attemptId)->first();

dump([
  "shares_count"=>$cnt,
  "share_row"=>$row,
  "expect_attempt_id"=>$attemptId,
  "expect_share_id"=>$shareId,
]);

if ($cnt <= 0) { throw new Exception("shares_count <= 0"); }
if (!$row) { throw new Exception("share_row missing"); }
if ((string)$row->attempt_id !== (string)$attemptId) { throw new Exception("attempt_id mismatch"); }
if ((string)$row->id !== (string)$shareId) { throw new Exception("share_id(id) mismatch"); }
' > "${ART_DIR}/tinker_shares.txt" 2>&1

echo "[OK] pr217_verify passed"
