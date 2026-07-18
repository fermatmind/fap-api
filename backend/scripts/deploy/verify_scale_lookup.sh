#!/usr/bin/env bash
set -euo pipefail

SCALE_LOOKUP_BASE_URL="${SCALE_LOOKUP_BASE_URL:-}"
SCALE_LOOKUP_SLUG="${SCALE_LOOKUP_SLUG:-}"
SCALE_LOOKUP_USE_RESOLVE="${SCALE_LOOKUP_USE_RESOLVE:-false}"
SCALE_LOOKUP_ATTEMPTS="${SCALE_LOOKUP_ATTEMPTS:-3}"
SCALE_LOOKUP_RETRY_DELAY_SECONDS="${SCALE_LOOKUP_RETRY_DELAY_SECONDS:-2}"
SCALE_LOOKUP_CONNECT_TIMEOUT_SECONDS="${SCALE_LOOKUP_CONNECT_TIMEOUT_SECONDS:-5}"
SCALE_LOOKUP_MAX_TIME_SECONDS="${SCALE_LOOKUP_MAX_TIME_SECONDS:-40}"

fail() {
  printf 'scale lookup verification failed: %s\n' "$1" >&2
  exit "${2:-1}"
}

require_value() {
  local name="$1"
  local value="$2"

  [ -n "$value" ] || fail "${name} is required" 2
}

validate_integer_range() {
  local name="$1"
  local value="$2"
  local minimum="$3"
  local maximum="$4"

  [[ "$value" =~ ^[0-9]+$ ]] || fail "${name} must be an integer" 2
  [ "$value" -ge "$minimum" ] && [ "$value" -le "$maximum" ] \
    || fail "${name} is outside the allowed range" 2
}

require_value "SCALE_LOOKUP_BASE_URL" "$SCALE_LOOKUP_BASE_URL"
require_value "SCALE_LOOKUP_SLUG" "$SCALE_LOOKUP_SLUG"

[[ "$SCALE_LOOKUP_BASE_URL" =~ ^https://[A-Za-z0-9.-]+(:[0-9]+)?$ ]] \
  || fail "SCALE_LOOKUP_BASE_URL must be an HTTPS origin without credentials or a path" 2
[[ "$SCALE_LOOKUP_SLUG" =~ ^[a-z0-9]+(-[a-z0-9]+)*$ ]] \
  || fail "SCALE_LOOKUP_SLUG contains unsupported characters" 2
case "$SCALE_LOOKUP_USE_RESOLVE" in
  true|false) ;;
  *) fail "SCALE_LOOKUP_USE_RESOLVE must be true or false" 2 ;;
esac

validate_integer_range "SCALE_LOOKUP_ATTEMPTS" "$SCALE_LOOKUP_ATTEMPTS" 1 5
validate_integer_range "SCALE_LOOKUP_RETRY_DELAY_SECONDS" "$SCALE_LOOKUP_RETRY_DELAY_SECONDS" 0 30
validate_integer_range "SCALE_LOOKUP_CONNECT_TIMEOUT_SECONDS" "$SCALE_LOOKUP_CONNECT_TIMEOUT_SECONDS" 1 30
validate_integer_range "SCALE_LOOKUP_MAX_TIME_SECONDS" "$SCALE_LOOKUP_MAX_TIME_SECONDS" 1 120

command -v curl >/dev/null 2>&1 || fail "curl is required" 2
command -v jq >/dev/null 2>&1 || fail "jq is required" 2

lookup_url="${SCALE_LOOKUP_BASE_URL}/api/v0.3/scales/lookup?slug=${SCALE_LOOKUP_SLUG}&locale=zh-CN"
curl_args=(
  -fsS
  --connect-timeout "$SCALE_LOOKUP_CONNECT_TIMEOUT_SECONDS"
  --max-time "$SCALE_LOOKUP_MAX_TIME_SECONDS"
)

if [ "$SCALE_LOOKUP_USE_RESOLVE" = "true" ]; then
  authority="${SCALE_LOOKUP_BASE_URL#https://}"
  resolve_host="${authority%%:*}"
  resolve_port="443"
  if [[ "$authority" == *:* ]]; then
    resolve_port="${authority##*:}"
  fi
  curl_args+=(--resolve "${resolve_host}:${resolve_port}:127.0.0.1")
fi

for attempt in $(seq 1 "$SCALE_LOOKUP_ATTEMPTS"); do
  if curl "${curl_args[@]}" "$lookup_url" |
    jq -e --arg slug "$SCALE_LOOKUP_SLUG" \
      '.ok == true and .primary_slug == $slug' >/dev/null
  then
    printf 'scale lookup verification passed on attempt %s\n' "$attempt"
    exit 0
  fi

  if [ "$attempt" -lt "$SCALE_LOOKUP_ATTEMPTS" ]; then
    printf 'scale lookup verification attempt %s failed; retrying\n' "$attempt" >&2
    sleep "$SCALE_LOOKUP_RETRY_DELAY_SECONDS"
  fi
done

fail "all bounded attempts failed"
