#!/usr/bin/env bash
set -euo pipefail

ROOT_DIR=$(cd "$(dirname "${BASH_SOURCE[0]}")/../.." && pwd)
BACKEND_DIR="$ROOT_DIR/backend"
ENV_FILE="${ENV_FILE:-$BACKEND_DIR/.env}"

get_env() {
  local key="$1"
  if [[ -n "${!key:-}" ]]; then
    echo "${!key}"
    return
  fi
  if [[ -f "$ENV_FILE" ]]; then
    local line
    line=$(grep -E "^${key}=" "$ENV_FILE" | tail -n 1 || true)
    line=${line#${key}=}
    line=${line%\"}
    line=${line#\"}
    line=${line%\'}
    line=${line#\'}
    echo "$line"
    return
  fi
  echo ""
}

TOKEN=$(get_env FAP_ADMIN_TOKEN)
if [[ -z "$TOKEN" ]]; then
  echo "FAIL: Missing FAP_ADMIN_TOKEN in $ENV_FILE"
  exit 1
fi

PORT=${PORT:-18010}
HOST=${HOST:-127.0.0.1}

cd "$BACKEND_DIR"

cleanup() {
  if [[ -n "${SERVE_PID:-}" ]]; then
    kill "$SERVE_PID" >/dev/null 2>&1 || true
  fi
}
trap cleanup EXIT

PORT=$PORT php artisan serve --host="$HOST" --port="$PORT" >/tmp/pr10_admin_serve.log 2>&1 &
SERVE_PID=$!

sleep 2

BASE_URL="http://${HOST}:${PORT}"

curl -sS -H "X-FAP-Admin-Token: ${TOKEN}" "$BASE_URL/api/v0.3/admin/healthz/snapshot" >/tmp/pr10_admin_healthz.json
curl -sS -H "X-FAP-Admin-Token: ${TOKEN}" "$BASE_URL/api/v0.3/admin/audit-logs" >/tmp/pr10_admin_audit.json

if ! grep -Eq '\"ok\"[[:space:]]*:[[:space:]]*true' /tmp/pr10_admin_healthz.json; then
  echo "FAIL: healthz snapshot not ok"
  cat /tmp/pr10_admin_healthz.json
  exit 1
fi

if ! grep -Eq '\"ok\"[[:space:]]*:[[:space:]]*true' /tmp/pr10_admin_audit.json; then
  echo "FAIL: audit logs not ok"
  cat /tmp/pr10_admin_audit.json
  exit 1
fi

php artisan admin:bootstrap-owner --email=owner@example.com --password=owner12345 --name=Owner

AUDIT_COUNT=$(php artisan tinker --execute="echo \DB::table('audit_logs')->count();")
if [[ "${AUDIT_COUNT}" -le 0 ]]; then
  echo "FAIL: audit_logs count is 0"
  exit 1
fi

echo "OK: admin endpoints + audit logs"
exit 0
