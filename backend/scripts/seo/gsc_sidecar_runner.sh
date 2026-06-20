#!/usr/bin/env bash
set -euo pipefail

SCRIPT_DIR="$(cd "$(dirname "${BASH_SOURCE[0]}")" && pwd)"
BACKEND_DIR="$(cd "${SCRIPT_DIR}/../.." && pwd)"

SIDECAR_ENV_FILE="${SIDECAR_ENV_FILE:-/opt/fermatmind/seo-gsc-runner/env/gsc-sidecar.env}"
SIDECAR_CONFIG_CACHE_DEFAULT="/tmp/fermatmind-gsc-sidecar-config.php"

fail() {
  printf 'error=%s\n' "$1" >&2
  exit 1
}

if [[ ! -r "${SIDECAR_ENV_FILE}" ]]; then
  fail "sidecar_env_file_unreadable"
fi

set -a
# shellcheck disable=SC1090
. "${SIDECAR_ENV_FILE}"
set +a

APP_CONFIG_CACHE="${SIDECAR_CONFIG_CACHE:-${SIDECAR_CONFIG_CACHE_DEFAULT}}"

case "${APP_CONFIG_CACHE}" in
  ""|*"${BACKEND_DIR}/bootstrap/cache/"*|*"/bootstrap/cache/"*)
    fail "sidecar_config_cache_forbidden"
    ;;
esac

required_env=(
  SEO_INTEL_GSC_ENABLED
  SEO_INTEL_GSC_LIVE_API_ENABLED
  SEO_INTEL_ALLOW_EXTERNAL_API_CALLS
  SEO_INTEL_GSC_PROPERTY_URL
  SEO_INTEL_GSC_AUTH_MODE
  SEO_INTEL_GSC_SERVICE_ACCOUNT_JSON_PATH
)

for key in "${required_env[@]}"; do
  if [[ -z "${!key:-}" ]]; then
    fail "sidecar_required_env_missing"
  fi
done

if [[ "${SEO_INTEL_GSC_ENABLED}" != "true" ]]; then
  fail "sidecar_gsc_enabled_not_true"
fi

if [[ "${SEO_INTEL_GSC_LIVE_API_ENABLED}" != "true" ]]; then
  fail "sidecar_gsc_live_api_enabled_not_true"
fi

if [[ "${SEO_INTEL_ALLOW_EXTERNAL_API_CALLS}" != "true" ]]; then
  fail "sidecar_external_api_calls_not_true"
fi

if [[ "${SEO_INTEL_GSC_AUTH_MODE}" != "service_account" ]]; then
  fail "sidecar_auth_mode_not_service_account"
fi

if [[ -n "${SEO_INTEL_GSC_SERVICE_ACCOUNT_JSON:-}" ]]; then
  fail "sidecar_inline_service_account_json_forbidden"
fi

if [[ -n "${SEO_INTEL_GSC_ACCESS_TOKEN:-}" ]]; then
  fail "sidecar_access_token_forbidden"
fi

export APP_CONFIG_CACHE
export SEO_INTEL_GSC_ENABLED
export SEO_INTEL_GSC_LIVE_API_ENABLED
export SEO_INTEL_ALLOW_EXTERNAL_API_CALLS
export SEO_INTEL_GSC_PROPERTY_URL
export SEO_INTEL_GSC_AUTH_MODE
export SEO_INTEL_GSC_SERVICE_ACCOUNT_JSON_PATH

cd "${BACKEND_DIR}"
exec php artisan seo-intel:gsc-sidecar-runner "$@"
