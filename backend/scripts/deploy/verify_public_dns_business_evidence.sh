#!/usr/bin/env bash
set -euo pipefail

PUBLIC_DNS_PROBE_BASE_URL="${PUBLIC_DNS_PROBE_BASE_URL:-}"
PUBLIC_DNS_PROBE_ATTEMPTS="${PUBLIC_DNS_PROBE_ATTEMPTS:-3}"
PUBLIC_DNS_PROBE_RETRY_DELAYS_SECONDS="${PUBLIC_DNS_PROBE_RETRY_DELAYS_SECONDS-2 5}"
PUBLIC_DNS_PROBE_CONNECT_TIMEOUT_SECONDS="${PUBLIC_DNS_PROBE_CONNECT_TIMEOUT_SECONDS:-3}"
PUBLIC_DNS_PROBE_MAX_TIME_SECONDS="${PUBLIC_DNS_PROBE_MAX_TIME_SECONDS:-10}"

fail() {
  printf 'Public DNS business evidence %s\n' "$1" >&2
  exit "${2:-1}"
}

validate_integer_range() {
  local name="$1"
  local value="$2"
  local minimum="$3"
  local maximum="$4"

  [[ "$value" =~ ^[0-9]+$ ]] || fail "configuration error: ${name} must be an integer" 2
  [ "$value" -ge "$minimum" ] && [ "$value" -le "$maximum" ] \
    || fail "configuration error: ${name} is outside the allowed range" 2
}

[[ "$PUBLIC_DNS_PROBE_BASE_URL" =~ ^https://[A-Za-z0-9.-]+(:[0-9]+)?$ ]] \
  || fail "configuration error: PUBLIC_DNS_PROBE_BASE_URL must be an HTTPS origin without credentials or a path" 2
validate_integer_range "PUBLIC_DNS_PROBE_ATTEMPTS" "$PUBLIC_DNS_PROBE_ATTEMPTS" 1 5
validate_integer_range \
  "PUBLIC_DNS_PROBE_CONNECT_TIMEOUT_SECONDS" \
  "$PUBLIC_DNS_PROBE_CONNECT_TIMEOUT_SECONDS" \
  1 \
  30
validate_integer_range \
  "PUBLIC_DNS_PROBE_MAX_TIME_SECONDS" \
  "$PUBLIC_DNS_PROBE_MAX_TIME_SECONDS" \
  1 \
  120

retry_delays=()
expected_delay_count=$((PUBLIC_DNS_PROBE_ATTEMPTS - 1))
if [ "$expected_delay_count" -eq 0 ]; then
  [ -z "$PUBLIC_DNS_PROBE_RETRY_DELAYS_SECONDS" ] \
    || fail "configuration error: PUBLIC_DNS_PROBE_RETRY_DELAYS_SECONDS must be empty without retries" 2
else
  read -r -a retry_delays <<< "$PUBLIC_DNS_PROBE_RETRY_DELAYS_SECONDS"
  [ "${#retry_delays[@]}" -eq "$expected_delay_count" ] \
    || fail "configuration error: PUBLIC_DNS_PROBE_RETRY_DELAYS_SECONDS must provide one delay per retry" 2
  for delay in "${retry_delays[@]}"; do
    validate_integer_range "PUBLIC_DNS_PROBE_RETRY_DELAYS_SECONDS" "$delay" 0 30
  done
fi

command -v curl >/dev/null 2>&1 || fail "configuration error: curl is required" 2
command -v jq >/dev/null 2>&1 || fail "configuration error: jq is required" 2

PROBE_STAGE="not_started"
PROBE_STATUS="none"
PROBE_BODY=""

probe_http() {
  local url="$1"
  local expected_status="$2"
  local raw

  PROBE_STATUS="none"
  PROBE_BODY=""
  if ! raw="$(curl \
    -sS \
    --connect-timeout "$PUBLIC_DNS_PROBE_CONNECT_TIMEOUT_SECONDS" \
    --max-time "$PUBLIC_DNS_PROBE_MAX_TIME_SECONDS" \
    -w $'\n%{http_code}' \
    "$url" \
    2>/dev/null)"
  then
    return 75
  fi

  PROBE_STATUS="${raw##*$'\n'}"
  PROBE_BODY="${raw%$'\n'*}"
  if [[ ! "$PROBE_STATUS" =~ ^[0-9]{3}$ ]]; then
    PROBE_STATUS="none"
    return 1
  fi

  case "$PROBE_STATUS" in
    429|502|503|504) return 75 ;;
  esac

  [ "$PROBE_STATUS" = "$expected_status" ] || return 1
}

verify_public_evidence() {
  PROBE_STAGE="public_health"
  probe_http "${PUBLIC_DNS_PROBE_BASE_URL}/api/healthz" "404" || return $?

  PROBE_STAGE="public_flags"
  probe_http "${PUBLIC_DNS_PROBE_BASE_URL}/api/v0.3/flags" "200" || return $?

  PROBE_STAGE="public_bigfive"
  probe_http \
    "${PUBLIC_DNS_PROBE_BASE_URL}/api/v0.5/personality-content-assets/big_five/hub/big-five?locale=zh-CN" \
    "200" \
    || return $?

  PROBE_STAGE="public_bigfive_contract"
  printf '%s' "$PROBE_BODY" | jq -e \
    '.ok == true and (.personality_public_content_asset_v1.source_hash | strings | test("^[0-9a-f]{64}$"))' \
    >/dev/null \
    || return 1
}

for attempt in $(seq 1 "$PUBLIC_DNS_PROBE_ATTEMPTS"); do
  set +e
  verify_public_evidence
  probe_rc=$?
  set -e

  if [ "$probe_rc" -eq 0 ]; then
    printf 'Public DNS business evidence passed on attempt %s\n' "$attempt"
    exit 0
  fi

  if [ "$probe_rc" -ne 75 ]; then
    fail "failed terminally: stage=${PROBE_STAGE} status=${PROBE_STATUS} rc=${probe_rc}"
  fi

  if [ "$attempt" -eq "$PUBLIC_DNS_PROBE_ATTEMPTS" ]; then
    fail "failed after ${PUBLIC_DNS_PROBE_ATTEMPTS} attempts: stage=${PROBE_STAGE} status=${PROBE_STATUS} rc=${probe_rc}"
  fi

  printf \
    'Public DNS business evidence retrying after attempt %s: stage=%s status=%s rc=%s\n' \
    "$attempt" \
    "$PROBE_STAGE" \
    "$PROBE_STATUS" \
    "$probe_rc" \
    >&2
  sleep "${retry_delays[$((attempt - 1))]}"
done

fail "failed unexpectedly outside the bounded attempt loop"
