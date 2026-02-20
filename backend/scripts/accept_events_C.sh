#!/usr/bin/env bash
set -euo pipefail

# ------------------------------------------------------------
# accept_events_C.sh
# One-shot acceptance for M3 Events loop:
# - report_view on GET /attempts/{id}/report (refresh + cache)
# - share_generate on GET /attempts/{id}/share
# - share_click on POST /shares/{shareId}/click
#
# Assumptions:
# - API is already running (default: http://127.0.0.1:1827)
# - Using testing/sqlite db at backend/database/database.sqlite
# - json parsing via php
#
# Optional headers (pass via env vars):
#   EXPERIMENT="E_accept" APPV="1.2.3" CHANNEL="miniapp" CLIENT_PLATFORM="wechat" ENTRY_PAGE="result_page"
# ------------------------------------------------------------

need_cmd() { command -v "$1" >/dev/null 2>&1 || { echo "[ERR] missing cmd: $1" >&2; exit 2; }; }
need_cmd curl
need_cmd php
need_cmd sed

REPO_DIR="$(cd "$(dirname "${BASH_SOURCE[0]}")/../.." && pwd)"
BACKEND_DIR="$REPO_DIR/backend"

API="${API:-http://127.0.0.1:1827}"
SQLITE_DB="${SQLITE_DB:-$BACKEND_DIR/database/database.sqlite}"

AUTH_HDRS=()
if [[ -n "${FM_TOKEN:-}" && "${FM_TOKEN}" != "null" ]]; then
  AUTH_HDRS=(-H "Authorization: Bearer ${FM_TOKEN}")
fi

echo "[ACCEPT] repo=$REPO_DIR"
echo "[ACCEPT] backend=$BACKEND_DIR"
echo "[ACCEPT] API=$API"
echo "[ACCEPT] SQLITE_DB=$SQLITE_DB"

if [[ ! -f "$SQLITE_DB" ]]; then
  echo "[ERR] sqlite db not found: $SQLITE_DB" >&2
  exit 1
fi

# ---- Optional header pass-through (from env; safe under set -u) ----
HDRS=()
[[ -n "${EXPERIMENT:-}" ]]      && HDRS+=(-H "X-Experiment: $EXPERIMENT")
[[ -n "${APPV:-}" ]]            && HDRS+=(-H "X-App-Version: $APPV")
[[ -n "${CHANNEL:-}" ]]         && HDRS+=(-H "X-Channel: $CHANNEL")
[[ -n "${CLIENT_PLATFORM:-}" ]] && HDRS+=(-H "X-Client-Platform: $CLIENT_PLATFORM")
[[ -n "${ENTRY_PAGE:-}" ]]      && HDRS+=(-H "X-Entry-Page: $ENTRY_PAGE")

# ---- get attempt_id from verify artifacts (preferred), else run ci_verify_mbti ----
ATT="$(php -r '$j=json_decode(@file_get_contents($argv[1]), true); echo $j["attempt_id"] ?? ($j["attemptId"] ?? "");' "$BACKEND_DIR/artifacts/verify_mbti/report.json" 2>/dev/null || true)"
if [[ -z "$ATT" || "$ATT" == "null" ]]; then
  echo "[ACCEPT] attempt_id not found in artifacts; run ci_verify_mbti.sh to generate one"
  (cd "$REPO_DIR" && bash backend/scripts/ci_verify_mbti.sh >/dev/null)
  ATT="$(php -r '$j=json_decode(@file_get_contents($argv[1]), true); echo $j["attempt_id"] ?? ($j["attemptId"] ?? "");' "$BACKEND_DIR/artifacts/verify_mbti/report.json" 2>/dev/null || true)"
fi
if [[ -z "$ATT" || "$ATT" == "null" ]]; then
  echo "[ERR] ATT empty after ci_verify_mbti.sh" >&2
  exit 1
fi
echo "[ACCEPT] ATT=$ATT"
export ATT

ANON_ID="${ANON_ID:-}"
ANON_ID_FILE="$BACKEND_DIR/artifacts/verify_mbti/anon_id.txt"
if [[ -z "$ANON_ID" && -s "$ANON_ID_FILE" ]]; then
  ANON_ID="$(cat "$ANON_ID_FILE")"
fi
if [[ -z "$ANON_ID" ]]; then
  echo "[ERR] missing anon_id; set ANON_ID=... or ensure $ANON_ID_FILE exists" >&2
  exit 1
fi
echo "[ACCEPT] ANON_ID=$ANON_ID"
export ANON_ID

# ---- 1) report_view: refresh=1 then cache hit ----
curl -sS -H "X-Anon-Id: $ANON_ID" \
  "$API/api/v0.3/attempts/$ATT/report?refresh=1&anon_id=$ANON_ID" >/dev/null
sleep 1
curl -sS -H "X-Anon-Id: $ANON_ID" \
  "$API/api/v0.3/attempts/$ATT/report?anon_id=$ANON_ID" >/dev/null

