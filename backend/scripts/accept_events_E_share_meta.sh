#!/usr/bin/env bash
set -euo pipefail

# accept_events_E_share_meta.sh
# Verifies that share_generate/share_click events contain required meta fields:
# experiment/version/channel/client_platform/entry_page, etc.

require_cmd() {
  command -v "$1" >/dev/null 2>&1 || { echo "[ERR] missing command: $1" >&2; exit 1; }
}

require_cmd curl
require_cmd jq
require_cmd php

REPO_DIR="$(cd "$(dirname "${BASH_SOURCE[0]}")/../.." && pwd)"
BACKEND_DIR="$REPO_DIR/backend"

API="${API:-http://127.0.0.1:18000}"
SQLITE_DB="${SQLITE_DB:-$BACKEND_DIR/database/database.sqlite}"

echo "[ACCEPT_E] repo=$REPO_DIR"
echo "[ACCEPT_E] backend=$BACKEND_DIR"
echo "[ACCEPT_E] API=$API"
echo "[ACCEPT_E] SQLITE_DB=$SQLITE_DB"

# --------------------------------------------
# Defaults (MUST be set before any use; set -u)
# --------------------------------------------
EXPERIMENT="${EXPERIMENT:-E_accept}"
APPV="${APPV:-1.2.3-accept}"
CHANNEL="${CHANNEL:-miniapp}"
CLIENT_PLATFORM="${CLIENT_PLATFORM:-wechat}"
ENTRY_PAGE="${ENTRY_PAGE:-result_page}"

export EXPERIMENT APPV CHANNEL CLIENT_PLATFORM ENTRY_PAGE

# --------------------------------------------
# 1) Optional: run C to produce fresh attempt/report artifacts
#    Set SKIP_C=1 to skip.
# --------------------------------------------
SKIP_C="${SKIP_C:-0}"
if [[ "$SKIP_C" != "1" ]]; then
  API="$API" SQLITE_DB="$SQLITE_DB" \
    EXPERIMENT="$EXPERIMENT" APPV="$APPV" CHANNEL="$CHANNEL" \
    CLIENT_PLATFORM="$CLIENT_PLATFORM" ENTRY_PAGE="$ENTRY_PAGE" \
    "$BACKEND_DIR/scripts/accept_events_C.sh" >/dev/null
fi

# --------------------------------------------
# 2) Resolve ATT
#    Priority: $ATT env > artifacts/verify_mbti/report.json
# --------------------------------------------
ATT="${ATT:-}"
if [[ -z "$ATT" ]]; then
  REPORT_JSON="$BACKEND_DIR/artifacts/verify_mbti/report.json"
  if [[ -f "$REPORT_JSON" ]]; then
    ATT="$(jq -r '.attempt_id // .attemptId // empty' "$REPORT_JSON" 2>/dev/null || true)"
  fi
fi
if [[ -z "$ATT" ]]; then
  echo "[ERR] cannot resolve ATT. Provide ATT=... or ensure artifacts/verify_mbti/report.json exists." >&2
  exit 1
fi
echo "[ACCEPT_E] ATT=$ATT"
export ATT

# --------------------------------------------
# 3) /share with headers (must produce share_generate meta)
# --------------------------------------------
SHARE_RAW="$(curl -sS "$API/api/v0.2/attempts/$ATT/share" \
  -H "X-Experiment: $EXPERIMENT" \
  -H "X-App-Version: $APPV" \
  -H "X-Channel: $CHANNEL" \
  -H "X-Client-Platform: $CLIENT_PLATFORM" \
  -H "X-Entry-Page: $ENTRY_PAGE" \
  || true)"

# Some endpoints may print non-JSON prefix; keep from first '{'
SHARE_JSON="$(printf '%s\n' "$SHARE_RAW" | sed -n '/^{/,$p')"
SHARE_ID="$(printf '%s\n' "$SHARE_JSON" | jq -r '.share_id // .shareId // empty' 2>/dev/null || true)"

if [[ -z "$SHARE_ID" || "$SHARE_ID" == "null" ]]; then
  echo "[ERR] SHARE_ID empty. Raw response:" >&2
  printf '%s\n' "$SHARE_RAW" >&2
  exit 1
fi

echo "[ACCEPT_E] SHARE_ID=$SHARE_ID"
export SHARE_ID

# --------------------------------------------
# 4) click with headers (must produce share_click meta)
# --------------------------------------------
curl -sS -X POST "$API/api/v0.2/shares/$SHARE_ID/click" \
  -H "Content-Type: application/json" \
  -H "X-Experiment: $EXPERIMENT" \
  -H "X-App-Version: $APPV" \
  -H "X-Channel: $CHANNEL" \
  -H "X-Client-Platform: $CLIENT_PLATFORM" \
  -H "X-Entry-Page: $ENTRY_PAGE" \
  -d '{}' >/dev/null

