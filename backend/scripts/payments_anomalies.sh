#!/usr/bin/env bash
set -euo pipefail

# payments_anomalies.sh
# Minimal anomaly scan for payments (orders/benefit_grants/payment_events).
#
# Env:
#   RECONCILIATION_ENABLED=0|1  (default 0; 0 -> skip exit 0)
#   DATE=YYYY-MM-DD             (optional; default today in TZ)
#   ANOMALY_FULFILL_LAG_MINUTES (optional; default 30)
#   ANOMALY_MAX_LIST            (optional; default 200)
#   DB_CONNECTION=sqlite|mysql|pgsql (optional; default sqlite)
#   SQLITE_DB=/abs/path/to/backend/database/database.sqlite (optional)
#   APP_ENV=testing|production  (optional; default testing)
#   WRITE_ARTIFACT=0|1          (optional; default 0)
#   ARTIFACT_DIR=...            (optional; default backend/artifacts/payments)
#
# Output:
#   JSON to stdout (grep-friendly), optional artifact file when WRITE_ARTIFACT=1.

need_cmd() { command -v "$1" >/dev/null 2>&1 || { echo "[ERR] missing cmd: $1" >&2; exit 2; }; }
need_cmd php

REPO_DIR="$(cd "$(dirname "${BASH_SOURCE[0]}")/../.." && pwd)"
BACKEND_DIR="$REPO_DIR/backend"
cd "$BACKEND_DIR"

RECONCILIATION_ENABLED="${RECONCILIATION_ENABLED:-0}"
if [[ "$RECONCILIATION_ENABLED" != "1" ]]; then
  echo "[ANOMALY] RECONCILIATION_ENABLED=0 -> skip"
  exit 0
fi

RECON_TZ="Asia/Shanghai"
DATE="${DATE:-$(TZ="$RECON_TZ" date +%F)}"
APP_ENV="${APP_ENV:-testing}"
DB_CONNECTION="${DB_CONNECTION:-sqlite}"
SQLITE_DB="${SQLITE_DB:-$BACKEND_DIR/database/database.sqlite}"
ANOMALY_FULFILL_LAG_MINUTES="${ANOMALY_FULFILL_LAG_MINUTES:-30}"
ANOMALY_MAX_LIST="${ANOMALY_MAX_LIST:-200}"
WRITE_ARTIFACT="${WRITE_ARTIFACT:-0}"
ARTIFACT_DIR="${ARTIFACT_DIR:-$REPO_DIR/backend/artifacts/payments}"

echo "[ANOMALY] repo=$REPO_DIR"
echo "[ANOMALY] backend=$BACKEND_DIR"
echo "[ANOMALY] date=$DATE tz=$RECON_TZ"
echo "[ANOMALY] fulfill_lag_minutes=$ANOMALY_FULFILL_LAG_MINUTES max_list=$ANOMALY_MAX_LIST"
echo "[ANOMALY] APP_ENV=$APP_ENV DB_CONNECTION=$DB_CONNECTION"
echo "[ANOMALY] SQLITE_DB=$SQLITE_DB"

if [[ "$DB_CONNECTION" == "sqlite" && ! -f "$SQLITE_DB" ]]; then
  echo "[ERR] sqlite db not found: $SQLITE_DB" >&2
  exit 1
fi

PHP_CODE='
use Illuminate\Support\Carbon;

$tz = getenv("RECON_TZ") ?: "Asia/Shanghai";
$date = getenv("DATE") ?: Carbon::now($tz)->format("Y-m-d");
$start = Carbon::createFromFormat("Y-m-d", $date, $tz)->startOfDay();
$end = (clone $start)->addDay();
$lagMinutes = (int) (getenv("ANOMALY_FULFILL_LAG_MINUTES") ?: 30);
$maxList = (int) (getenv("ANOMALY_MAX_LIST") ?: 200);
$cutoff = Carbon::now($tz)->subMinutes($lagMinutes);

if (!\Schema::hasTable("orders")) {
  fwrite(STDERR, "[ANOMALY][ERR] missing table: orders\n");
  exit(2);
}
if (!\Schema::hasTable("benefit_grants")) {
  fwrite(STDERR, "[ANOMALY][ERR] missing table: benefit_grants\n");
  exit(2);
}
if (!\Schema::hasTable("payment_events")) {
  fwrite(STDERR, "[ANOMALY][ERR] missing table: payment_events\n");
  exit(2);
}

$paidNoGrantQ = \DB::table("orders")
  ->leftJoin("benefit_grants", "benefit_grants.source_order_id", "=", "orders.id")
  ->where("orders.status", "paid")
  ->whereNull("benefit_grants.id")
  ->whereNotNull("orders.paid_at")
  ->where("orders.paid_at", ">=", $start)
  ->where("orders.paid_at", "<", $end);

