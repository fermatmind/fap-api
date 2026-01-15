#!/usr/bin/env bash
set -euo pipefail

# accept_events_F_result_view_meta.sh
# Verify result_view meta_json has:
# - type_code / engine_version / content_package_version (baseline)
# - experiment / version / channel / client_platform / entry_page (header passthrough)
# - share_id is backfilled when request contains share_id

need_cmd() { command -v "$1" >/dev/null 2>&1 || { echo "[ERR] missing cmd: $1" >&2; exit 2; }; }
need_cmd curl
need_cmd jq
need_cmd php
need_cmd sed

REPO_DIR="$(cd "$(dirname "${BASH_SOURCE[0]}")/../.." && pwd)"
BACKEND_DIR="$REPO_DIR/backend"

API="${API:-http://127.0.0.1:18000}"
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

ATT="$(jq -r '.attempt_id // .attemptId // empty' "$REPORT_JSON" 2>/dev/null || true)"
if [[ -z "$ATT" || "$ATT" == "null" ]]; then
  echo "[ERR] cannot read ATT from $REPORT_JSON" >&2
  exit 1
fi

SHARE_ID="$(jq -r '.share_id // .shareId // empty' "$SHARE_JSON" 2>/dev/null || true)"
if [[ -z "$SHARE_ID" || "$SHARE_ID" == "null" ]]; then
  echo "[ERR] cannot read SHARE_ID from $SHARE_JSON" >&2
  exit 1
fi

echo "[ACCEPT_F] ATT=$ATT"
echo "[ACCEPT_F] SHARE_ID=$SHARE_ID"

# --------------------------------------------
# Call /report with headers + share_id (twice to trigger 10s dedup backfill safely)
# --------------------------------------------
curl -sS "$API/api/v0.2/attempts/$ATT/report?refresh=1&share_id=$SHARE_ID" \
  -H "X-Experiment: $EXPERIMENT" \
  -H "X-App-Version: $APPV" \
  -H "X-Channel: $CHANNEL" \
  -H "X-Client-Platform: $CLIENT_PLATFORM" \
  -H "X-Entry-Page: $ENTRY_PAGE" >/dev/null

sleep 1

curl -sS "$API/api/v0.2/attempts/$ATT/report?share_id=$SHARE_ID" \
  -H "X-Experiment: $EXPERIMENT" \
  -H "X-App-Version: $APPV" \
  -H "X-Channel: $CHANNEL" \
  -H "X-Client-Platform: $CLIENT_PLATFORM" \
  -H "X-Entry-Page: $ENTRY_PAGE" >/dev/null

# --------------------------------------------
# Verify in sqlite: result_view meta_json contains required fields & share_id backfilled
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

$fetch = function() use ($att, $shareId) {
  // 优先用 JSON path 精确匹配 share_id
  $q = \DB::table("events")
    ->where("event_code", "result_view")
    ->where("attempt_id", $att)
    ->where("meta_json->share_id", $shareId)
    ->orderByDesc("occurred_at");

  $row = $q->first();
  if ($row) return $row;

  // fallback: like
  $like = "%\"share_id\":\"{$shareId}\"%";
  return \DB::table("events")
    ->where("event_code","result_view")
    ->where("attempt_id",$att)
    ->where("meta_json","like",$like)
    ->orderByDesc("occurred_at")
    ->first();
};

$rv = $fetch();
if (!$rv) $fail("missing result_view for given share_id");

$m = json_decode($rv->meta_json ?? "{}", true) ?: [];

if (($m["share_id"] ?? null) !== $shareId) $fail("result_view.share_id not backfilled");

if (empty($m["type_code"])) $fail("result_view.type_code missing");
if (empty($m["content_package_version"])) $fail("result_view.content_package_version missing");
if (empty($m["engine_version"]) && empty($m["engine"])) $fail("result_view.engine_version missing");

// ✅ 新增收口字段（与 share_* 对齐）
if (empty($m["experiment"])) $fail("result_view.experiment missing");
if (empty($m["version"]))    $fail("result_view.version missing");
if (empty($m["channel"]))    $fail("result_view.channel missing");
if (empty($m["client_platform"])) $fail("result_view.client_platform missing");
if (empty($m["entry_page"])) $fail("result_view.entry_page missing");

// ✅ 校验值等于本次 headers（避免“有字段但不对”）
if (($m["experiment"] ?? "") !== $exp) $fail("result_view.experiment mismatch");
if (($m["version"] ?? "") !== $ver)    $fail("result_view.version mismatch");
if (($m["channel"] ?? "") !== $ch)     $fail("result_view.channel mismatch");
if (($m["client_platform"] ?? "") !== $cp) $fail("result_view.client_platform mismatch");
if (($m["entry_page"] ?? "") !== $ep)  $fail("result_view.entry_page mismatch");

dump([
  "driver" => config("database.default"),
  "occurred_at" => $rv->occurred_at ?? null,
  "result_view" => [
    "share_id" => $m["share_id"] ?? null,
    "experiment" => $m["experiment"] ?? null,
    "version" => $m["version"] ?? null,
    "channel" => $m["channel"] ?? null,
    "client_platform" => $m["client_platform"] ?? null,
    "entry_page" => $m["entry_page"] ?? null,
  ],
]);

echo "[ACCEPT_F] PASS ✅\n";
'