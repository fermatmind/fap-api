#!/usr/bin/env bash
set -euo pipefail

HEALTHCHECK_HOST="${HEALTHCHECK_HOST:-}"
PUBLIC_API_BASE_URL="${PUBLIC_API_BASE_URL:-}"
BACKEND_SHA="${BACKEND_SHA:-}"
RELEASE_NAME="${RELEASE_NAME:-}"
PROBE_ID="${PROBE_ID:-}"
TIMEOUT="${TIMEOUT:-10}"

fail() {
  echo "readiness failed: $1"
  exit "${2:-1}"
}

require_value() {
  local name="$1"
  local value="$2"

  [ -n "$value" ] || fail "${name} is required" 2
}

validate_https_origin() {
  local name="$1"
  local value="$2"

  [[ "$value" =~ ^https://[A-Za-z0-9.-]+(:[0-9]+)?$ ]] || fail "${name} must be an HTTPS origin without credentials or a path" 2
}

request_code() {
  local label="$1"
  local url="$2"
  local user_agent="${3:-}"
  local code

  if [ -n "$user_agent" ]; then
    code="$(curl -sS --max-time "$TIMEOUT" -A "$user_agent" -o "$BODY_FILE" -w "%{http_code}" "$url" || true)"
  else
    code="$(curl -sS --max-time "$TIMEOUT" -o "$BODY_FILE" -w "%{http_code}" "$url" || true)"
  fi

  [ "$code" = "200" ] || fail "${label} returned a non-200 response"
}

printf '[readiness] start\n'

require_value "HEALTHCHECK_HOST" "$HEALTHCHECK_HOST"
require_value "PUBLIC_API_BASE_URL" "$PUBLIC_API_BASE_URL"
require_value "BACKEND_SHA" "$BACKEND_SHA"
require_value "RELEASE_NAME" "$RELEASE_NAME"
require_value "PROBE_ID" "$PROBE_ID"

[[ "$HEALTHCHECK_HOST" =~ ^[A-Za-z0-9.-]+$ ]] || fail "HEALTHCHECK_HOST must be a hostname" 2
validate_https_origin "PUBLIC_API_BASE_URL" "$PUBLIC_API_BASE_URL"
[[ "$BACKEND_SHA" =~ ^[0-9a-f]{40}$ ]] || fail "BACKEND_SHA must be a 40-character lowercase SHA" 2
[[ "$RELEASE_NAME" =~ ^[A-Za-z0-9._-]+$ ]] || fail "RELEASE_NAME contains unsupported characters" 2
[[ "$PROBE_ID" =~ ^[A-Za-z0-9._-]+$ ]] || fail "PROBE_ID contains unsupported characters" 2
[[ "$TIMEOUT" =~ ^[1-9][0-9]*$ ]] || fail "TIMEOUT must be a positive integer" 2

command -v curl >/dev/null 2>&1 || fail "curl is required" 2
command -v jq >/dev/null 2>&1 || fail "jq is required" 2

BODY_FILE="$(mktemp "${TMPDIR:-/tmp}/fermatmind-readiness.XXXXXX")"
trap 'rm -f "$BODY_FILE"' EXIT

internal_code="$(
  curl -sS --max-time "$TIMEOUT" \
    --resolve "${HEALTHCHECK_HOST}:443:127.0.0.1" \
    -o "$BODY_FILE" \
    -w "%{http_code}" \
    "https://${HEALTHCHECK_HOST}/api/healthz" 2>/dev/null || true
)"
[ "$internal_code" = "200" ] || fail "target-node health returned a non-200 response"
jq -e '.ok == true' "$BODY_FILE" >/dev/null 2>&1 || fail "target-node health payload is not healthy"

request_code "public flags API" "${PUBLIC_API_BASE_URL}/api/v0.3/flags"
request_code \
  "public Big Five Personality API" \
  "${PUBLIC_API_BASE_URL}/api/v0.5/personality-content-assets/big_five/hub/big-five?locale=zh-CN" \
  "FermatMindReleaseProbe/${PROBE_ID}"

echo "backend sha accepted: ${BACKEND_SHA}"
echo "release name accepted: ${RELEASE_NAME}"
echo "[readiness] target-node health and public business probes passed"
