#!/usr/bin/env bash
set -euo pipefail

# backend/scripts/accept_events_D_click_anon_override.sh
#
# Purpose:
# 1) Read ATT from backend/artifacts/verify_mbti/report.json
# 2) Call /attempts/{ATT}/share to get SHARE_ID
# 3) Call /shares/{SHARE_ID}/click with body anon_id=client_override_123
# 4) Query sqlite to find latest share_click for that SHARE_ID and assert events.anon_id == client_override_123

require_cmd() {
  command -v "$1" >/dev/null 2>&1 || { echo "[ERR] missing command: $1" >&2; exit 1; }
}

require_cmd curl
require_cmd php

REPO_DIR="$(cd "$(dirname "${BASH_SOURCE[0]}")/../.." && pwd)"
BACKEND_DIR="$REPO_DIR/backend"

API="${API:-http://127.0.0.1:1827}"
SQLITE_DB="${SQLITE_DB:-$BACKEND_DIR/database/database.sqlite}"

AUTH_HDRS=()
if [[ -n "${FM_TOKEN:-}" && "${FM_TOKEN}" != "null" ]]; then
  AUTH_HDRS=(-H "Authorization: Bearer ${FM_TOKEN}")
fi

echo "[ACCEPT_D2] repo=$REPO_DIR"
echo "[ACCEPT_D2] backend=$BACKEND_DIR"
echo "[ACCEPT_D2] API=$API"
echo "[ACCEPT_D2] SQLITE_DB=$SQLITE_DB"

# --- Read ATT from artifacts report.json
REPORT_JSON="$BACKEND_DIR/artifacts/verify_mbti/report.json"
if [[ ! -f "$REPORT_JSON" ]]; then
  echo "[ERR] missing $REPORT_JSON (run accept_events_C.sh first)" >&2
  exit 1
fi

ATT="$(php -r '$j=json_decode(@file_get_contents($argv[1]), true); echo $j["attempt_id"] ?? ($j["attemptId"] ?? "");' "$REPORT_JSON" 2>/dev/null || true)"
if [[ -z "$ATT" || "$ATT" == "null" ]]; then
  echo "[ERR] cannot read ATT from $REPORT_JSON" >&2
  exit 1
fi
echo "[ACCEPT_D2] ATT=$ATT"
export ATT

# --- Call /share to get SHARE_ID
SHARE_RAW="$(curl -sS ${AUTH_HDRS[@]+"${AUTH_HDRS[@]}"} "$API/api/v0.2/attempts/$ATT/share" || true)"
SHARE_JSON="$(printf '%s\n' "$SHARE_RAW" | sed -n '/^{/,$p')"
SHARE_ID="$(printf '%s\n' "$SHARE_JSON" | php -r '$j=json_decode(stream_get_contents(STDIN), true); echo $j["share_id"] ?? ($j["shareId"] ?? "");' 2>/dev/null || true)"

if [[ -z "$SHARE_ID" || "$SHARE_ID" == "null" ]]; then
  echo "[ERR] SHARE_ID empty. Raw response:" >&2
  printf '%s\n' "$SHARE_RAW" >&2
  exit 1
fi
echo "[ACCEPT_D2] SHARE_ID=$SHARE_ID"
export SHARE_ID

# --- Click with explicit body anon_id override
OVERRIDE_ANON_ID="client_override_123"
echo "[ACCEPT_D2] override_anon_id=$OVERRIDE_ANON_ID"

CLICK_RAW="$(curl -sS -X POST "$API/api/v0.2/shares/$SHARE_ID/click" \
  -H "Content-Type: application/json" \
  -d "{\"anon_id\":\"$OVERRIDE_ANON_ID\"}" \
  || true)"

# If server prints non-json, keep from first '{'
CLICK_JSON="$(printf '%s\n' "$CLICK_RAW" | sed -n '/^{/,$p')"
CLICK_OK="$(printf '%s\n' "$CLICK_JSON" | php -r '
$j=json_decode(stream_get_contents(STDIN), true);
$ok=$j["ok"] ?? null;
if ($ok === true) { echo "true"; }
elseif ($ok === false) { echo "false"; }
else { echo ""; }
' 2>/dev/null || true)"
if [[ "$CLICK_OK" != "true" ]]; then
  echo "[ERR] click did not return ok:true. Raw response:" >&2
  printf '%s\n' "$CLICK_RAW" >&2
  exit 1
fi

# --- Query sqlite and assert latest share_click anon_id == override
cd "$BACKEND_DIR"

TMP_PHP="$(mktemp -t accept_events_D_click_anon_override.XXXXXX)"
trap 'rm -f "$TMP_PHP"' EXIT

cat >"$TMP_PHP" <<'PHP'
$shareId = getenv("SHARE_ID") ?: "";
$want    = getenv("OVERRIDE_ANON_ID") ?: "";

$fail = function($msg) use ($shareId, $want) {
  throw new \RuntimeException("FAIL: {$msg} (SHARE_ID={$shareId}, WANT={$want})");
};

if ($shareId === "" || $want === "") $fail("missing env SHARE_ID or OVERRIDE_ANON_ID");

$driver = \DB::connection()->getDriverName();

$q = \DB::table("events")->where("event_code", "share_click");

// Filter by share_id in meta_json (cross-db best effort)
if ($driver === "sqlite") {
  $q->whereRaw("json_extract(meta_json, '$.share_id') = ?", [$shareId]);
} elseif ($driver === "mysql") {
  $q->whereRaw("JSON_UNQUOTE(JSON_EXTRACT(meta_json, '$.share_id')) = ?", [$shareId]);
} elseif ($driver === "pgsql") {
  $q->whereRaw("(meta_json::jsonb ->> 'share_id') = ?", [$shareId]);
} else {
  $q->where("meta_json", "like", "%\"share_id\":\"{$shareId}\"%");
}

$row = $q->orderByDesc("occurred_at")->first();
if (!$row) $fail("missing share_click for share_id");

$got = $row->anon_id ?? null;

dump([
  "driver" => $driver,
  "share_id" => $shareId,
  "occurred_at" => $row->occurred_at ?? null,
  "anon_id_col" => $got,
]);

if ($got !== $want) $fail("share_click anon_id did not override");

echo "[ACCEPT_D2] PASS ✅\n";
PHP

OVERRIDE_ANON_ID="$OVERRIDE_ANON_ID" \
APP_ENV=testing DB_CONNECTION=sqlite DB_DATABASE="$SQLITE_DB" \
php artisan tinker --execute="$(cat "$TMP_PHP")"
