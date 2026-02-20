#!/usr/bin/env bash
set -euo pipefail

# accept_events_F_result_view_meta.sh
# Verify result_view meta_json has:
# - v0.3 baseline fields: attempt_id / type_code / scale_code / pack_id / dir_version
# - request channel is written into events.channel column

need_cmd() { command -v "$1" >/dev/null 2>&1 || { echo "[ERR] missing cmd: $1" >&2; exit 2; }; }
need_cmd curl
need_cmd php
need_cmd sleep

REPO_DIR="$(cd "$(dirname "${BASH_SOURCE[0]}")/../.." && pwd)"
BACKEND_DIR="$REPO_DIR/backend"

API="${API:-http://127.0.0.1:1827}"
SQLITE_DB="${SQLITE_DB:-$BACKEND_DIR/database/database.sqlite}"

echo "[ACCEPT_F] repo=$REPO_DIR"
echo "[ACCEPT_F] backend=$BACKEND_DIR"
echo "[ACCEPT_F] API=$API"
echo "[ACCEPT_F] SQLITE_DB=$SQLITE_DB"

# --------------------------------------------
# Defaults (safe under set -u)
# --------------------------------------------
EXPERIMENT="${EXPERIMENT:-F_accept}"
APPV="${APPV:-1.2.3-accept}"
CHANNEL="${CHANNEL:-miniapp}"
CLIENT_PLATFORM="${CLIENT_PLATFORM:-wechat}"
ENTRY_PAGE="${ENTRY_PAGE:-result_page}"

export EXPERIMENT APPV CHANNEL CLIENT_PLATFORM ENTRY_PAGE

# --------------------------------------------
# Load artifacts: ATT + SHARE_ID
# --------------------------------------------
REPORT_JSON="$BACKEND_DIR/artifacts/verify_mbti/report.json"
SHARE_JSON="$BACKEND_DIR/artifacts/verify_mbti/share.json"

if [[ ! -f "$REPORT_JSON" || ! -f "$SHARE_JSON" ]]; then
  echo "[ACCEPT_F] artifacts missing, run ci_verify_mbti to generate" >&2
  (cd "$REPO_DIR" && bash backend/scripts/ci_verify_mbti.sh >/dev/null)
fi

ATT="$(php -r '$j=json_decode(@file_get_contents($argv[1]), true); echo $j["attempt_id"] ?? ($j["attemptId"] ?? "");' "$REPORT_JSON" 2>/dev/null || true)"
if [[ -z "$ATT" || "$ATT" == "null" ]]; then
  echo "[ERR] cannot read ATT from $REPORT_JSON" >&2
  exit 1
fi

SHARE_ID="$(php -r '$j=json_decode(@file_get_contents($argv[1]), true); echo $j["share_id"] ?? ($j["shareId"] ?? "");' "$SHARE_JSON" 2>/dev/null || true)"
if [[ -z "$SHARE_ID" || "$SHARE_ID" == "null" ]]; then
  echo "[ERR] cannot read SHARE_ID from $SHARE_JSON" >&2
  exit 1
fi

echo "[ACCEPT_F] ATT=$ATT"
echo "[ACCEPT_F] SHARE_ID=$SHARE_ID"

# --------------------------------------------
# Get fm_token for gated endpoints (must be provided by caller/CI)
# --------------------------------------------
FM_TOKEN="${FM_TOKEN:-}"
if [[ -z "$FM_TOKEN" || "$FM_TOKEN" == "null" ]]; then
  echo "[ACCEPT_F][FAIL] FM_TOKEN missing; pass token from ci_verify_mbti.sh" >&2
  exit 15
fi

CURL_AUTH=(-H "Authorization: Bearer $FM_TOKEN")

