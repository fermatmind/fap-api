#!/usr/bin/env bash
set -euo pipefail

BACKEND_DIR="$(cd "$(dirname "${BASH_SOURCE[0]}")/.." && pwd)"
REPO_DIR="$(cd "${BACKEND_DIR}/.." && pwd)"

API="${API:-http://127.0.0.1:18000}"
SQLITE_DB="${SQLITE_DB:-${BACKEND_DIR}/database/database.sqlite}"
LOOKUP_ORDER="${LOOKUP_ORDER:-0}"

echo "[ACCEPT_ORDER] repo=${REPO_DIR}"
echo "[ACCEPT_ORDER] backend=${BACKEND_DIR}"
echo "[ACCEPT_ORDER] API=${API}"
echo "[ACCEPT_ORDER] SQLITE_DB=${SQLITE_DB}"
echo "[ACCEPT_ORDER] LOOKUP_ORDER=${LOOKUP_ORDER}"

if [[ "${LOOKUP_ORDER}" != "1" ]]; then
  echo "[ACCEPT_ORDER][FAIL] LOOKUP_ORDER must be enabled (set LOOKUP_ORDER=1 before starting server)"
  exit 1
fi

# 0) health
curl -sS "${API}/api/v0.2/health" >/dev/null

# 1) detect table/column/order_no
INFO_OUT="$(cd "${BACKEND_DIR}" && php artisan tinker --execute='
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Facades\DB;

$table = "";
if (Schema::hasTable("orders")) $table = "orders";
elseif (Schema::hasTable("payments")) $table = "payments";

if ($table === "") {
  echo "TABLE=\n";
  echo "COLUMN=\n";
  echo "ORDER_NO=\n";
  return;
}

$column = null;
$candidates = ["order_no","order_id","order_number","order_sn"];
foreach ($candidates as $col) {
  if (Schema::hasColumn($table, $col)) { $column = $col; break; }
}

echo "TABLE={$table}\n";
echo "COLUMN={$column}\n";

if ($column === null) {
  echo "ORDER_NO=\n";
  return;
}

$row = DB::table($table)->select([$column])->orderByDesc($column)->first();
if (!$row) { echo "ORDER_NO=\n"; return; }

$val = trim((string) ($row->{$column} ?? ""));
echo "ORDER_NO={$val}\n";
')"

TABLE_NAME="$(printf "%s" "${INFO_OUT}" | sed -n 's/^TABLE=//p' | tail -n 1)"
COLUMN_NAME="$(printf "%s" "${INFO_OUT}" | sed -n 's/^COLUMN=//p' | tail -n 1)"
ORDER_NO="$(printf "%s" "${INFO_OUT}" | sed -n 's/^ORDER_NO=//p' | tail -n 1)"

PAYLOAD_ORDER_NO="${ORDER_NO:-TEST-ORDER-001}"

RESP="$(curl -sS -X POST "${API}/api/v0.2/lookup/order" \
  -H "Content-Type: application/json" -H "Accept: application/json" \
  -d "{\"order_no\":\"${PAYLOAD_ORDER_NO}\"}")"

if [[ -z "${TABLE_NAME}" || -z "${COLUMN_NAME}" ]]; then
  if ! php -r '$j=json_decode(stream_get_contents(STDIN), true); exit((int) !(($j["ok"] ?? true) === false && ($j["error"] ?? "") === "NOT_SUPPORTED"));' <<<"${RESP}"; then
    echo "[ACCEPT_ORDER][FAIL] expect NOT_SUPPORTED: ${RESP}"
    exit 1
  fi
  echo "[ACCEPT_ORDER] NOT_SUPPORTED OK"
  exit 0
fi

if [[ -z "${ORDER_NO}" ]]; then
  if ! php -r '$j=json_decode(stream_get_contents(STDIN), true); exit((int) !(($j["ok"] ?? true) === false && ($j["error"] ?? "") === "NOT_FOUND"));' <<<"${RESP}"; then
    echo "[ACCEPT_ORDER][FAIL] expect NOT_FOUND: ${RESP}"
    exit 1
  fi
  echo "[ACCEPT_ORDER] NOT_FOUND OK (no rows)"
  exit 0
fi

if ! php -r '$j=json_decode(stream_get_contents(STDIN), true); exit((int) !($j["ok"] ?? false));' <<<"${RESP}"; then
  echo "[ACCEPT_ORDER][FAIL] expect ok=true: ${RESP}"
  exit 1
fi

echo "[ACCEPT_ORDER] DONE OK"
