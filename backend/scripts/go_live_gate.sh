#!/usr/bin/env bash
set -euo pipefail

status="PASS"

fail() {
  local msg="$1"
  status="STOP-SHIP"
  echo "[FAIL] $msg"
}

pass() {
  local msg="$1"
  echo "[PASS] $msg"
}

require_true() {
  local key="$1"
  local value="${!key:-false}"
  if [[ "$value" == "true" || "$value" == "1" ]]; then
    pass "$key"
  else
    fail "$key"
  fi
}

require_non_empty() {
  local key="$1"
  local value="${!key:-}"
  if [[ -n "$value" ]]; then
    pass "$key"
  else
    fail "$key"
  fi
}

echo "== Commerce / Stripe =="
if [[ "${STRIPE_SECRET:-}" == sk_live_* ]]; then
  pass "STRIPE_SECRET live key"
else
  fail "STRIPE_SECRET must start with sk_live_"
fi

require_non_empty "STRIPE_WEBHOOK_SECRET"
require_true "OPS_GATE_PAYMENT_REFUND_DRILL_OK"

echo "== SRE / DevOps =="
if [[ "${APP_DEBUG:-false}" == "false" || "${APP_DEBUG:-0}" == "0" ]]; then
  pass "APP_DEBUG=false"
else
  fail "APP_DEBUG must be false"
fi

if [[ "${QUEUE_CONNECTION:-sync}" != "sync" ]]; then
  pass "QUEUE_CONNECTION != sync"
else
  fail "QUEUE_CONNECTION cannot be sync"
fi

require_true "OPS_GATE_DB_RESTORE_DRILL_OK"
require_true "OPS_GATE_LOG_ROTATION_OK"

echo "== Compliance / Comm =="
require_non_empty "MAIL_HOST"
require_true "OPS_GATE_SPF_DKIM_DMARC_OK"
require_true "OPS_GATE_LEGAL_PAGES_OK"

echo "== Growth / Observability =="
if [[ -n "${SENTRY_LARAVEL_DSN:-${SENTRY_DSN:-}}" ]]; then
  pass "backend sentry dsn"
else
  fail "backend sentry dsn missing"
fi

require_non_empty "VITE_SENTRY_DSN"
require_true "OPS_GATE_CONVERSION_TRACKING_OK"
require_true "OPS_GATE_GSC_SITEMAP_OK"

echo "== Result =="
echo "$status"
if [[ "$status" == "STOP-SHIP" ]]; then
  exit 2
fi