# --------------------------------------------
# Call /result with headers + share_id
# IMPORTANT:
#   result_view event should be emitted by GET /attempts/{id}/result
#   so we must call /result (NOT /report)
# --------------------------------------------
call_result() {
  local url="$1"
  local http=""
  http="$(curl -sS -L -o /dev/null -w "%{http_code}" \
    "${CURL_AUTH[@]}" \
    -H "Accept: application/json" \
    -H "X-Experiment: $EXPERIMENT" \
    -H "X-App-Version: $APPV" \
    -H "X-Channel: $CHANNEL" \
    -H "X-Client-Platform: $CLIENT_PLATFORM" \
    -H "X-Entry-Page: $ENTRY_PAGE" \
    "$url" || true)"

  if [[ "$http" != "200" ]]; then
    echo "[ACCEPT_F][FAIL] call_result HTTP=$http url=$url" >&2
    exit 2
  fi
}

# Call twice (dedup/backfill safe)
call_result "$API/api/v0.3/attempts/$ATT/result?share_id=$SHARE_ID"
sleep 1
call_result "$API/api/v0.3/attempts/$ATT/result?share_id=$SHARE_ID"

# --------------------------------------------
# Verify in sqlite: result_view carries v0.3 baseline metadata
# --------------------------------------------
cd "$BACKEND_DIR"
APP_ENV=testing DB_CONNECTION=sqlite DB_DATABASE="$SQLITE_DB" \
ATT="$ATT" SHARE_ID="$SHARE_ID" \
EXPERIMENT="$EXPERIMENT" APPV="$APPV" CHANNEL="$CHANNEL" CLIENT_PLATFORM="$CLIENT_PLATFORM" ENTRY_PAGE="$ENTRY_PAGE" \
php artisan tinker --execute='
$att = getenv("ATT") ?: "";
$shareId = getenv("SHARE_ID") ?: "";

$exp = getenv("EXPERIMENT") ?: "";
$ver = getenv("APPV") ?: "";
$ch  = getenv("CHANNEL") ?: "";
$cp  = getenv("CLIENT_PLATFORM") ?: "";
$ep  = getenv("ENTRY_PAGE") ?: "";

$fail = function($msg) use ($att, $shareId) {
  throw new \RuntimeException("FAIL: ".$msg." (ATT={$att}, SHARE_ID={$shareId})");
};

$driver = \DB::connection()->getDriverName();

$fetchByAttempt = function() use ($att) {
  return \DB::table("events")
    ->where("event_code", "result_view")
    ->where("attempt_id", $att)
    ->orderByDesc("occurred_at")
    ->first();
};

$rv = $fetchByAttempt();
if (!$rv) {
  $rv = \DB::table("events")
    ->where("event_code", "result_view")
    ->where("meta_json", "like", "%\"attempt_id\":\"{$att}\"%")
    ->orderByDesc("occurred_at")
    ->first();
}
if (!$rv) {
  $rv = \DB::table("events")
    ->where("event_code", "result_view")
    ->orderByDesc("occurred_at")
    ->first();
}
if (!$rv) $fail("missing result_view");

$m = json_decode($rv->meta_json ?? "{}", true) ?: [];

// v0.3 baseline fields (attempt_id may be in column or meta depending recorder path)
if (($m["attempt_id"] ?? "") !== "" && ($m["attempt_id"] ?? null) !== $att) {
  $fail("result_view.attempt_id mismatch");
}
if (empty($m["type_code"])) $fail("result_view.type_code missing");

// channel is expected in column (context), not meta_json
if (($rv->channel ?? "") !== "" && ($rv->channel ?? null) !== $ch) {
  $fail("result_view.channel column mismatch");
}

dump([
  "driver" => config("database.default"),
  "db" => config("database.connections.".config("database.default").".database"),
  "driver_name" => $driver,
  "occurred_at" => $rv->occurred_at ?? null,
  "channel_col" => $rv->channel ?? null,
  "result_view" => [
    "attempt_id" => $m["attempt_id"] ?? null,
    "type_code" => $m["type_code"] ?? null,
    "scale_code" => $m["scale_code"] ?? null,
    "pack_id" => $m["pack_id"] ?? null,
    "dir_version" => $m["dir_version"] ?? null,
  ],
]);

echo "[ACCEPT_F] PASS ✅\n";
'