$paidNoGrantTotal = (clone $paidNoGrantQ)->count();
$paidNoGrantItems = (clone $paidNoGrantQ)
  ->orderBy("orders.paid_at")
  ->limit($maxList)
  ->get([
    "orders.id as order_id",
    "orders.user_id",
    "orders.anon_id",
    "orders.paid_at",
    "orders.amount_total",
    "orders.request_id",
    "orders.status",
  ]);

$paidNoFulfillQ = \DB::table("orders")
  ->where("status", "paid")
  ->whereNull("fulfilled_at")
  ->whereNotNull("paid_at")
  ->where("paid_at", ">=", $start)
  ->where("paid_at", "<", $end)
  ->where("paid_at", "<=", $cutoff);

$paidNoFulfillTotal = (clone $paidNoFulfillQ)->count();
$paidNoFulfillItems = (clone $paidNoFulfillQ)
  ->orderBy("paid_at")
  ->limit($maxList)
  ->get([
    "id as order_id",
    "user_id",
    "anon_id",
    "paid_at",
    "amount_total",
    "request_id",
    "status",
  ]);

$grantWithoutPaidQ = \DB::table("benefit_grants")
  ->leftJoin("orders", "orders.id", "=", "benefit_grants.source_order_id")
  ->where("benefit_grants.created_at", ">=", $start)
  ->where("benefit_grants.created_at", "<", $end)
  ->where(function ($q) {
    $q->whereNull("orders.id")
      ->orWhereNotIn("orders.status", ["paid", "fulfilled", "gifted"]);
  });

$grantWithoutPaidTotal = (clone $grantWithoutPaidQ)->count();
$grantWithoutPaidItems = (clone $grantWithoutPaidQ)
  ->orderBy("benefit_grants.created_at")
  ->limit($maxList)
  ->get([
    "benefit_grants.id as grant_id",
    "benefit_grants.source_order_id as order_id",
    "benefit_grants.benefit_type",
    "benefit_grants.benefit_ref",
    "benefit_grants.created_at",
    "orders.status as order_status",
    "orders.request_id as order_request_id",
  ]);

$badSigCount = \DB::table("payment_events")
  ->where("signature_ok", false)
  ->where("created_at", ">=", $start)
  ->where("created_at", "<", $end)
  ->count();

$out = [
  "date" => $date,
  "timezone" => $tz,
  "window" => [
    "start" => $start->toDateTimeString(),
    "end" => $end->toDateTimeString(),
  ],
  "fulfill_lag_minutes" => $lagMinutes,
  "cutoff_at" => $cutoff->toDateTimeString(),
  "max_list" => $maxList,
  "paid_no_grant" => [
    "total" => $paidNoGrantTotal,
    "items" => $paidNoGrantItems,
  ],
  "paid_no_fulfill_overdue" => [
    "total" => $paidNoFulfillTotal,
    "items" => $paidNoFulfillItems,
  ],
  "grant_without_paid" => [
    "total" => $grantWithoutPaidTotal,
    "items" => $grantWithoutPaidItems,
  ],
  "payment_events_signature_bad" => [
    "total" => $badSigCount,
  ],
];

echo json_encode($out, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES) . PHP_EOL;
';

if [[ "$DB_CONNECTION" == "sqlite" ]]; then
  OUTPUT="$(APP_ENV="$APP_ENV" DB_CONNECTION="$DB_CONNECTION" DB_DATABASE="$SQLITE_DB" \
    DATE="$DATE" RECON_TZ="$RECON_TZ" \
    ANOMALY_FULFILL_LAG_MINUTES="$ANOMALY_FULFILL_LAG_MINUTES" \
    ANOMALY_MAX_LIST="$ANOMALY_MAX_LIST" \
    php artisan tinker --execute="$PHP_CODE")"
else
  OUTPUT="$(APP_ENV="$APP_ENV" DB_CONNECTION="$DB_CONNECTION" \
    DATE="$DATE" RECON_TZ="$RECON_TZ" \
    ANOMALY_FULFILL_LAG_MINUTES="$ANOMALY_FULFILL_LAG_MINUTES" \
    ANOMALY_MAX_LIST="$ANOMALY_MAX_LIST" \
    php artisan tinker --execute="$PHP_CODE")"
fi

echo "$OUTPUT"

if [[ "$WRITE_ARTIFACT" == "1" ]]; then
  mkdir -p "$ARTIFACT_DIR"
  ARTIFACT_PATH="$ARTIFACT_DIR/anomalies_${DATE}.json"
  printf "%s\n" "$OUTPUT" > "$ARTIFACT_PATH"
  echo "[ANOMALY] artifact=$ARTIFACT_PATH"
fi
