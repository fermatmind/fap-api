#!/usr/bin/env bash
set -euo pipefail

HEALTHCHECK_HOST="${HEALTHCHECK_HOST:-}"
PUBLIC_API_BASE_URL="${PUBLIC_API_BASE_URL:-}"
PUBLIC_WEB_BASE_URL="${PUBLIC_WEB_BASE_URL:-}"
BACKEND_SHA="${BACKEND_SHA:-}"
RELEASE_NAME="${RELEASE_NAME:-}"
PROBE_ID="${PROBE_ID:-}"
TIMEOUT="${TIMEOUT:-10}"

fail() {
  echo "post-deploy validation failed: $1"
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

check_url() {
  local label="$1"
  local url="$2"
  local expected="$3"
  local user_agent="${4:-}"
  local code

  if [ -n "$user_agent" ]; then
    code="$(curl -sS --max-time "$TIMEOUT" -A "$user_agent" -o "$BODY_FILE" -w "%{http_code}" "$url" || true)"
  else
    code="$(curl -sS --max-time "$TIMEOUT" -o "$BODY_FILE" -w "%{http_code}" "$url" || true)"
  fi

  if [ "$code" != "$expected" ]; then
    echo "[smoke] fail ${label}: expected ${expected}, received a different response"
    return 1
  fi

  echo "[smoke] pass ${label}"
}

require_value "HEALTHCHECK_HOST" "$HEALTHCHECK_HOST"
require_value "PUBLIC_API_BASE_URL" "$PUBLIC_API_BASE_URL"
require_value "PUBLIC_WEB_BASE_URL" "$PUBLIC_WEB_BASE_URL"
require_value "BACKEND_SHA" "$BACKEND_SHA"
require_value "RELEASE_NAME" "$RELEASE_NAME"
require_value "PROBE_ID" "$PROBE_ID"

[[ "$HEALTHCHECK_HOST" =~ ^[A-Za-z0-9.-]+$ ]] || fail "HEALTHCHECK_HOST must be a hostname" 2
validate_https_origin "PUBLIC_API_BASE_URL" "$PUBLIC_API_BASE_URL"
validate_https_origin "PUBLIC_WEB_BASE_URL" "$PUBLIC_WEB_BASE_URL"
[[ "$BACKEND_SHA" =~ ^[0-9a-f]{40}$ ]] || fail "BACKEND_SHA must be a 40-character lowercase SHA" 2
[[ "$RELEASE_NAME" =~ ^[A-Za-z0-9._-]+$ ]] || fail "RELEASE_NAME contains unsupported characters" 2
[[ "$PROBE_ID" =~ ^[A-Za-z0-9._-]+$ ]] || fail "PROBE_ID contains unsupported characters" 2
[[ "$TIMEOUT" =~ ^[1-9][0-9]*$ ]] || fail "TIMEOUT must be a positive integer" 2

command -v curl >/dev/null 2>&1 || fail "curl is required" 2
command -v jq >/dev/null 2>&1 || fail "jq is required" 2

BODY_FILE="$(mktemp "${TMPDIR:-/tmp}/fermatmind-post-deploy.XXXXXX")"
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

ok=1
check_url "public flags API" "${PUBLIC_API_BASE_URL}/api/v0.3/flags" "200" || ok=0
check_url \
  "public Big Five Personality API" \
  "${PUBLIC_API_BASE_URL}/api/v0.5/personality-content-assets/big_five/hub/big-five?locale=zh-CN" \
  "200" \
  "FermatMindReleaseProbe/${PROBE_ID}" || ok=0
check_url "public SEO sitemap source" "${PUBLIC_API_BASE_URL}/api/v0.5/seo/sitemap-source" "200" || ok=0
check_url "public occupations API" "${PUBLIC_API_BASE_URL}/api/v0.5/career/datasets/occupations?locale=zh-CN" "200" || ok=0
check_url "public career jobs API" "${PUBLIC_API_BASE_URL}/api/v0.5/career/jobs?locale=zh-CN" "200" || ok=0
check_url "held career job API" "${PUBLIC_API_BASE_URL}/api/v0.5/career/jobs/software-developers?locale=zh-CN" "404" || ok=0
check_url "public Big Five hub" "${PUBLIC_WEB_BASE_URL}/zh/personality/big-five" "200" || ok=0
check_url "public sitemap" "${PUBLIC_WEB_BASE_URL}/sitemap.xml" "200" || ok=0
check_url "public llms entry" "${PUBLIC_WEB_BASE_URL}/llms.txt" "200" || ok=0
check_url "public llms full" "${PUBLIC_WEB_BASE_URL}/llms-full.txt" "200" || ok=0
check_url "public robots" "${PUBLIC_WEB_BASE_URL}/robots.txt" "200" || ok=0

if [ "$ok" -eq 1 ]; then
  echo "backend sha accepted: ${BACKEND_SHA}"
  echo "release name accepted: ${RELEASE_NAME}"
  echo "[smoke] target-node health and all public checks passed"
  exit 0
fi

echo "[smoke] one or more checks failed"
exit 1
