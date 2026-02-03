#!/usr/bin/env bash
set -euo pipefail

# accept_events_D_anon.sh
# M3 hard: anon_id must exist and must NOT be placeholder for:
#   - result_view
#   - share_generate
#   - share_click

require_cmd() {
  command -v "$1" >/dev/null 2>&1 || { echo "[ERR] missing command: $1" >&2; exit 1; }
}

require_cmd curl
require_cmd php

REPO_DIR="$(cd "$(dirname "${BASH_SOURCE[0]}")/../.." && pwd)"
BACKEND_DIR="$REPO_DIR/backend"

API="${API:-http://127.0.0.1:1827}"
SQLITE_DB="${SQLITE_DB:-$BACKEND_DIR/database/database.sqlite}"

echo "[ACCEPT_D] repo=$REPO_DIR"
echo "[ACCEPT_D] backend=$BACKEND_DIR"
echo "[ACCEPT_D] API=$API"
echo "[ACCEPT_D] SQLITE_DB=$SQLITE_DB"

# --------------------------------------------
# Defaults (MUST be set before any use; set -u)
# --------------------------------------------
EXPERIMENT="${EXPERIMENT:-D_accept}"
APPV="${APPV:-1.2.3-accept}"
CHANNEL="${CHANNEL:-miniapp}"
CLIENT_PLATFORM="${CLIENT_PLATFORM:-wechat}"
ENTRY_PAGE="${ENTRY_PAGE:-result_page}"

export EXPERIMENT APPV CHANNEL CLIENT_PLATFORM ENTRY_PAGE

# --------------------------------------------
# Step B-1) Run C to generate a fresh attempt + events
# --------------------------------------------
API="$API" SQLITE_DB="$SQLITE_DB" \
  EXPERIMENT="$EXPERIMENT" APPV="$APPV" CHANNEL="$CHANNEL" \
  CLIENT_PLATFORM="$CLIENT_PLATFORM" ENTRY_PAGE="$ENTRY_PAGE" \
  "$BACKEND_DIR/scripts/accept_events_C.sh" >/dev/null

# --------------------------------------------
# Resolve ATT + SHARE_ID from artifacts
# - ATT: from artifacts/verify_mbti/report.json
# - SHARE_ID: prefer env if C exported; else try to read from artifacts if present
# --------------------------------------------
REPORT_JSON="$BACKEND_DIR/artifacts/verify_mbti/report.json"
if [[ ! -f "$REPORT_JSON" ]]; then
  echo "[ERR] missing $REPORT_JSON (accept_events_C.sh should generate it)" >&2
  exit 1
fi

ATT="$(php -r '$j=json_decode(@file_get_contents($argv[1]), true); echo $j["attempt_id"] ?? ($j["attemptId"] ?? "");' "$REPORT_JSON" 2>/dev/null || true)"
if [[ -z "$ATT" || "$ATT" == "null" ]]; then
  echo "[ERR] cannot read ATT from $REPORT_JSON" >&2
  exit 1
fi
echo "[ACCEPT_D] ATT=$ATT"
export ATT

# SHARE_ID resolution:
# 1) If accept_events_C.sh exported SHARE_ID in current shell (unlikely), use it
# 2) Else: call /share ourselves with headers, so we get a deterministic SHARE_ID and
#    the share_generate event should have same anon_id as attempt-side (if implemented).
SHARE_RAW="$(curl -sS "$API/api/v0.2/attempts/$ATT/share" \
  -H "X-Experiment: $EXPERIMENT" \
  -H "X-App-Version: $APPV" \
  -H "X-Channel: $CHANNEL" \
  -H "X-Client-Platform: $CLIENT_PLATFORM" \
  -H "X-Entry-Page: $ENTRY_PAGE" \
  || true)"
SHARE_JSON="$(printf '%s\n' "$SHARE_RAW" | sed -n '/^{/,$p')"
SHARE_ID="$(printf '%s\n' "$SHARE_JSON" | php -r '$j=json_decode(stream_get_contents(STDIN), true); echo $j["share_id"] ?? ($j["shareId"] ?? "");' 2>/dev/null || true)"

if [[ -z "$SHARE_ID" || "$SHARE_ID" == "null" ]]; then
  echo "[ERR] SHARE_ID empty. Raw response:" >&2
  printf '%s\n' "$SHARE_RAW" >&2
  exit 1
fi
echo "[ACCEPT_D] SHARE_ID=$SHARE_ID"
export SHARE_ID

# Trigger click so share_click event exists
curl -sS -X POST "$API/api/v0.2/shares/$SHARE_ID/click" \
  -H "Content-Type: application/json" \
  -H "X-Experiment: $EXPERIMENT" \
  -H "X-App-Version: $APPV" \
  -H "X-Channel: $CHANNEL" \
  -H "X-Client-Platform: $CLIENT_PLATFORM" \
  -H "X-Entry-Page: $ENTRY_PAGE" \
  -d '{}' >/dev/null

