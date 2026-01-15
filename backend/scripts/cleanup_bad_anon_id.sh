#!/usr/bin/env bash
set -euo pipefail

# cleanup_bad_anon_id.sh
# One-off cleanup for historical bad anon_id values in events table.
#
# Strategy:
# - Find events whose anon_id contains any blacklist token
# - Default scope: event_code = share_click
# - Action: set anon_id = NULL (do NOT delete events)
#
# Usage:
#   bash backend/scripts/cleanup_bad_anon_id.sh --dry-run
#   bash backend/scripts/cleanup_bad_anon_id.sh --apply
#   bash backend/scripts/cleanup_bad_anon_id.sh --dry-run --all-events
#
# Env:
#   SQLITE_DB=/abs/path/to/backend/database/database.sqlite   (optional)
#   DB_CONNECTION=sqlite|mysql|pgsql (optional; default sqlite)
#   APP_ENV=testing (optional; default testing)

usage() {
  cat <<'EOF'
cleanup_bad_anon_id.sh

Options:
  --dry-run       Only print stats + sample rows (default)
  --apply         Apply update: set anon_id = NULL for matched rows
  --all-events    Scan all event_code (default only share_click)
  --limit N       Sample rows limit for dry-run (default 20)
  -h, --help      Show help

Environment:
  SQLITE_DB        Path to sqlite database (default: backend/database/database.sqlite)
  APP_ENV          Default: testing
  DB_CONNECTION    Default: sqlite

Examples:
  bash backend/scripts/cleanup_bad_anon_id.sh --dry-run
  bash backend/scripts/cleanup_bad_anon_id.sh --apply
  bash backend/scripts/cleanup_bad_anon_id.sh --dry-run --all-events
EOF
}

MODE="dry-run"
ALL_EVENTS="0"
LIMIT="20"

while [[ $# -gt 0 ]]; do
  case "$1" in
    --dry-run) MODE="dry-run"; shift ;;
    --apply) MODE="apply"; shift ;;
    --all-events) ALL_EVENTS="1"; shift ;;
    --limit) LIMIT="${2:-20}"; shift 2 ;;
    -h|--help) usage; exit 0 ;;
    *) echo "[ERR] unknown arg: $1" >&2; usage; exit 2 ;;
  esac
done

need_cmd() { command -v "$1" >/dev/null 2>&1 || { echo "[ERR] missing cmd: $1" >&2; exit 2; }; }
need_cmd php

REPO_DIR="$(cd "$(dirname "${BASH_SOURCE[0]}")/../.." && pwd)"
BACKEND_DIR="$REPO_DIR/backend"
cd "$BACKEND_DIR"

APP_ENV="${APP_ENV:-testing}"
DB_CONNECTION="${DB_CONNECTION:-sqlite}"
SQLITE_DB="${SQLITE_DB:-$BACKEND_DIR/database/database.sqlite}"

echo "[CLEANUP] repo=$REPO_DIR"
echo "[CLEANUP] backend=$BACKEND_DIR"
echo "[CLEANUP] mode=$MODE"
echo "[CLEANUP] all_events=$ALL_EVENTS"
echo "[CLEANUP] limit=$LIMIT"
echo "[CLEANUP] APP_ENV=$APP_ENV DB_CONNECTION=$DB_CONNECTION"
echo "[CLEANUP] SQLITE_DB=$SQLITE_DB"

if [[ "$DB_CONNECTION" == "sqlite" && ! -f "$SQLITE_DB" ]]; then
  echo "[ERR] sqlite db not found: $SQLITE_DB" >&2
  exit 1
fi

# NOTE: keep blacklist consistent with acceptance scripts
# (contains match; case-insensitive for ascii tokens)
PHP_CODE='
$allEvents = getenv("ALL_EVENTS") === "1";
$limit     = (int) (getenv("LIMIT") ?: 20);
$mode      = getenv("MODE") ?: "dry-run";

$bad = [
  "todo","placeholder","fixme","tbd",
  "把你查到的anon_id填这里",
  "把你查到的 anon_id 填这里",
  "填这里",
];

$q = \DB::table("events")->whereNotNull("anon_id");

if (!$allEvents) {
  $q->where("event_code", "share_click");
}

$q->where(function($qq) use ($bad) {
  foreach ($bad as $b) {
    $qq->orWhere("anon_id", "like", "%{$b}%");
  }
});

$total = (clone $q)->count();

$byCode = (clone $q)
  ->select("event_code", \DB::raw("count(*) as cnt"))
  ->groupBy("event_code")
  ->orderByDesc("cnt")
  ->get();

$sample = (clone $q)
  ->orderByDesc("occurred_at")
  ->limit($limit)
  ->get(["id","event_code","occurred_at","anon_id"]);

dump([
  "mode" => $mode,
  "total_matched" => $total,
  "by_event_code" => $byCode,
  "sample" => $sample,
]);

if ($mode !== "apply") {
  echo "[CLEANUP] DRY_RUN done\n";
  return;
}

if ($total <= 0) {
  echo "[CLEANUP] nothing to update\n";
  return;
}

\DB::beginTransaction();
try {
  $updated = $q->update(["anon_id" => null]);
  \DB::commit();
} catch (\Throwable $e) {
  \DB::rollBack();
  throw $e;
}

dump(["updated" => $updated]);

// recheck
$re = \DB::table("events")->whereNotNull("anon_id");
if (!$allEvents) $re->where("event_code","share_click");
$re->where(function($qq) use ($bad) {
  foreach ($bad as $b) {
    $qq->orWhere("anon_id", "like", "%{$b}%");
  }
});
$remain = $re->count();
dump(["remaining_matched" => $remain]);

if ($remain > 0) {
  throw new \RuntimeException("CLEANUP_FAIL: remaining_matched > 0");
}

echo "[CLEANUP] APPLY done ✅\n";
';

if [[ "$DB_CONNECTION" == "sqlite" ]]; then
  APP_ENV="$APP_ENV" DB_CONNECTION="$DB_CONNECTION" DB_DATABASE="$SQLITE_DB" \
  MODE="$MODE" ALL_EVENTS="$ALL_EVENTS" LIMIT="$LIMIT" \
  php artisan tinker --execute="$PHP_CODE"
else
  # For non-sqlite, use Laravel default connection settings in .env / config.
  APP_ENV="$APP_ENV" DB_CONNECTION="$DB_CONNECTION" \
  MODE="$MODE" ALL_EVENTS="$ALL_EVENTS" LIMIT="$LIMIT" \
  php artisan tinker --execute="$PHP_CODE"
fi