#!/usr/bin/env bash
set -euo pipefail

SCRIPT_DIR="$(cd "$(dirname "${BASH_SOURCE[0]}")" && pwd)"
REPO_DIR="$(cd "${SCRIPT_DIR}/../../.." && pwd)"
cd "${REPO_DIR}"

sanitize_hits() {
  sed -E \
    -e 's/(APP_DEBUG=).*/\1[REDACTED]/g' \
    -e 's/(APP_KEY=).*/\1[REDACTED]/g' \
    -e 's/(AWS_SECRET_ACCESS_KEY=).*/\1[REDACTED]/g' \
    -e 's/(STRIPE_SECRET=).*/\1[REDACTED]/g' \
    -e 's/(BILLING_WEBHOOK_SECRET=).*/\1[REDACTED]/g' \
    -e 's/(Authorization:).*/\1 [REDACTED]/g'
}

echo "[GUARD] no committed secrets (H-02)"

tracked_env_hits="$(git ls-files | grep -E '(^|/)\.env(\.[^/]+)?$' | grep -vE '(^|/)\.env\.example$' || true)"
if [ -n "${tracked_env_hits}" ]; then
  echo "[GUARD][FAIL] tracked .env files detected:"
  echo "${tracked_env_hits}"
  exit 1
fi

if [ -f backend/.env ]; then
  echo "[GUARD][FAIL] backend/.env exists in workspace"
  exit 1
fi

danger_hits="$(
  grep -R -n -E --binary-files=without-match \
    --exclude-dir=.git \
    --exclude-dir=vendor \
    --exclude-dir=storage \
    --exclude-dir=node_modules \
    --exclude-dir=docs \
    --exclude-dir=scripts \
    --exclude='.env.example' \
    'APP_DEBUG=true|APP_KEY=base64:|AWS_SECRET_ACCESS_KEY=|STRIPE_SECRET=|BILLING_WEBHOOK_SECRET=|Authorization:[[:space:]]*Bearer[[:space:]]+[A-Za-z0-9._-]{20,}' \
    . || true
)"

if [ -n "${danger_hits}" ]; then
  echo "[GUARD][FAIL] dangerous patterns detected:"
  echo "${danger_hits}" | sanitize_hits
  exit 1
fi

echo "[GUARD] PASS"
