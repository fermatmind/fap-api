#!/usr/bin/env bash
set -euo pipefail

require_cmd() {
  command -v "$1" >/dev/null 2>&1 || {
    echo "[CERT_LAYOUT][FAIL] missing command: $1" >&2
    exit 1
  }
}

read_env_value() {
  local key="$1"
  local default_value="$2"
  local env_file="$3"
  local value="${!key:-}"

  if [[ -z "$value" && -f "$env_file" ]]; then
    value="$(grep -E "^${key}=" "$env_file" | tail -n1 | cut -d= -f2- || true)"
    value="${value%\"}"
    value="${value#\"}"
    value="${value%\'}"
    value="${value#\'}"
  fi

  if [[ -z "$value" ]]; then
    value="$default_value"
  fi

  printf '%s' "$value"
}

resolve_path() {
  local raw="$1"
  local backend_dir="$2"
  if [[ "$raw" = /* ]]; then
    printf '%s' "$raw"
  else
    printf '%s' "$backend_dir/$raw"
  fi
}

sha256_file() {
  local file_path="$1"
  if command -v shasum >/dev/null 2>&1; then
    shasum -a 256 "$file_path" | awk '{print $1}'
    return
  fi

  if command -v openssl >/dev/null 2>&1; then
    openssl dgst -sha256 "$file_path" | awk '{print $2}'
    return
  fi

  echo "sha256-unavailable"
}

check_file() {
  local label="$1"
  local file_path="$2"
  local failed_ref="$3"

  if [[ ! -e "$file_path" ]]; then
    echo "[CERT_LAYOUT][FAIL] ${label}: missing -> ${file_path}"
    printf -v "$failed_ref" '%s' "1"
    return
  fi

  if [[ ! -r "$file_path" ]]; then
    echo "[CERT_LAYOUT][FAIL] ${label}: not readable -> ${file_path}"
    printf -v "$failed_ref" '%s' "1"
    return
  fi

  if [[ ! -s "$file_path" ]]; then
    echo "[CERT_LAYOUT][FAIL] ${label}: empty -> ${file_path}"
    printf -v "$failed_ref" '%s' "1"
    return
  fi

  local digest
  digest="$(sha256_file "$file_path")"
  echo "[CERT_LAYOUT][OK] ${label}: ${file_path} (sha256=${digest})"
}

check_notify_url() {
  local provider="$1"
  local url="$2"
  local failed_ref="$3"

  if [[ -z "$url" ]]; then
    echo "[CERT_LAYOUT][WARN] ${provider} notify_url empty"
    return
  fi

  if [[ "$url" == *\?* ]]; then
    echo "[CERT_LAYOUT][FAIL] ${provider} notify_url must not contain query string: ${url}"
    printf -v "$failed_ref" '%s' "1"
    return
  fi

  if [[ "$url" != http://* && "$url" != https://* ]]; then
    echo "[CERT_LAYOUT][FAIL] ${provider} notify_url must be http(s): ${url}"
    printf -v "$failed_ref" '%s' "1"
    return
  fi

  echo "[CERT_LAYOUT][OK] ${provider} notify_url: ${url}"
}

require_cmd grep

SCRIPT_DIR="$(cd "$(dirname "${BASH_SOURCE[0]}")" && pwd)"
BACKEND_DIR="$(cd "$SCRIPT_DIR/.." && pwd)"
ENV_FILE="${ENV_FILE:-$BACKEND_DIR/.env}"

WECHAT_MCH_SECRET_CERT_RAW="$(read_env_value "WECHAT_PAY_MCH_SECRET_CERT" "storage/app/cert/wechat/apiclient_key.pem" "$ENV_FILE")"
WECHAT_MCH_PUBLIC_CERT_RAW="$(read_env_value "WECHAT_PAY_MCH_PUBLIC_CERT" "storage/app/cert/wechat/apiclient_cert.pem" "$ENV_FILE")"
WECHAT_PLATFORM_CERT_RAW="$(read_env_value "WECHAT_PAY_PLATFORM_CERT" "storage/app/cert/wechat/wechatpay_platform_cert.pem" "$ENV_FILE")"

ALIPAY_MERCHANT_PRIVATE_KEY_PATH_RAW="$(read_env_value "ALIPAY_MERCHANT_PRIVATE_KEY_PATH" "storage/app/cert/alipay/app_private_key.pem" "$ENV_FILE")"
ALIPAY_APP_PUBLIC_CERT_RAW="$(read_env_value "ALIPAY_APP_PUBLIC_CERT" "storage/app/cert/alipay/appCertPublicKey.crt" "$ENV_FILE")"
ALIPAY_PUBLIC_CERT_RAW="$(read_env_value "ALIPAY_PUBLIC_CERT" "storage/app/cert/alipay/alipayCertPublicKey_RSA2.crt" "$ENV_FILE")"
ALIPAY_ROOT_CERT_RAW="$(read_env_value "ALIPAY_ROOT_CERT" "storage/app/cert/alipay/alipayRootCert.crt" "$ENV_FILE")"

WECHAT_NOTIFY_URL="$(read_env_value "WECHAT_PAY_NOTIFY_URL" "" "$ENV_FILE")"
ALIPAY_NOTIFY_URL="$(read_env_value "ALIPAY_NOTIFY_URL" "" "$ENV_FILE")"

WECHAT_MCH_SECRET_CERT="$(resolve_path "$WECHAT_MCH_SECRET_CERT_RAW" "$BACKEND_DIR")"
WECHAT_MCH_PUBLIC_CERT="$(resolve_path "$WECHAT_MCH_PUBLIC_CERT_RAW" "$BACKEND_DIR")"
WECHAT_PLATFORM_CERT="$(resolve_path "$WECHAT_PLATFORM_CERT_RAW" "$BACKEND_DIR")"
ALIPAY_MERCHANT_PRIVATE_KEY_PATH="$(resolve_path "$ALIPAY_MERCHANT_PRIVATE_KEY_PATH_RAW" "$BACKEND_DIR")"
ALIPAY_APP_PUBLIC_CERT="$(resolve_path "$ALIPAY_APP_PUBLIC_CERT_RAW" "$BACKEND_DIR")"
ALIPAY_PUBLIC_CERT="$(resolve_path "$ALIPAY_PUBLIC_CERT_RAW" "$BACKEND_DIR")"
ALIPAY_ROOT_CERT="$(resolve_path "$ALIPAY_ROOT_CERT_RAW" "$BACKEND_DIR")"

failed=0

echo "[CERT_LAYOUT] backend=${BACKEND_DIR}"
echo "[CERT_LAYOUT] env_file=${ENV_FILE}"

echo "[CERT_LAYOUT] checking WeChat certificate files"
check_file "wechat.mch_secret_cert" "$WECHAT_MCH_SECRET_CERT" failed
check_file "wechat.mch_public_cert" "$WECHAT_MCH_PUBLIC_CERT" failed
check_file "wechat.platform_cert" "$WECHAT_PLATFORM_CERT" failed

echo "[CERT_LAYOUT] checking Alipay certificate files"
check_file "alipay.merchant_private_key_path" "$ALIPAY_MERCHANT_PRIVATE_KEY_PATH" failed
check_file "alipay.app_public_cert" "$ALIPAY_APP_PUBLIC_CERT" failed
check_file "alipay.public_cert" "$ALIPAY_PUBLIC_CERT" failed
check_file "alipay.root_cert" "$ALIPAY_ROOT_CERT" failed

echo "[CERT_LAYOUT] checking notify_url contract (no query params)"
check_notify_url "wechatpay" "$WECHAT_NOTIFY_URL" failed
check_notify_url "alipay" "$ALIPAY_NOTIFY_URL" failed

if [[ "$failed" != "0" ]]; then
  echo "[CERT_LAYOUT] FAIL"
  exit 1
fi

echo "[CERT_LAYOUT] PASS"
