#!/usr/bin/env bash
set -euo pipefail

# ------------------------------------------------------------
# accept_events_C.sh
# One-shot acceptance for M3 Events loop:
# - result_view on GET /attempts/{id}/report (refresh + cache)
# - share_generate on GET /attempts/{id}/share
# - share_click on POST /shares/{shareId}/click
#
# Assumptions:
# - API is already running (default: http://127.0.0.1:18000)
# - Using testing/sqlite db at backend/database/database.sqlite
# - jq is installed (used in existing workflow)
# ------------------------------------------------------------

REPO_DIR="$(cd "$(dirname "${BASH_SOURCE[0]}")/../.." && pwd)"
BACKEND_DIR="$REPO_DIR/backend"
API="${API:-http://127.0.0.1:18000}"

SQLITE_DB="${SQLITE_DB:-$BACKEND_DIR/database/database.sqlite}"

echo "[ACCEPT] repo=$REPO_DIR"
echo "[ACCEPT] backend=$BACKEND_DIR"
echo "[ACCEPT] API=$API"
echo "[ACCEPT] SQLITE_DB=$SQLITE_DB"

if [[ ! -f "$SQLITE_DB" ]]; then
  echo "[ERR] sqlite db not found: $SQLITE_DB" >&2
  exit 1
fi

# ---- get attempt_id from verify artifacts (preferred), else run ci_verify_mbti ----
ATT="$(jq -r '.attempt_id // .attemptId // empty' "$BACKEND_DIR/artifacts/verify_mbti/report.json" 2>/dev/null || true)"
if [[ -z "${ATT:-}" ]]; then
  echo "[ACCEPT] attempt_id not found in artifacts; run ci_verify_mbti.sh to generate one"
  (cd "$REPO_DIR" && bash backend/scripts/ci_verify_mbti.sh >/dev/null)
  ATT="$(jq -r '.attempt_id // .attemptId // empty' "$BACKEND_DIR/artifacts/verify_mbti/report.json")"
fi
echo "[ACCEPT] ATT=$ATT"

# ---- 1) result_view: refresh=1 then cache hit ----
curl -sS "$API/api/v0.2/attempts/$ATT/report?refresh=1" >/dev/null
sleep 1
curl -sS "$API/api/v0.2/attempts/$ATT/report" >/dev/null

# ---- 2) share_generate ----
SHARE_ID="$(curl -sS "$API/api/v0.2/attempts/$ATT/share" | sed -n '/^{/,$p' | jq -r '.share_id')"
if [[ -z "$SHARE_ID" || "$SHARE_ID" == "null" ]]; then
  echo "[ERR] share_id empty from /attempts/$ATT/share" >&2
  exit 1
fi
echo "[ACCEPT] SHARE_ID=$SHARE_ID"

# ---- 3) share_click ----
curl -sS -X POST "$API/api/v0.2/shares/$SHARE_ID/click" \
  -H "Content-Type: application/json" \
  -d '{}' >/dev/null

# ---- 4) verify events in same testing/sqlite db ----
# We validate:
# - result_view latest has: type_code, engine_version, content_package_version, cache/refresh flags
# - share_generate latest has: share_id, type_code, engine_version, content_package_version
# - share_click latest has: share_id, attempt_id (and anon_id may be null for now)
cd "$BACKEND_DIR"

export APP_ENV=testing
export DB_CONNECTION=sqlite
export DB_DATABASE="$SQLITE_DB"

php artisan tinker --execute='
$att = getenv("ATT") ?: "";
$shareId = getenv("SHARE_ID") ?: "";

$must = function($cond, $msg) {
  if (!$cond) { throw new \RuntimeException($msg); }
};

$fetch = function($code) use ($att) {
  return \DB::table("events")->where("attempt_id",$att)->where("event_code",$code)->orderByDesc("occurred_at")->first();
};

$rv = $fetch("result_view");
$sg = $fetch("share_generate");
$sc = $fetch("share_click");

$rvMeta = $rv ? json_decode($rv->meta_json ?? "{}", true) : [];
$sgMeta = $sg ? json_decode($sg->meta_json ?? "{}", true) : [];
$scMeta = $sc ? json_decode($sc->meta_json ?? "{}", true) : [];

$must((bool)$rv, "missing result_view");
$must(is_string($rvMeta["type_code"] ?? null) && $rvMeta["type_code"] !== "", "result_view.meta_json.type_code missing");
$must(is_string($rvMeta["engine_version"] ?? null) && $rvMeta["engine_version"] !== "", "result_view.meta_json.engine_version missing");
$must(is_string($rvMeta["content_package_version"] ?? null) && $rvMeta["content_package_version"] !== "", "result_view.meta_json.content_package_version missing");
$must(array_key_exists("cache",$rvMeta), "result_view.meta_json.cache missing");
$must(array_key_exists("refresh",$rvMeta), "result_view.meta_json.refresh missing");

$must((bool)$sg, "missing share_generate");
$must(($sgMeta["share_id"] ?? null) === $shareId, "share_generate.meta_json.share_id mismatch");
$must(is_string($sgMeta["type_code"] ?? null) && $sgMeta["type_code"] !== "", "share_generate.meta_json.type_code missing");
$must(is_string($sgMeta["engine_version"] ?? ($sgMeta["engine"] ?? null)) && ($sgMeta["engine_version"] ?? ($sgMeta["engine"] ?? "")) !== "", "share_generate engine_version/engine missing");
$must(is_string($sgMeta["content_package_version"] ?? null) && $sgMeta["content_package_version"] !== "", "share_generate.meta_json.content_package_version missing");

$must((bool)$sc, "missing share_click");
$must(($scMeta["share_id"] ?? null) === $shareId, "share_click.meta_json.share_id mismatch");
$must(($scMeta["attempt_id"] ?? null) === $att, "share_click.meta_json.attempt_id mismatch");

dump([
  "OK" => true,
  "attempt_id" => $att,
  "share_id" => $shareId,
  "result_view" => [
    "occurred_at" => $rv->occurred_at ?? null,
    "type_code" => $rvMeta["type_code"] ?? null,
    "engine_version" => $rvMeta["engine_version"] ?? null,
    "content_package_version" => $rvMeta["content_package_version"] ?? null,
    "cache" => $rvMeta["cache"] ?? null,
    "refresh" => $rvMeta["refresh"] ?? null,
  ],
  "share_generate" => [
    "occurred_at" => $sg->occurred_at ?? null,
    "share_id" => $sgMeta["share_id"] ?? null,
    "type_code" => $sgMeta["type_code"] ?? null,
    "engine_version_or_engine" => $sgMeta["engine_version"] ?? ($sgMeta["engine"] ?? null),
    "content_package_version" => $sgMeta["content_package_version"] ?? null,
  ],
  "share_click" => [
    "occurred_at" => $sc->occurred_at ?? null,
    "anon_id" => $sc->anon_id ?? null,
    "share_id" => $scMeta["share_id"] ?? null,
    "attempt_id" => $scMeta["attempt_id"] ?? null,
  ],
]);
'

echo "[ACCEPT] PASS ✅"
