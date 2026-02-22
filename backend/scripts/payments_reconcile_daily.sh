#!/usr/bin/env bash
set -euo pipefail

# payments_reconcile_daily.sh
# Daily reconciliation for payments via commerce:reconcile command.
#
# Env:
#   RECONCILIATION_ENABLED=0|1  (default 0; 0 -> skip exit 0)
#   DATE=YYYY-MM-DD             (optional; default today in TZ)
#   DB_CONNECTION=sqlite|mysql|pgsql (optional; default sqlite)
#   SQLITE_DB=/abs/path/to/backend/database/database.sqlite (optional)
#   APP_ENV=testing|production  (optional; default testing)
#   WRITE_ARTIFACT=0|1          (optional; default 0)
#   ARTIFACT_DIR=...            (optional; default backend/artifacts/payments)
#
# Output:
#   JSON to stdout (single-line), optional artifact file when WRITE_ARTIFACT=1.

need_cmd() { command -v "$1" >/dev/null 2>&1 || { echo "[ERR] missing cmd: $1" >&2; exit 2; }; }
need_cmd php

REPO_DIR="$(cd "$(dirname "${BASH_SOURCE[0]}")/../.." && pwd)"
BACKEND_DIR="$REPO_DIR/backend"
cd "$BACKEND_DIR"

RECONCILIATION_ENABLED="${RECONCILIATION_ENABLED:-0}"
if [[ "$RECONCILIATION_ENABLED" != "1" ]]; then
  echo "[RECON] RECONCILIATION_ENABLED=0 -> skip"
  exit 0
fi

RECON_TZ="Asia/Shanghai"
DATE="${DATE:-$(TZ="$RECON_TZ" date +%F)}"
APP_ENV="${APP_ENV:-testing}"
DB_CONNECTION="${DB_CONNECTION:-sqlite}"
SQLITE_DB="${SQLITE_DB:-$BACKEND_DIR/database/database.sqlite}"
WRITE_ARTIFACT="${WRITE_ARTIFACT:-0}"
ARTIFACT_DIR="${ARTIFACT_DIR:-$REPO_DIR/backend/artifacts/payments}"

echo "[RECON] repo=$REPO_DIR"
echo "[RECON] backend=$BACKEND_DIR"
echo "[RECON] date=$DATE tz=$RECON_TZ"
echo "[RECON] APP_ENV=$APP_ENV DB_CONNECTION=$DB_CONNECTION"
echo "[RECON] SQLITE_DB=$SQLITE_DB"

if [[ "$DB_CONNECTION" == "sqlite" && ! -f "$SQLITE_DB" ]]; then
  echo "[ERR] sqlite db not found: $SQLITE_DB" >&2
  exit 1
fi

if [[ "$DB_CONNECTION" == "sqlite" ]]; then
  OUTPUT="$(APP_ENV="$APP_ENV" DB_CONNECTION="$DB_CONNECTION" DB_DATABASE="$SQLITE_DB" \
    php artisan commerce:reconcile --date="$DATE" --org_id=0 --json=1 | tail -n 1)"
else
  OUTPUT="$(APP_ENV="$APP_ENV" DB_CONNECTION="$DB_CONNECTION" \
    php artisan commerce:reconcile --date="$DATE" --org_id=0 --json=1 | tail -n 1)"
fi

echo "$OUTPUT"

if [[ "$WRITE_ARTIFACT" == "1" ]]; then
  mkdir -p "$ARTIFACT_DIR"
  ARTIFACT_PATH="$ARTIFACT_DIR/reconcile_${DATE}.json"
  printf "%s\n" "$OUTPUT" > "$ARTIFACT_PATH"
  echo "[RECON] artifact=$ARTIFACT_PATH"
fi
