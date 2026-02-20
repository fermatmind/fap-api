#!/usr/bin/env bash
set -euo pipefail

if [[ -z "${FM_TOKEN:-}" ]]; then
  echo "[ACCEPT_G][FAIL] FM_TOKEN is required for report endpoint auth." >&2
  exit 2
fi
CURL_AUTH=(-H "Authorization: Bearer ${FM_TOKEN}")

need_cmd() { command -v "$1" >/dev/null 2>&1 || { echo "[ACCEPT_G][FAIL] missing cmd: $1" >&2; exit 2; }; }
need_cmd curl
need_cmd php

SCRIPT_DIR="$(cd "$(dirname "${BASH_SOURCE[0]}")" && pwd)"
BACKEND_DIR="$(cd "$SCRIPT_DIR/.." && pwd)"
REPO_DIR="$(cd "$BACKEND_DIR/.." && pwd)"
cd "$BACKEND_DIR"

API="${API:-http://127.0.0.1:1827}"
SQLITE_DB_IN="${SQLITE_DB:-$BACKEND_DIR/database/database.sqlite}"

RUN_DIR="${RUN_DIR:-$BACKEND_DIR/artifacts/verify_mbti}"
SHARE_JSON="$RUN_DIR/share.json"

# -----------------------------
# Normalize SQLITE_DB to absolute path (make hand-run stable)
# - accept relative paths like "backend/database/database.sqlite"
# - ensure tinker uses the exact same db file
# -----------------------------
SQLITE_DB_ABS="$SQLITE_DB_IN"
if [[ -n "$SQLITE_DB_ABS" ]]; then
  if [[ "$SQLITE_DB_ABS" != /* ]]; then
    SQLITE_DB_ABS="$REPO_DIR/$SQLITE_DB_ABS"
  fi
  SQLITE_DB_ABS="$(cd "$(dirname "$SQLITE_DB_ABS")" && pwd)/$(basename "$SQLITE_DB_ABS")"
fi

echo "[ACCEPT_G] repo=$REPO_DIR"
echo "[ACCEPT_G] backend=$BACKEND_DIR"
echo "[ACCEPT_G] API=$API"
echo "[ACCEPT_G] SQLITE_DB(in)=$SQLITE_DB_IN"
echo "[ACCEPT_G] SQLITE_DB(abs)=$SQLITE_DB_ABS"
echo "[ACCEPT_G] RUN_DIR=$RUN_DIR"

if [[ ! -f "$SHARE_JSON" ]]; then
  echo "[ACCEPT_G][FAIL] share.json not found: $SHARE_JSON" >&2
  exit 2
fi

ATT="$(php -r '$j=json_decode(@file_get_contents($argv[1]), true); echo $j["attempt_id"] ?? "";' "$SHARE_JSON" 2>/dev/null || true)"
SHARE_ID="$(php -r '$j=json_decode(@file_get_contents($argv[1]), true); echo $j["share_id"] ?? "";' "$SHARE_JSON" 2>/dev/null || true)"

if [[ -z "$ATT" || -z "$SHARE_ID" ]]; then
  echo "[ACCEPT_G][FAIL] missing attempt_id/share_id in $SHARE_JSON" >&2
  echo "---- $SHARE_JSON ----" >&2
  cat "$SHARE_JSON" >&2 || true
  exit 2
fi

echo "[ACCEPT_G] ATT=$ATT"
echo "[ACCEPT_G] SHARE_ID=$SHARE_ID"

ANON_ID="${ANON_ID:-}"
if [[ -z "$ANON_ID" && -n "$SQLITE_DB_ABS" ]]; then
  ANON_ID="$(ATT="$ATT" SQLITE_DB="$SQLITE_DB_ABS" php -r '
$att = (string) getenv("ATT");
$db = (string) getenv("SQLITE_DB");
if ($att === "" || $db === "" || !is_file($db)) {
  exit(0);
}
try {
  $pdo = new PDO("sqlite:" . $db);
  $stmt = $pdo->prepare("select anon_id from attempts where id = :id limit 1");
  $stmt->execute([":id" => $att]);
  $row = $stmt->fetch(PDO::FETCH_ASSOC);
  if (is_array($row)) {
    echo (string) ($row["anon_id"] ?? "");
  }
} catch (Throwable $e) {
  exit(0);
}
')"
fi
echo "[ACCEPT_G] ANON_ID=${ANON_ID:-<empty>}"

# Trigger report_view (include share_id in query; v0.3 does not persist share_id in report_view meta)
REPORT_HEADERS=(
  -H "X-Experiment: G_accept"
  -H "X-App-Version: 1.2.3-accept"
  -H "X-Channel: miniapp"
  -H "X-Client-Platform: wechat"
  -H "X-Entry-Page: report_page"
)
REPORT_URL="$API/api/v0.3/attempts/$ATT/report?share_id=$SHARE_ID"

curl -fsS \
  "${REPORT_HEADERS[@]}" \
  ${CURL_AUTH[@]+"${CURL_AUTH[@]}"} \
  "$REPORT_URL" >/dev/null

# ✅ 关键：强制 tinker 走同一个 sqlite 文件（避免“手跑连错库”）
DB_CONNECTION=sqlite DB_DATABASE="$SQLITE_DB_ABS" ATT="$ATT" SHARE_ID="$SHARE_ID" php artisan tinker --execute='
use App\Models\Event;

$att = getenv("ATT");
$shareId = getenv("SHARE_ID");

$driver = \DB::connection()->getDriverName();

$e = Event::where("event_code","report_view")
  ->where("attempt_id",$att)
  ->orderByDesc("occurred_at")
  ->first();
if (!$e) {
  $e = Event::query()
    ->where("event_code", "report_view")
    ->where("meta_json", "like", "%\"attempt_id\":\"{$att}\"%")
    ->orderByDesc("occurred_at")
    ->first();
}
if (!$e) {
  $e = Event::where("event_code","report_view")
    ->orderByDesc("occurred_at")
    ->first();
}

if (!$e) throw new RuntimeException("FAIL: report_view not found (ATT=$att, SHARE_ID=$shareId)");

$meta = $e->meta_json;
if (is_string($meta) && $meta !== "") $meta = json_decode($meta, true);
if (!is_array($meta)) $meta = [];

if (($meta["attempt_id"] ?? "") !== "" && ($meta["attempt_id"] ?? null) !== $att) {
  throw new RuntimeException("FAIL: report_view.attempt_id mismatch (ATT=$att, SHARE_ID=$shareId)");
}
if (empty($meta["type_code"])) {
  throw new RuntimeException("FAIL: report_view.type_code missing (ATT=$att, SHARE_ID=$shareId)");
}
dump([
  "driver" => config("database.default"),
  "db" => config("database.connections.sqlite.database"),
  "driver_name" => $driver,
  "occurred_at" => (string)$e->occurred_at,
  "channel_col" => $e->channel ?? null,
  "report_view" => [
    "attempt_id" => $meta["attempt_id"] ?? null,
    "type_code" => $meta["type_code"] ?? null,
    "locked" => $meta["locked"] ?? null,
  ],
]);

echo "[ACCEPT_G] PASS ✅\n";
'
