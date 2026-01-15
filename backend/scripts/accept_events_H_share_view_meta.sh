#!/usr/bin/env bash
set -euo pipefail

SCRIPT_DIR="$(cd "$(dirname "${BASH_SOURCE[0]}")" && pwd)"
BACKEND_DIR="$(cd "$SCRIPT_DIR/.." && pwd)"
REPO_DIR="$(cd "$BACKEND_DIR/.." && pwd)"
cd "$BACKEND_DIR"

API="${API:-http://127.0.0.1:18000}"

# -----------------------------
# Resolve SQLITE_DB to ABS path
# -----------------------------
SQLITE_DB_IN="${SQLITE_DB:-$BACKEND_DIR/database/database.sqlite}"

resolve_abs() {
  local p="$1"
  if [[ "$p" = /* ]]; then
    echo "$p"
    return 0
  fi

  # repo-root relative
  if [[ -f "$REPO_DIR/$p" ]]; then
    python3 -c 'import os,sys; print(os.path.abspath(os.path.join(sys.argv[1], sys.argv[2])))' \
      "$REPO_DIR" "$p"
    return 0
  fi

  # backend-dir relative
  if [[ -f "$BACKEND_DIR/$p" ]]; then
    python3 -c 'import os,sys; print(os.path.abspath(os.path.join(sys.argv[1], sys.argv[2])))' \
      "$BACKEND_DIR" "$p"
    return 0
  fi

  # last resort: cwd absolute
  python3 -c 'import os,sys; print(os.path.abspath(sys.argv[1]))' "$p"
}

SQLITE_DB_ABS="$(resolve_abs "$SQLITE_DB_IN")"

# -----------------------------
# Artifacts
# -----------------------------
RUN_DIR="${RUN_DIR:-$BACKEND_DIR/artifacts/verify_mbti}"
SHARE_JSON="$RUN_DIR/share.json"

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
if ! jq -e '.ok==true' "$SHARE_JSON" >/dev/null 2>&1; then
  echo "[ACCEPT_H][FAIL] share.json is not ok=true (maybe HTML/500). head:" >&2
  head -n 40 "$SHARE_JSON" >&2 || true
  exit 2
fi

ATT="$(jq -r '.attempt_id // empty' "$SHARE_JSON")"
SHARE_ID="$(jq -r '.share_id // empty' "$SHARE_JSON")"

if [[ -z "$ATT" || -z "$SHARE_ID" ]]; then
  echo "[ACCEPT_H][FAIL] missing attempt_id/share_id in $SHARE_JSON" >&2
  echo "---- $SHARE_JSON ----" >&2
  cat "$SHARE_JSON" >&2 || true
  exit 2
fi

echo "[ACCEPT_H] ATT=$ATT"
echo "[ACCEPT_H] SHARE_ID=$SHARE_ID"

# -----------------------------
# Trigger share_view (share page exposure)
# -----------------------------
curl -fsS \
  -H "X-Experiment: H_accept" \
  -H "X-App-Version: 1.2.3-accept" \
  -H "X-Channel: miniapp" \
  -H "X-Client-Platform: wechat" \
  -H "X-Entry-Page: share_page" \
  "$API/api/v0.2/attempts/$ATT/share" >/dev/null

# -----------------------------
# Assert in DB (force same sqlite as server)
# -----------------------------
DB_CONNECTION=sqlite DB_DATABASE="$SQLITE_DB_ABS" \
ATT="$ATT" SHARE_ID="$SHARE_ID" \
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