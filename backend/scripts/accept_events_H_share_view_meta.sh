#!/usr/bin/env bash
set -euo pipefail

need_cmd() { command -v "$1" >/dev/null 2>&1 || { echo "[ACCEPT_H][FAIL] missing cmd: $1" >&2; exit 2; }; }
need_cmd curl
need_cmd php

SCRIPT_DIR="$(cd "$(dirname "${BASH_SOURCE[0]}")" && pwd)"
BACKEND_DIR="$(cd "$SCRIPT_DIR/.." && pwd)"
REPO_DIR="$(cd "$BACKEND_DIR/.." && pwd)"
cd "$BACKEND_DIR"

API="${API:-http://127.0.0.1:1827}"

# -----------------------------
# Resolve SQLITE_DB to ABS path
# -----------------------------
SQLITE_DB_IN="${SQLITE_DB:-$BACKEND_DIR/database/database.sqlite}"

resolve_abs() {
  local p="$1"
  php -r '
$repo = $argv[2];
$backend = $argv[3];
$p = $argv[1];
if ($p === "") { echo ""; exit(0); }
if ($p[0] === "/") { echo $p; exit(0); }
$repoPath = $repo ? ($repo . "/" . $p) : $p;
if ($repo && file_exists($repoPath)) { echo realpath($repoPath); exit(0); }
$backendPath = $backend ? ($backend . "/" . $p) : $p;
if ($backend && file_exists($backendPath)) { echo realpath($backendPath); exit(0); }
$rp = realpath($p);
echo $rp ? $rp : $p;
' "$p" "$REPO_DIR" "$BACKEND_DIR"
}

SQLITE_DB_ABS="$(resolve_abs "$SQLITE_DB_IN")"

# -----------------------------
# Artifacts
# -----------------------------
RUN_DIR="${RUN_DIR:-$BACKEND_DIR/artifacts/verify_mbti}"
SHARE_JSON="$RUN_DIR/share.json"
TMP_RESP="$RUN_DIR/_accept_H_share_resp.json"

echo "[ACCEPT_H] repo=$REPO_DIR"
echo "[ACCEPT_H] backend=$BACKEND_DIR"
echo "[ACCEPT_H] API=$API"
echo "[ACCEPT_H] SQLITE_DB(in)=$SQLITE_DB_IN"
echo "[ACCEPT_H] SQLITE_DB(abs)=$SQLITE_DB_ABS"
echo "[ACCEPT_H] RUN_DIR=$RUN_DIR"

if [[ ! -f "$SHARE_JSON" ]]; then
  echo "[ACCEPT_H][FAIL] share.json not found: $SHARE_JSON" >&2
  exit 2
fi

# share.json must be valid JSON with ok=true
if ! php -r '$j=json_decode(@file_get_contents($argv[1]), true); if (!is_array($j) || !($j["ok"] ?? false)) { exit(1); }' "$SHARE_JSON" >/dev/null 2>&1; then
  echo "[ACCEPT_H][FAIL] share.json is not ok=true (maybe HTML/500). head:" >&2
  head -n 40 "$SHARE_JSON" >&2 || true
  exit 2
fi

ATT="$(php -r '$j=json_decode(@file_get_contents($argv[1]), true); echo $j["attempt_id"] ?? "";' "$SHARE_JSON" 2>/dev/null || true)"
SHARE_ID_OLD="$(php -r '$j=json_decode(@file_get_contents($argv[1]), true); echo $j["share_id"] ?? "";' "$SHARE_JSON" 2>/dev/null || true)"

if [[ -z "$ATT" ]]; then
  echo "[ACCEPT_H][FAIL] missing attempt_id in $SHARE_JSON" >&2
  echo "---- $SHARE_JSON ----" >&2
  cat "$SHARE_JSON" >&2 || true
  exit 2
fi

echo "[ACCEPT_H] ATT=$ATT"
echo "[ACCEPT_H] share_id(from_artifacts)=$SHARE_ID_OLD"

# -----------------------------
# Trigger share_view (share page exposure)
# IMPORTANT: Do NOT discard response; use share_id from THIS response
# -----------------------------
curl -fsS \
  -H "X-Experiment: H_accept" \
  -H "X-App-Version: 1.2.3-accept" \
  -H "X-Channel: miniapp" \
  -H "X-Client-Platform: wechat" \
  -H "X-Entry-Page: share_page" \
  "$API/api/v0.2/attempts/$ATT/share" >"$TMP_RESP"

if ! php -r '$j=json_decode(@file_get_contents($argv[1]), true); if (!is_array($j) || !($j["ok"] ?? false)) { exit(1); }' "$TMP_RESP" >/dev/null 2>&1; then
  echo "[ACCEPT_H][FAIL] /share response ok!=true. head:" >&2
  head -n 60 "$TMP_RESP" >&2 || true
  exit 2
fi

SHARE_ID_RESP="$(php -r '$j=json_decode(@file_get_contents($argv[1]), true); echo $j["share_id"] ?? "";' "$TMP_RESP" 2>/dev/null || true)"
if [[ -z "$SHARE_ID_RESP" ]]; then
  echo "[ACCEPT_H][FAIL] missing share_id in /share response: $TMP_RESP" >&2
  cat "$TMP_RESP" >&2 || true
  exit 2
fi

echo "[ACCEPT_H] share_id(from_api)=$SHARE_ID_RESP"

# -----------------------------
# Assert in DB (force same sqlite as server)
# -----------------------------
DB_CONNECTION=sqlite DB_DATABASE="$SQLITE_DB_ABS" \
ATT="$ATT" SHARE_ID="$SHARE_ID_RESP" \
php artisan tinker --execute='
use App\Models\Event;

$att = getenv("ATT");
$shareId = getenv("SHARE_ID");

$e = Event::where("event_code","share_view")
  ->where("attempt_id",$att)
  ->orderByDesc("occurred_at")
  ->first();

if (!$e) throw new RuntimeException("FAIL: share_view not found (ATT=$att).");

$meta = $e->meta_json;
if (is_string($meta) && $meta !== "") $meta = json_decode($meta, true);
if (!is_array($meta)) $meta = [];

$expect = [
  "experiment" => "H_accept",
  "version" => "1.2.3-accept",
  "channel" => "miniapp",
  "client_platform" => "wechat",
  "entry_page" => "share_page",
  "share_id" => $shareId,
];

foreach ($expect as $k=>$v) {
  $got = $meta[$k] ?? null;
  if ($got !== $v) {
    throw new RuntimeException("FAIL: share_view.$k mismatch (got=".var_export($got,true).", want=".var_export($v,true).", ATT=$att, SHARE_ID=$shareId)");
  }
}

dump([
  "driver" => config("database.default"),
  "db" => config("database.connections.sqlite.database"),
  "occurred_at" => (string)$e->occurred_at,
  "share_view" => $expect,
]);

echo "[ACCEPT_H] PASS ✅\n";
'
