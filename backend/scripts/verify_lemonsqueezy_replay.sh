#!/usr/bin/env bash
set -euo pipefail

require_cmd() {
  command -v "$1" >/dev/null 2>&1 || {
    echo "[LEMON_REPLAY][FAIL] missing command: $1" >&2
    exit 1
  }
}

json_field() {
  local json_text="$1"
  local key="$2"
  php -r '$j=json_decode($argv[1], true); if (!is_array($j)) { echo ""; exit(0);} $v=$j[$argv[2]] ?? ""; if (is_scalar($v)) { echo (string)$v; }' "$json_text" "$key"
}

compute_signature() {
  local payload_file="$1"
  local secret="$2"
  php -r '$raw=file_get_contents($argv[1]); if(!is_string($raw)){fwrite(STDERR,"read payload failed\n"); exit(1);} echo hash_hmac("sha256", $raw, $argv[2]);' "$payload_file" "$secret"
}

post_payload() {
  local label="$1"
  local url="$2"
  local payload_file="$3"
  local signature="$4"

  local body_file
  body_file="$(mktemp -t lemon_replay.XXXXXX)"

  local status
  status="$(curl -sS -o "$body_file" -w "%{http_code}" -X POST "$url" -H "Content-Type: application/json" -H "X-Signature: ${signature}" --data-binary @"${payload_file}")"
  local body
  body="$(cat "$body_file")"
  rm -f "$body_file"

  echo "[LEMON_REPLAY] ${label} status=${status} body=${body}" >&2

  printf '%s\n%s' "$status" "$body"
}

SCRIPT_DIR="$(cd "$(dirname "${BASH_SOURCE[0]}")" && pwd)"
BACKEND_DIR="$(cd "$SCRIPT_DIR/.." && pwd)"
ENV_FILE="${ENV_FILE:-$BACKEND_DIR/.env}"
API_BASE="${API_BASE:-http://127.0.0.1:18080}"
URL="${API_BASE}/api/v0.3/webhooks/payment/lemonsqueezy"

LEMON_PAYLOAD_FILE="${LEMON_PAYLOAD_FILE:-}"
LEMON_SIGNATURE="${LEMON_SIGNATURE:-}"
LEMONSQUEEZY_WEBHOOK_SECRET="${LEMONSQUEEZY_WEBHOOK_SECRET:-}"

LEMON_ORDER_NO="${LEMON_ORDER_NO:-ord_lemon_replay_demo}"
LEMON_AMOUNT_CENTS="${LEMON_AMOUNT_CENTS:-4990}"
LEMON_CURRENCY="${LEMON_CURRENCY:-USD}"

require_cmd curl
require_cmd php

if [[ -z "$LEMONSQUEEZY_WEBHOOK_SECRET" && -f "$ENV_FILE" ]]; then
  LEMONSQUEEZY_WEBHOOK_SECRET="$(grep -E '^LEMONSQUEEZY_WEBHOOK_SECRET=' "$ENV_FILE" | tail -n1 | cut -d= -f2- || true)"
  LEMONSQUEEZY_WEBHOOK_SECRET="${LEMONSQUEEZY_WEBHOOK_SECRET%\"}"
  LEMONSQUEEZY_WEBHOOK_SECRET="${LEMONSQUEEZY_WEBHOOK_SECRET#\"}"
  LEMONSQUEEZY_WEBHOOK_SECRET="${LEMONSQUEEZY_WEBHOOK_SECRET%\'}"
  LEMONSQUEEZY_WEBHOOK_SECRET="${LEMONSQUEEZY_WEBHOOK_SECRET#\'}"
fi

echo "[LEMON_REPLAY] backend=${BACKEND_DIR}"
echo "[LEMON_REPLAY] api_base=${API_BASE}"

tmp_payload=""
cleanup() {
  [[ -n "$tmp_payload" && -f "$tmp_payload" ]] && rm -f "$tmp_payload"
}
trap cleanup EXIT

if [[ -z "$LEMON_PAYLOAD_FILE" ]]; then
  if [[ -z "$LEMONSQUEEZY_WEBHOOK_SECRET" ]]; then
    echo "[LEMON_REPLAY][FAIL] LEMONSQUEEZY_WEBHOOK_SECRET is required when LEMON_PAYLOAD_FILE is not provided" >&2
    exit 1
  fi

  tmp_payload="$(mktemp -t lemon_payload.XXXXXX.json)"
  cat > "$tmp_payload" <<JSON
{
  "meta": {
    "event_name": "order_created",
    "custom_data": {
      "order_no": "${LEMON_ORDER_NO}",
      "amount_cents": ${LEMON_AMOUNT_CENTS},
      "currency": "${LEMON_CURRENCY}"
    }
  },
  "data": {
    "id": "demo_$(date +%s)",
    "type": "orders",
    "attributes": {
      "currency": "${LEMON_CURRENCY}"
    }
  }
}
JSON
  LEMON_PAYLOAD_FILE="$tmp_payload"
fi

if [[ ! -f "$LEMON_PAYLOAD_FILE" ]]; then
  echo "[LEMON_REPLAY][FAIL] payload file not found: ${LEMON_PAYLOAD_FILE}" >&2
  exit 1
fi

if [[ -z "$LEMON_SIGNATURE" ]]; then
  if [[ -z "$LEMONSQUEEZY_WEBHOOK_SECRET" ]]; then
    echo "[LEMON_REPLAY][FAIL] set LEMON_SIGNATURE or LEMONSQUEEZY_WEBHOOK_SECRET" >&2
    exit 1
  fi
  LEMON_SIGNATURE="$(compute_signature "$LEMON_PAYLOAD_FILE" "$LEMONSQUEEZY_WEBHOOK_SECRET")"
fi

echo "[LEMON_REPLAY] phase 1: invalid signature check"
invalid_result="$(post_payload "invalid" "$URL" "$LEMON_PAYLOAD_FILE" "invalid")"
invalid_status="$(printf '%s' "$invalid_result" | head -n1)"
invalid_body="$(printf '%s' "$invalid_result" | tail -n +2)"
invalid_code="$(json_field "$invalid_body" "error_code")"
if [[ "$invalid_status" != "400" || "$invalid_code" != "INVALID_SIGNATURE" ]]; then
  echo "[LEMON_REPLAY][FAIL] invalid-signature expectation failed (status=${invalid_status}, error_code=${invalid_code})" >&2
  exit 1
fi

echo "[LEMON_REPLAY] phase 2: replay with provided/derived signature"
valid_result="$(post_payload "valid" "$URL" "$LEMON_PAYLOAD_FILE" "$LEMON_SIGNATURE")"
valid_status="$(printf '%s' "$valid_result" | head -n1)"
valid_body="$(printf '%s' "$valid_result" | tail -n +2)"
valid_code="$(json_field "$valid_body" "error_code")"

if [[ "$valid_status" == "500" ]]; then
  echo "[LEMON_REPLAY][FAIL] valid replay returned 500" >&2
  exit 1
fi

if [[ "$valid_code" == "INVALID_SIGNATURE" ]]; then
  echo "[LEMON_REPLAY][FAIL] valid replay still INVALID_SIGNATURE" >&2
  exit 1
fi

echo "[LEMON_REPLAY] PASS"
