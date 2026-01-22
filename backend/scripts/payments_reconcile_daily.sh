#!/usr/bin/env bash
set -euo pipefail

# payments_reconcile_daily.sh
# Minimal daily reconciliation for payments (orders/payment_events).
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
#   JSON to stdout (grep-friendly), optional artifact file when WRITE_ARTIFACT=1.

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

PHP_CODE='
use Illuminate\Support\Carbon;

$tz = getenv("RECON_TZ") ?: "Asia/Shanghai";
$date = getenv("DATE") ?: Carbon::now($tz)->format("Y-m-d");
$start = Carbon::createFromFormat("Y-m-d", $date, $tz)->startOfDay();
$end = (clone $start)->addDay();

if (!\Schema::hasTable("orders")) {
  fwrite(STDERR, "[RECON][ERR] missing table: orders\n");
  exit(2);
}
if (!\Schema::hasTable("payment_events")) {
  fwrite(STDERR, "[RECON][ERR] missing table: payment_events\n");
  exit(2);
}

$ordersPaid = \DB::table("orders")
  ->whereNotNull("paid_at")
  ->where("paid_at", ">=", $start)
  ->where("paid_at", "<", $end);

$ordersRefunded = \DB::table("orders")
  ->whereNotNull("refunded_at")
  ->where("refunded_at", ">=", $start)
  ->where("refunded_at", "<", $end);

$ordersFulfilled = \DB::table("orders")
  ->whereNotNull("fulfilled_at")
  ->where("fulfilled_at", ">=", $start)
  ->where("fulfilled_at", "<", $end);

$ordersByStatus = (clone $ordersPaid)
  ->select("status", \DB::raw("count(*) as cnt"))
  ->groupBy("status")
  ->orderByDesc("cnt")
  ->get();

$paidCount = (clone $ordersPaid)->count();
$paidAmount = (int) (clone $ordersPaid)->sum("amount_total");

$fulfilledCount = (clone $ordersFulfilled)->count();

$refundCount = (clone $ordersRefunded)->count();
$refundAmount = (int) (clone $ordersRefunded)->sum("amount_refunded");

$netRevenue = $paidAmount - $refundAmount;

$eventsQuery = \DB::table("payment_events")
  ->where("created_at", ">=", $start)
  ->where("created_at", "<", $end);

$eventsTotal = (clone $eventsQuery)->count();
$eventsByType = (clone $eventsQuery)
  ->select("event_type", \DB::raw("count(*) as cnt"))
  ->groupBy("event_type")
  ->orderByDesc("cnt")
  ->get();

$out = [
  "date" => $date,
  "timezone" => $tz,
  "window" => [
    "start" => $start->toDateTimeString(),
    "end" => $end->toDateTimeString(),
  ],
  "orders_by_status" => $ordersByStatus,
  "success_paid" => [
    "count" => $paidCount,
    "amount_total" => $paidAmount,
  ],
  "success_fulfilled" => [
    "count" => $fulfilledCount,
  ],
  "refunds" => [
    "count" => $refundCount,
    "amount_total" => $refundAmount,
  ],
  "net_revenue" => $netRevenue,
  "payment_events" => [
    "total" => $eventsTotal,
    "by_type" => $eventsByType,
  ],
];

echo json_encode($out, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES) . PHP_EOL;
';

if [[ "$DB_CONNECTION" == "sqlite" ]]; then
  OUTPUT="$(APP_ENV="$APP_ENV" DB_CONNECTION="$DB_CONNECTION" DB_DATABASE="$SQLITE_DB" \
    DATE="$DATE" RECON_TZ="$RECON_TZ" \
    php artisan tinker --execute="$PHP_CODE")"
else
  OUTPUT="$(APP_ENV="$APP_ENV" DB_CONNECTION="$DB_CONNECTION" \
    DATE="$DATE" RECON_TZ="$RECON_TZ" \
    php artisan tinker --execute="$PHP_CODE")"
fi

echo "$OUTPUT"

if [[ "$WRITE_ARTIFACT" == "1" ]]; then
  mkdir -p "$ARTIFACT_DIR"
  ARTIFACT_PATH="$ARTIFACT_DIR/reconcile_${DATE}.json"
  printf "%s\n" "$OUTPUT" > "$ARTIFACT_PATH"
  echo "[RECON] artifact=$ARTIFACT_PATH"
fi
