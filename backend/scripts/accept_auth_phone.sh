#!/usr/bin/env bash
set -euo pipefail

BACKEND_DIR="$(cd "$(dirname "${BASH_SOURCE[0]}")/.." && pwd)"
REPO_DIR="$(cd "${BACKEND_DIR}/.." && pwd)"

API="${API:-http://127.0.0.1:1827}"
SQLITE_DB="${SQLITE_DB:-${BACKEND_DIR}/database/database.sqlite}"

PHONE="${PHONE:-+8613800138000}"
SCENE="${SCENE:-login}"

# 每次验收用一个固定但不容易撞的 anon_id
ANON_ID="${ANON_ID:-accept_phone_$(date +%s)}"

export PHONE
export SCENE

echo "[ACCEPT_PHONE] repo=${REPO_DIR}"
echo "[ACCEPT_PHONE] backend=${BACKEND_DIR}"
echo "[ACCEPT_PHONE] API=${API}"
echo "[ACCEPT_PHONE] SQLITE_DB=${SQLITE_DB}"
echo "[ACCEPT_PHONE] PHONE=${PHONE} SCENE=${SCENE} ANON_ID=${ANON_ID}"

# 0) health
curl -sS "${API}/api/v0.2/health" >/dev/null

# 1) 取最新 attempt 并绑定 anon_id（仅在 sqlite 且 attempts 表存在时做；否则跳过绑定）
ATTEMPT_ID=""
if [[ -f "${SQLITE_DB}" ]]; then
  export ANON_ID
  ATT_OUT="$(cd "${BACKEND_DIR}" && php artisan tinker --execute='
use Illuminate\Support\Facades\DB;

$anon = (string) getenv("ANON_ID");

try {
  $has = DB::select("SELECT name FROM sqlite_master WHERE type=\"table\" AND name=\"attempts\" LIMIT 1");
  if (!$has) { echo "ATTEMPT_ID=\n"; return; }
} catch (\Throwable $e) {
  echo "ATTEMPT_ID=\n"; return;
}

$att = DB::table("attempts")->orderByDesc("created_at")->first();
if (!$att) { echo "ATTEMPT_ID=\n"; return; }

DB::table("attempts")->where("id", $att->id)->update([
  "anon_id" => $anon,
  "user_id" => null,
]);

echo "ATTEMPT_ID={$att->id}\n";
')"
  ATTEMPT_ID="$(printf "%s" "${ATT_OUT}" | sed -n 's/^ATTEMPT_ID=//p' | tail -n 1)"
fi

if [[ -n "${ATTEMPT_ID}" ]]; then
  echo "[ACCEPT_PHONE] bound attempt_id=${ATTEMPT_ID} -> anon_id=${ANON_ID} (user_id=NULL)"
else
  echo "[ACCEPT_PHONE][WARN] no attempt bound (no sqlite/attempts). Will only verify token gate + /me ok."
fi

# 2) send_code（dev 下会返回 dev_code）
SEND_JSON="$(curl -sS -X POST "${API}/api/v0.2/auth/phone/send_code" \
  -H "Content-Type: application/json" -H "Accept: application/json" \
  -d "{\"phone\":\"${PHONE}\",\"consent\":true,\"scene\":\"${SCENE}\"}")"

CODE="$(php -r '$j=json_decode(stream_get_contents(STDIN), true); echo $j["dev_code"] ?? "";' <<<"${SEND_JSON}")"

# If dev_code not returned (e.g. APP_ENV=testing), read OTP from shared cache via artisan.
if [[ -z "${CODE}" ]]; then
  CODE="$(cd "${BACKEND_DIR}" && php artisan tinker --execute='
use Illuminate\Support\Facades\Cache;
$phone = getenv("PHONE");
$scene = getenv("SCENE") ?: "login";
$k = "otp:{$scene}:{$phone}";
echo (string) Cache::get($k);
' 2>/dev/null | tail -n 1 | tr -d "\r" | tr -d "\n")"
fi

if [[ -z "${CODE}" ]]; then
  echo "[ACCEPT_PHONE][FAIL] cannot obtain OTP code (dev_code missing and cache read empty)"
  echo "[ACCEPT_PHONE] send_code response=${SEND_JSON}"
  exit 1
fi
echo "[ACCEPT_PHONE] code=${CODE}"

echo "[ACCEPT_PHONE] dev_code=${CODE}"

# 3) verify -> token（携带 anon_id 触发归集）
VERIFY_JSON="$(curl -sS -X POST "${API}/api/v0.2/auth/phone/verify" \
  -H "Content-Type: application/json" -H "Accept: application/json" \
  -d "{\"phone\":\"${PHONE}\",\"code\":\"${CODE}\",\"consent\":true,\"scene\":\"${SCENE}\",\"anon_id\":\"${ANON_ID}\"}")"

TOKEN="$(php -r '$j=json_decode(stream_get_contents(STDIN), true); echo $j["token"] ?? "";' <<<"${VERIFY_JSON}")"
if [[ -z "${TOKEN}" ]]; then
  echo "[ACCEPT_PHONE][FAIL] verify did not return token"
  echo "[ACCEPT_PHONE] verify response=${VERIFY_JSON}"
  exit 1
fi
echo "[ACCEPT_PHONE] token_issued=${TOKEN}"

# 4) /me/attempts 必须能看到刚才被归集的 attempt_id
ME_JSON="$(curl -sS -H "Accept: application/json" -H "Authorization: Bearer ${TOKEN}" \
  "${API}/api/v0.2/me/attempts?per_page=20&page=1")"

# 关键：让子进程 php 能拿到 ATTEMPT_ID
export ATTEMPT_ID

php -r '
$j = json_decode(stream_get_contents(STDIN), true);
if (!is_array($j) || !($j["ok"] ?? false)) {
  fwrite(STDERR, "[ACCEPT_PHONE][FAIL] /me/attempts not ok\n");
  exit(1);
}

$att = (string) getenv("ATTEMPT_ID");
if ($att === "") {
  fwrite(STDERR, "[ACCEPT_PHONE][FAIL] ATTEMPT_ID env missing\n");
  exit(1);
}

$items = $j["items"] ?? [];
$found = false;
foreach ($items as $it) {
  if (($it["attempt_id"] ?? "") === $att) { $found = true; break; }
}

if (!$found) {
  fwrite(STDERR, "[ACCEPT_PHONE][FAIL] attempt not found in /me/attempts: ".$att."\n");
  fwrite(STDERR, json_encode($j, JSON_UNESCAPED_UNICODE|JSON_UNESCAPED_SLASHES)."\n");
  exit(1);
}

echo "[ACCEPT_PHONE] PASS ✅ attempt visible in /me/attempts\n";
' <<<"${ME_JSON}"

echo "[ACCEPT_PHONE] DONE ✅"