# --------------------------------------------
# Step A + Step B-2) Define placeholder blacklist in code and validate in DB
# --------------------------------------------
cd "$BACKEND_DIR"

TMP_PHP="$(mktemp -t accept_events_D_anon.XXXXXX)"
trap 'rm -f "$TMP_PHP"' EXIT

cat >"$TMP_PHP" <<'PHP'
$att     = getenv("ATT") ?: "";
$shareId = getenv("SHARE_ID") ?: "";

$fail = function($msg) use ($att, $shareId) {
  throw new \RuntimeException("FAIL: {$msg} (ATT={$att}, SHARE_ID={$shareId})");
};

if ($att === "" || $shareId === "") $fail("missing env ATT or SHARE_ID");

/**
 * Step A: Placeholder blacklist (验收口径写死在脚本里)
 * - We match case-insensitively.
 * - Use "contains" match; if any hit => FAIL.
 */
$blacklist = [
  "todo",
  "placeholder",
  "把你查到的anon_id填这里",
  "把你查到的 anon_id 填这里",
  "填这里",
  "tbd",
  "fixme",
  "xxx",
  "unknown",
  "null",
];

$driver = \DB::connection()->getDriverName();

$applyShareIdFilter = function($q) use ($driver, $shareId) {
  if ($driver === "sqlite") {
    return $q->whereRaw("json_extract(meta_json, '$.share_id') = ?", [$shareId]);
  }
  if ($driver === "mysql") {
    return $q->whereRaw("JSON_UNQUOTE(JSON_EXTRACT(meta_json, '$.share_id')) = ?", [$shareId]);
  }
  if ($driver === "pgsql") {
    return $q->whereRaw("(meta_json::jsonb ->> 'share_id') = ?", [$shareId]);
  }
  $like = "%\"share_id\":\"{$shareId}\"%";
  return $q->where("meta_json", "like", $like);
};

$fetch = function(string $eventCode) use ($att, $shareId, $applyShareIdFilter) {
  $q = \DB::table("events")
    ->where("event_code", $eventCode)
    ->where("attempt_id", $att);

  // result_view may not carry share_id; we will handle it separately below.
  if (in_array($eventCode, ["share_generate", "share_click"], true)) {
    $q = $applyShareIdFilter($q);
  }

  return $q->orderByDesc("occurred_at")->first();
};

$sg = $fetch("share_generate");
$sc = $fetch("share_click");

// result_view: usually keyed by attempt_id only (no share_id)
$rv = \DB::table("events")
  ->where("event_code", "result_view")
  ->where("attempt_id", $att)
  ->orderByDesc("occurred_at")
  ->first();

if (!$rv) $fail("missing result_view");
if (!$sg) $fail("missing share_generate");
if (!$sc) $fail("missing share_click");

$rvm = json_decode($rv->meta_json ?? "{}", true) ?: [];
$sgm = json_decode($sg->meta_json ?? "{}", true) ?: [];
$scm = json_decode($sc->meta_json ?? "{}", true) ?: [];

$norm = function($v) {
  $s = is_string($v) ? $v : (is_null($v) ? "" : strval($v));
  $s = trim($s);
  return $s;
};

$assertAnon = function($row, array $m, string $label) use ($fail, $blacklist, $norm) {
  // ✅ 优先用 events 表的 anon_id 列；没有再 fallback meta_json.anon_id
  $anon = $norm($row->anon_id ?? ($m["anon_id"] ?? ""));
  if ($anon === "") $fail("{$label}.anon_id missing/empty");

  $lower = mb_strtolower($anon, "UTF-8");
  foreach ($blacklist as $bad) {
    $badLower = mb_strtolower($bad, "UTF-8");
    if ($badLower !== "" && mb_strpos($lower, $badLower, 0, "UTF-8") !== false) {
      $fail("{$label}.anon_id hit blacklist: {$bad} (anon_id={$anon})");
    }
  }
  return $anon;
};

$rvAnon = $assertAnon($rv, $rvm, "result_view");
$sgAnon = $assertAnon($sg, $sgm, "share_generate");
$scAnon = $assertAnon($sc, $scm, "share_click");

dump([
  "driver" => $driver,
  "result_view" => [
    "occurred_at" => $rv->occurred_at ?? null,
    "anon_id" => $rvAnon,
  ],
  "share_generate" => [
    "occurred_at" => $sg->occurred_at ?? null,
    "share_id" => $sgm["share_id"] ?? null,
    "anon_id" => $sgAnon,
  ],
  "share_click" => [
    "occurred_at" => $sc->occurred_at ?? null,
    "share_id" => $scm["share_id"] ?? null,
    "anon_id" => $scAnon,
  ],
]);

echo "[ACCEPT_D] PASS ✅\n";
PHP

APP_ENV=testing DB_CONNECTION=sqlite DB_DATABASE="$SQLITE_DB" \
php artisan tinker --execute="$(cat "$TMP_PHP")"