# --------------------------------------------
# 5) Query sqlite and validate meta fields
#    (Cross-DB safe JSON extraction, with LIKE fallback)
# --------------------------------------------
cd "$BACKEND_DIR"

TMP_PHP="$(mktemp -t accept_events_E_share_meta.XXXXXX)"
trap 'rm -f "$TMP_PHP"' EXIT

cat >"$TMP_PHP" <<'PHP'
$att     = getenv("ATT") ?: "";
$shareId = getenv("SHARE_ID") ?: "";

$fail = function($msg) use ($att, $shareId) {
  throw new \RuntimeException("FAIL: {$msg} (ATT={$att}, SHARE_ID={$shareId})");
};

if ($att === "" || $shareId === "") $fail("missing env ATT or SHARE_ID");

$driver = \DB::connection()->getDriverName();

$applyShareIdFilter = function($q) use ($driver, $shareId) {
  // Prefer exact JSON extraction where possible; fallback to LIKE.
  if ($driver === "sqlite") {
    // json_extract works if meta_json is valid JSON text
    return $q->whereRaw("json_extract(meta_json, '$.share_id') = ?", [$shareId]);
  }
  if ($driver === "mysql") {
    return $q->whereRaw("JSON_UNQUOTE(JSON_EXTRACT(meta_json, '$.share_id')) = ?", [$shareId]);
  }
  if ($driver === "pgsql") {
    return $q->whereRaw("(meta_json::jsonb ->> 'share_id') = ?", [$shareId]);
  }

  // Unknown driver: best-effort LIKE
  $like = "%\"share_id\":\"{$shareId}\"%";
  return $q->where("meta_json", "like", $like);
};

$fetch = function(string $eventCode) use ($att, $shareId, $applyShareIdFilter) {
  $q = \DB::table("events")
    ->where("event_code", $eventCode)
    ->where("attempt_id", $att);

  $q = $applyShareIdFilter($q);

  $row = $q->orderByDesc("occurred_at")->first();
  if ($row) return $row;

  // Final fallback: LIKE (covers cases where JSON functions are unavailable)
  $like = "%\"share_id\":\"{$shareId}\"%";
  return \DB::table("events")
    ->where("event_code", $eventCode)
    ->where("attempt_id", $att)
    ->where("meta_json", "like", $like)
    ->orderByDesc("occurred_at")
    ->first();
};

$sg = $fetch("share_generate");
$sc = $fetch("share_click");

if (!$sg) $fail("missing share_generate");
if (!$sc) $fail("missing share_click");

$sgm = json_decode($sg->meta_json ?? "{}", true) ?: [];
$scm = json_decode($sc->meta_json ?? "{}", true) ?: [];

$req = function(array $m, string $k, string $label) use ($fail) {
  if (!isset($m[$k]) || $m[$k] === "" || $m[$k] === null) $fail("{$label}.{$k} missing");
};

if (($sgm["share_id"] ?? null) !== $shareId) $fail("share_generate.share_id mismatch");
$req($sgm, "type_code", "share_generate");
$req($sgm, "content_package_version", "share_generate");
if (empty($sgm["engine_version"]) && empty($sgm["engine"])) $fail("share_generate.engine_version missing");

# ✅ Required AB + channel fields
$req($sgm, "experiment", "share_generate");
$req($sgm, "version", "share_generate");
$req($sgm, "channel", "share_generate");
$req($sgm, "client_platform", "share_generate");
$req($sgm, "entry_page", "share_generate");

if (($scm["share_id"] ?? null) !== $shareId) $fail("share_click.share_id mismatch");
$req($scm, "attempt_id", "share_click");
$req($scm, "channel", "share_click");
$req($scm, "client_platform", "share_click");
$req($scm, "entry_page", "share_click");
$req($scm, "experiment", "share_click");
$req($scm, "version", "share_click");

dump([
  "driver" => $driver,
  "share_generate" => [
    "occurred_at" => $sg->occurred_at ?? null,
    "share_id" => $sgm["share_id"] ?? null,
    "experiment" => $sgm["experiment"] ?? null,
    "version" => $sgm["version"] ?? null,
    "channel" => $sgm["channel"] ?? null,
    "client_platform" => $sgm["client_platform"] ?? null,
    "entry_page" => $sgm["entry_page"] ?? null,
  ],
  "share_click" => [
    "occurred_at" => $sc->occurred_at ?? null,
    "share_id" => $scm["share_id"] ?? null,
    "experiment" => $scm["experiment"] ?? null,
    "version" => $scm["version"] ?? null,
    "channel" => $scm["channel"] ?? null,
    "client_platform" => $scm["client_platform"] ?? null,
    "entry_page" => $scm["entry_page"] ?? null,
  ],
]);

echo "[ACCEPT_E] PASS ✅\n";
PHP

APP_ENV=testing DB_CONNECTION=sqlite DB_DATABASE="$SQLITE_DB" \
php artisan tinker --execute="$(cat "$TMP_PHP")"