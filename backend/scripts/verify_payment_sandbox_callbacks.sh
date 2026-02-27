#!/usr/bin/env bash
set -euo pipefail

require_cmd() {
  command -v "$1" >/dev/null 2>&1 || {
    echo "[SANDBOX_CB][FAIL] missing command: $1" >&2
    exit 1
  }
}

json_field() {
  local json_text="$1"
  local key="$2"
  php -r '$j=json_decode($argv[1], true); if (!is_array($j)) { echo ""; exit(0);} $v=$j[$argv[2]] ?? ""; if (is_scalar($v)) { echo (string)$v; }' "$json_text" "$key"
}

call_webhook() {
  local label="$1"
  local url="$2"
  local content_type="$3"
  local payload_mode="$4"
  local payload_value="$5"
  shift 4
  shift 1
  local headers=("$@")

  local body_file
  body_file="$(mktemp -t sandbox_cb.XXXXXX)"

  local -a curl_cmd
  curl_cmd=(curl -sS -o "$body_file" -w "%{http_code}" -X POST "$url" -H "Content-Type: ${content_type}")

  if [[ ${#headers[@]} -gt 0 ]]; then
    curl_cmd+=("${headers[@]}")
  fi

  case "$payload_mode" in
    data)
      curl_cmd+=(--data "$payload_value")
      ;;
    data-binary)
      curl_cmd+=(--data-binary "$payload_value")
      ;;
    data-file)
      curl_cmd+=(--data "@${payload_value}")
      ;;
    data-binary-file)
      curl_cmd+=(--data-binary "@${payload_value}")
      ;;
    *)
      echo "[SANDBOX_CB][FAIL] unknown payload_mode: ${payload_mode}" >&2
      exit 1
      ;;
  esac

  local status
  status="$("${curl_cmd[@]}")"

  local body
  body="$(cat "$body_file")"
  rm -f "$body_file"

  echo "[SANDBOX_CB] ${label} status=${status} body=${body}" >&2

  printf '%s\n%s' "$status" "$body"
}

assert_invalid_signature() {
  local label="$1"
  local status="$2"
  local body="$3"

  local error_code
  error_code="$(json_field "$body" "error_code")"

  if [[ "$status" != "400" || "$error_code" != "INVALID_SIGNATURE" ]]; then
    echo "[SANDBOX_CB][FAIL] ${label}: expect 400 + INVALID_SIGNATURE, got status=${status}, error_code=${error_code}" >&2
    exit 1
  fi
}

assert_signature_passed() {
  local label="$1"
  local status="$2"
  local body="$3"

  local error_code
  error_code="$(json_field "$body" "error_code")"

  if [[ "$status" == "500" ]]; then
    echo "[SANDBOX_CB][FAIL] ${label}: got 500" >&2
    exit 1
  fi

  if [[ "$error_code" == "INVALID_SIGNATURE" ]]; then
    echo "[SANDBOX_CB][FAIL] ${label}: signature still invalid" >&2
    exit 1
  fi
}

SCRIPT_DIR="$(cd "$(dirname "${BASH_SOURCE[0]}")" && pwd)"
BACKEND_DIR="$(cd "$SCRIPT_DIR/.." && pwd)"
API_BASE="${API_BASE:-http://127.0.0.1:18080}"

WECHAT_URL="${API_BASE}/api/v0.3/webhooks/payment/wechatpay"
ALIPAY_URL="${API_BASE}/api/v0.3/webhooks/payment/alipay"

WECHAT_FIXTURE_BODY_FILE="${WECHAT_FIXTURE_BODY_FILE:-}"
WECHAT_FIXTURE_HEADER_FILE="${WECHAT_FIXTURE_HEADER_FILE:-}"
ALIPAY_FIXTURE_FORM_FILE="${ALIPAY_FIXTURE_FORM_FILE:-}"

require_cmd curl
require_cmd php

