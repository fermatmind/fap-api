#!/usr/bin/env bash
set -euo pipefail

# accept_events_D_anon_block_placeholder_click.sh
# Verify placeholder anon_id will NOT leak into events.share_click.anon_id
# Strategy:
# 1) Read ATT from artifacts report.json
# 2) Call /attempts/{ATT}/share to get SHARE_ID
# 3) POST /shares/{SHARE_ID}/click with BAD_ANON placeholder
# 4) Query sqlite: latest share_click for that share_id must exist and anon_id != BAD_ANON

need_cmd() { command -v "$1" >/dev/null 2>&1 || { echo "[ERR] missing cmd: $1" >&2; exit 2; }; }
need_cmd curl
need_cmd jq
need_cmd php
need_cmd sed

REPO_DIR="$(cd "$(dirname "${BASH_SOURCE[0]}")/../.." && pwd)"
BACKEND_DIR="$REPO_DIR/backend"

API="${API:-http://127.0.0.1:18000}"
SQLITE_DB="${SQLITE_DB:-$BACKEND_DIR/database/database.sqlite}"
BAD_ANON="${BAD_ANON:-把你查到的anon_id填这里}"

echo "[ACCEPT_D3] repo=$REPO_DIR"
echo "[ACCEPT_D3] backend=$BACKEND_DIR"
echo "[ACCEPT_D3] API=$API"
echo "[ACCEPT_D3] SQLITE_DB=$SQLITE_DB"
echo "[ACCEPT_D3] BAD_ANON=$BAD_ANON"

REPORT_JSON="$BACKEND_DIR/artifacts/verify_mbti/report.json"
if [[ ! -f "$REPORT_JSON" ]]; then
  echo "[ERR] missing $REPORT_JSON (run verify_mbti first)" >&2
  exit 1
fi

ATT="$(jq -r '.attempt_id // .attemptId // empty' "$REPORT_JSON" 2>/dev/null || true)"
if [[ -z "$ATT" || "$ATT" == "null" ]]; then
  echo "[ERR] cannot read ATT from $REPORT_JSON" >&2
  exit 1
fi
echo "[ACCEPT_D3] ATT=$ATT"

# /share
SHARE_RAW="$(curl -sS "$API/api/v0.2/attempts/$ATT/share" || true)"
SHARE_JSON="$(printf '%s\n' "$SHARE_RAW" | sed -n '/^{/,$p')"
SHARE_ID="$(printf '%s\n' "$SHARE_JSON" | jq -r '.share_id // .shareId // empty' 2>/dev/null || true)"
if [[ -z "$SHARE_ID" || "$SHARE_ID" == "null" ]]; then
  echo "[ERR] SHARE_ID empty. Raw response:" >&2
  printf '%s\n' "$SHARE_RAW" >&2
  exit 1
fi
echo "[ACCEPT_D3] SHARE_ID=$SHARE_ID"

# click with placeholder anon_id
curl -sS -X POST "$API/api/v0.2/shares/$SHARE_ID/click" \
  -H "Content-Type: application/json" \
  -d "{\"anon_id\":\"$BAD_ANON\"}" >/dev/null

# query sqlite: must exist and must NOT equal BAD_ANON
cd "$BACKEND_DIR"
APP_ENV=testing DB_CONNECTION=sqlite DB_DATABASE="$SQLITE_DB" \
SHARE_ID="$SHARE_ID" BAD_ANON="$BAD_ANON" \
php artisan tinker --execute='
$shareId = getenv("SHARE_ID");
$bad     = getenv("BAD_ANON");

$sc = \DB::table("events")
  ->where("event_code","share_click")
  ->whereRaw("json_extract(meta_json, \"$.share_id\") = ?", [$shareId])
  ->orderByDesc("occurred_at")
  ->first();

dump([
  "share_id" => $shareId,
  "bad" => $bad,
  "found" => (bool)$sc,
  "anon_id_col" => $sc->anon_id ?? null,
]);

if (!$sc) throw new \RuntimeException("FAIL: share_click not found");
if (($sc->anon_id ?? null) === $bad) throw new \RuntimeException("FAIL: placeholder leaked");
echo "[ACCEPT_D3] PASS ✅\n";
'