# ---- 2) share_generate (with optional headers) ----
# Use set -u safe array expansion in case HDRS is empty.
SHARE_RAW="$(
  curl -sS \
    ${AUTH_HDRS[@]+"${AUTH_HDRS[@]}"} \
    "$API/api/v0.3/attempts/$ATT/share" \
    ${HDRS[@]+"${HDRS[@]}"} \
  || true
)"
SHARE_JSON="$(printf '%s\n' "$SHARE_RAW" | sed -n '/^{/,$p')"
SHARE_ID="$(printf '%s\n' "$SHARE_JSON" | php -r '$j=json_decode(stream_get_contents(STDIN), true); echo $j["share_id"] ?? ($j["shareId"] ?? "");' 2>/dev/null || true)"

if [[ -z "$SHARE_ID" || "$SHARE_ID" == "null" ]]; then
  echo "[ERR] share_id empty from /attempts/$ATT/share. Raw response:" >&2
  printf '%s\n' "$SHARE_RAW" >&2
  exit 1
fi
echo "[ACCEPT] SHARE_ID=$SHARE_ID"
export SHARE_ID

# ---- 3) share_click (with optional headers) ----
curl -sS -X POST "$API/api/v0.3/shares/$SHARE_ID/click" \
  -H "Content-Type: application/json" \
  ${HDRS[@]+"${HDRS[@]}"} \
  -d '{}' >/dev/null

# ---- 4) verify events in same testing/sqlite db ----
cd "$BACKEND_DIR"

export APP_ENV=testing
export DB_CONNECTION=sqlite
export DB_DATABASE="$SQLITE_DB"

php artisan tinker --execute='
$att     = getenv("ATT") ?: "";
$shareId = getenv("SHARE_ID") ?: "";

$must = function($cond, $msg) {
  if (!$cond) throw new \RuntimeException($msg);
};

$fetch = function($code) use ($att) {
  return \DB::table("events")
    ->where("attempt_id", $att)
    ->where("event_code", $code)
    ->orderByDesc("occurred_at")
    ->first();
};

$rv = $fetch("report_view");
$sg = $fetch("share_generate");
$sc = $fetch("share_click");

$rvMeta = $rv ? json_decode($rv->meta_json ?? "{}", true) : [];
$sgMeta = $sg ? json_decode($sg->meta_json ?? "{}", true) : [];
$scMeta = $sc ? json_decode($sc->meta_json ?? "{}", true) : [];

$must((bool)$rv, "missing report_view");
$must(($rvMeta["attempt_id"] ?? null) === $att, "report_view.meta_json.attempt_id mismatch");
$must(is_string($rvMeta["type_code"] ?? null) && $rvMeta["type_code"] !== "", "report_view.meta_json.type_code missing");
$must(array_key_exists("locked", $rvMeta), "report_view.meta_json.locked missing");

$must((bool)$sg, "missing share_generate");
$must(($sgMeta["share_id"] ?? null) === $shareId, "share_generate.meta_json.share_id mismatch");
$must(is_string($sgMeta["type_code"] ?? null) && $sgMeta["type_code"] !== "", "share_generate.meta_json.type_code missing");
$eng = $sgMeta["engine_version"] ?? ($sgMeta["engine"] ?? "");
$must(is_string($eng) && $eng !== "", "share_generate engine_version/engine missing");
$must(is_string($sgMeta["content_package_version"] ?? null) && $sgMeta["content_package_version"] !== "", "share_generate.meta_json.content_package_version missing");

$must((bool)$sc, "missing share_click");
$must(($scMeta["share_id"] ?? null) === $shareId, "share_click.meta_json.share_id mismatch");
$must(($scMeta["attempt_id"] ?? null) === $att, "share_click.meta_json.attempt_id mismatch");

dump([
  "OK" => true,
  "attempt_id" => $att,
  "share_id" => $shareId,
  "report_view" => [
    "occurred_at" => $rv->occurred_at ?? null,
    "attempt_id" => $rvMeta["attempt_id"] ?? null,
    "type_code" => $rvMeta["type_code"] ?? null,
    "locked" => $rvMeta["locked"] ?? null,
  ],
  "share_generate" => [
    "occurred_at" => $sg->occurred_at ?? null,
    "share_id" => $sgMeta["share_id"] ?? null,
    "type_code" => $sgMeta["type_code"] ?? null,
    "engine_version_or_engine" => $eng,
    "content_package_version" => $sgMeta["content_package_version"] ?? null,
  ],
  "share_click" => [
    "occurred_at" => $sc->occurred_at ?? null,
    "anon_id" => $sc->anon_id ?? null,
    "share_id" => $scMeta["share_id"] ?? null,
    "attempt_id" => $scMeta["attempt_id"] ?? null,
  ],
]);

echo "[ACCEPT] PASS ✅\n";
'