echo "[SANDBOX_CB] backend=${BACKEND_DIR}"
echo "[SANDBOX_CB] api_base=${API_BASE}"

echo "[SANDBOX_CB] phase 1: negative signature checks"
invalid_wechat="$(call_webhook "wechat.invalid" "$WECHAT_URL" "application/json" "data-binary" '{"foo":"bar"}')"
invalid_wechat_status="$(printf '%s' "$invalid_wechat" | head -n1)"
invalid_wechat_body="$(printf '%s' "$invalid_wechat" | tail -n +2)"
assert_invalid_signature "wechat.invalid" "$invalid_wechat_status" "$invalid_wechat_body"

invalid_alipay="$(call_webhook "alipay.invalid" "$ALIPAY_URL" "application/x-www-form-urlencoded" "data" "foo=bar")"
invalid_alipay_status="$(printf '%s' "$invalid_alipay" | head -n1)"
invalid_alipay_body="$(printf '%s' "$invalid_alipay" | tail -n +2)"
assert_invalid_signature "alipay.invalid" "$invalid_alipay_status" "$invalid_alipay_body"

echo "[SANDBOX_CB] phase 2: optional positive fixture checks"

if [[ -n "$WECHAT_FIXTURE_BODY_FILE" ]]; then
  if [[ ! -f "$WECHAT_FIXTURE_BODY_FILE" ]]; then
    echo "[SANDBOX_CB][FAIL] WECHAT_FIXTURE_BODY_FILE not found: ${WECHAT_FIXTURE_BODY_FILE}" >&2
    exit 1
  fi

  wechat_headers=()
  if [[ -n "$WECHAT_FIXTURE_HEADER_FILE" ]]; then
    if [[ ! -f "$WECHAT_FIXTURE_HEADER_FILE" ]]; then
      echo "[SANDBOX_CB][FAIL] WECHAT_FIXTURE_HEADER_FILE not found: ${WECHAT_FIXTURE_HEADER_FILE}" >&2
      exit 1
    fi

    while IFS= read -r line; do
      [[ -z "$line" ]] && continue
      wechat_headers+=("-H" "$line")
    done < "$WECHAT_FIXTURE_HEADER_FILE"
  fi

  positive_wechat="$(call_webhook "wechat.fixture" "$WECHAT_URL" "application/json" "data-binary-file" "$WECHAT_FIXTURE_BODY_FILE" "${wechat_headers[@]}")"
  positive_wechat_status="$(printf '%s' "$positive_wechat" | head -n1)"
  positive_wechat_body="$(printf '%s' "$positive_wechat" | tail -n +2)"
  assert_signature_passed "wechat.fixture" "$positive_wechat_status" "$positive_wechat_body"
else
  echo "[SANDBOX_CB][SKIP] WECHAT_FIXTURE_BODY_FILE not set"
fi

if [[ -n "$ALIPAY_FIXTURE_FORM_FILE" ]]; then
  if [[ ! -f "$ALIPAY_FIXTURE_FORM_FILE" ]]; then
    echo "[SANDBOX_CB][FAIL] ALIPAY_FIXTURE_FORM_FILE not found: ${ALIPAY_FIXTURE_FORM_FILE}" >&2
    exit 1
  fi

  positive_alipay="$(call_webhook "alipay.fixture" "$ALIPAY_URL" "application/x-www-form-urlencoded" "data-file" "$ALIPAY_FIXTURE_FORM_FILE")"
  positive_alipay_status="$(printf '%s' "$positive_alipay" | head -n1)"
  positive_alipay_body="$(printf '%s' "$positive_alipay" | tail -n +2)"
  assert_signature_passed "alipay.fixture" "$positive_alipay_status" "$positive_alipay_body"
else
  echo "[SANDBOX_CB][SKIP] ALIPAY_FIXTURE_FORM_FILE not set"
fi

echo "[SANDBOX_CB] PASS"
