#!/usr/bin/env bash
set -euo pipefail

SCRIPT_DIR="$(cd "$(dirname "${BASH_SOURCE[0]}")" && pwd)"
REPO_DIR="$(cd "${SCRIPT_DIR}/../.." && pwd)"
cd "${REPO_DIR}"

fail() {
  echo "[CAE_GATE][FAIL] $*" >&2
  exit 1
}

echo "[CAE_GATE] start"

if [ -f "backend/.env" ]; then
  fail "backend/.env exists in workspace"
fi

tracked_env_hits="$(git ls-files | grep -E '(^|/)\.env(\.[^/]+)?$' | grep -vE '(^|/)\.env\.example$' || true)"
if [ -n "${tracked_env_hits}" ]; then
  echo "${tracked_env_hits}" >&2
  fail "tracked .env files detected"
fi

debug_hits="$(grep -nEi '^[[:space:]]*APP_DEBUG[[:space:]]*=[[:space:]]*"?true"?[[:space:]]*(#.*)?$' backend/.env.example || true)"
if [ -n "${debug_hits}" ]; then
  echo "${debug_hits}" >&2
  fail "APP_DEBUG=true found in backend/.env.example"
fi

bad_sensitive_lines="$(
awk '
function trim(s){ sub(/^[ \t\r\n]+/, "", s); sub(/[ \t\r\n]+$/, "", s); return s }
function strip_quotes(s) {
  s = trim(s)
  if ((s ~ /^".*"$/) || (s ~ /^'\''.*'\''$/)) return substr(s, 2, length(s)-2)
  return s
}
function is_sensitive(k) {
  return (k ~ /(_SECRET|_TOKEN|_PASSWORD)$/ || k == "APP_KEY" || k == "AWS_SECRET_ACCESS_KEY")
}
function is_placeholder(v) {
  return (v == "" || v == "null" || v == "NULL" || v == "(production_value_required)" || v == "<required>" || v == "<placeholder>" || v == "CHANGE_ME" || v == "changeme")
}
{
  raw = $0
  if (raw ~ /^[ \t]*#/ || raw ~ /^[ \t]*$/) next
  eq = index(raw, "=")
  if (eq == 0) next
  key = trim(substr(raw, 1, eq - 1))
  val = strip_quotes(substr(raw, eq + 1))
  if (is_sensitive(key) && !is_placeholder(val)) {
    printf("%d:%s=%s\n", NR, key, val)
  }
}
' backend/.env.example
)"
if [ -n "${bad_sensitive_lines}" ]; then
  echo "${bad_sensitive_lines}" >&2
  fail "non-empty sensitive keys found in backend/.env.example"
fi

required_dockerignore_lines="
**/.env
**/.env.*
backend/.env
backend/.env.*
backend/storage/logs
backend/storage/framework/cache
backend/storage/framework/sessions
backend/storage/framework/views
"
while IFS= read -r line; do
  [ -z "${line}" ] && continue
  grep -Fx -- "${line}" .dockerignore >/dev/null || fail ".dockerignore missing: ${line}"
done <<< "${required_dockerignore_lines}"

echo "CAE_GATE_PASS"
