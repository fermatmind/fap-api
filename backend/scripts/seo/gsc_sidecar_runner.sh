#!/usr/bin/env bash
set -euo pipefail

PATH="/usr/local/sbin:/usr/local/bin:/usr/sbin:/usr/bin:/sbin:/bin"
export PATH

SCRIPT_DIR="$(cd "$(dirname "${BASH_SOURCE[0]}")" && pwd)"
BACKEND_DIR="$(cd "${SCRIPT_DIR}/../.." && pwd)"

SIDECAR_ENV_FILE="${SIDECAR_ENV_FILE:-/opt/fermatmind/seo-gsc-runner/env/gsc-sidecar.env}"

fail() {
  printf 'error=%s\n' "$1" >&2
  exit 1
}

if [[ "${SIDECAR_ENV_FILE}" != /* || -L "${SIDECAR_ENV_FILE}" || ! -r "${SIDECAR_ENV_FILE}" ]]; then
  fail "sidecar_env_file_unreadable"
fi

if [[ -n "${SIDECAR_CONFIG_CACHE:-}" || -n "${APP_CONFIG_CACHE:-}" ]]; then
  fail "sidecar_config_cache_override_forbidden"
fi

if [[ -n "${SEO_INTEL_GSC_SERVICE_ACCOUNT_JSON:-}" ]]; then
  fail "sidecar_inline_service_account_json_forbidden"
fi

if [[ -n "${SEO_INTEL_GSC_ACCESS_TOKEN:-}" ]]; then
  fail "sidecar_access_token_forbidden"
fi

required_env=(
  SEO_INTEL_GSC_ENABLED
  SEO_INTEL_GSC_LIVE_API_ENABLED
  SEO_INTEL_ALLOW_EXTERNAL_API_CALLS
  SEO_INTEL_GSC_PROPERTY_URL
  SEO_INTEL_GSC_AUTH_MODE
  SEO_INTEL_GSC_SERVICE_ACCOUNT_JSON_PATH
)

allowed_env=(
  "${required_env[@]}"
  SEO_INTEL_GSC_SERVICE_ACCOUNT_JSON
  SEO_INTEL_GSC_ACCESS_TOKEN
)

for key in "${allowed_env[@]}"; do
  unset "${key}"
done

while IFS= read -r line || [[ -n "${line}" ]]; do
  line="${line%$'\r'}"
  if [[ "${line}" =~ ^[[:space:]]*$ || "${line}" =~ ^[[:space:]]*# ]]; then
    continue
  fi

  if [[ "${line}" == export\ * ]]; then
    line="${line#export }"
  fi

  if [[ ! "${line}" =~ ^([A-Z][A-Z0-9_]*)=(.*)$ ]]; then
    fail "sidecar_env_line_invalid"
  fi

  key="${BASH_REMATCH[1]}"
  value="${BASH_REMATCH[2]}"
  case " ${allowed_env[*]} " in
    *" ${key} "*) ;;
    *) fail "sidecar_env_key_forbidden" ;;
  esac

  first="${value:0:1}"
  last="${value: -1}"
  if [[ "${first}" == '"' || "${first}" == "'" ]]; then
    if (( ${#value} < 2 )) || [[ "${last}" != "${first}" ]]; then
      fail "sidecar_env_quote_invalid"
    fi
    value="${value:1:${#value}-2}"
  elif [[ "${last}" == '"' || "${last}" == "'" ]]; then
    fail "sidecar_env_quote_invalid"
  fi

  printf -v "${key}" '%s' "${value}"
  export "${key}"
done < "${SIDECAR_ENV_FILE}"

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

umask 077
SIDECAR_CACHE_DIR="$(mktemp -d /tmp/fermatmind-gsc-sidecar.XXXXXX)" || fail "sidecar_config_cache_create_failed"
APP_CONFIG_CACHE="${SIDECAR_CACHE_DIR}/config.php"

cleanup() {
  /bin/rm -f "${APP_CONFIG_CACHE}"
  /bin/rmdir "${SIDECAR_CACHE_DIR}" 2>/dev/null || true
}
trap cleanup EXIT
trap 'exit 130' HUP INT TERM

export APP_CONFIG_CACHE
export SEO_INTEL_GSC_ENABLED
export SEO_INTEL_GSC_LIVE_API_ENABLED
export SEO_INTEL_ALLOW_EXTERNAL_API_CALLS
export SEO_INTEL_GSC_PROPERTY_URL
export SEO_INTEL_GSC_AUTH_MODE
export SEO_INTEL_GSC_SERVICE_ACCOUNT_JSON_PATH

cd "${BACKEND_DIR}"
php artisan seo-intel:gsc-sidecar-runner "$@"
