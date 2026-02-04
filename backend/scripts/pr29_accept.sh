#!/usr/bin/env bash
set -euo pipefail

export CI=true
export FAP_NONINTERACTIVE=1
export COMPOSER_NO_INTERACTION=1
export GIT_TERMINAL_PROMPT=0
export NO_COLOR=1
export PAGER=cat
export GIT_PAGER=cat
export TERM=dumb
export XDEBUG_MODE=off

REPO_DIR="$(cd "$(dirname "${BASH_SOURCE[0]}")/../.." && pwd)"
BACKEND_DIR="${REPO_DIR}/backend"
ART_DIR="${BACKEND_DIR}/artifacts/pr29"
SERVE_PORT="${SERVE_PORT:-1829}"
DB_PATH="/tmp/pr29.sqlite"

mkdir -p "${ART_DIR}"
mkdir -p "${BACKEND_DIR}/storage/framework/cache" \
  "${BACKEND_DIR}/storage/framework/sessions" \
  "${BACKEND_DIR}/storage/framework/views" \
  "${BACKEND_DIR}/storage/framework/testing" \
  "${BACKEND_DIR}/bootstrap/cache"

# Port cleanup
for p in "${SERVE_PORT}" 18000; do
  lsof -ti tcp:${p} | xargs -r kill -9 || true
  lsof -nP -iTCP:${p} -sTCP:LISTEN || true
  lsof -ti tcp:${p} | xargs -r kill -9 || true
  lsof -nP -iTCP:${p} -sTCP:LISTEN || true
  done

export APP_ENV=testing
export DB_CONNECTION=sqlite
export DB_DATABASE="${DB_PATH}"

rm -f "${DB_DATABASE}"
touch "${DB_DATABASE}"

cd "${BACKEND_DIR}"
composer install --no-interaction --no-progress

SERVE_PORT="${SERVE_PORT}" bash "${BACKEND_DIR}/scripts/pr29_verify.sh"

cd "${REPO_DIR}"

bash "${BACKEND_DIR}/scripts/ci_verify_mbti.sh"

echo "== git status after ci_verify_mbti"
git status -sb
git status -sb | grep -E '^\?\?\s+content_packages/MBTI-CN-v' >/dev/null && {
  echo "[FAIL] legacy alias leaked: content_packages/MBTI-CN-v*"
  echo "== ls -la content_packages | grep MBTI-CN-v =="
  ls -la content_packages | grep -E 'MBTI-CN-v' || true
  exit 1
} || true

SUMMARY_TXT="${ART_DIR}/summary.txt"
ORDER_NO="$(cat "${ART_DIR}/order_no.txt" 2>/dev/null || true)"
ATTEMPT_ID="$(cat "${ART_DIR}/attempt_id.txt" 2>/dev/null || true)"
ORG_ID="$(cat "${ART_DIR}/org_id.txt" 2>/dev/null || true)"
LOCKED_BEFORE="$(cat "${ART_DIR}/locked_before.txt" 2>/dev/null || true)"
LOCKED_AFTER_PAID="$(cat "${ART_DIR}/locked_after_paid.txt" 2>/dev/null || true)"
LOCKED_AFTER_REFUND="$(cat "${ART_DIR}/locked_after_refund.txt" 2>/dev/null || true)"

cat > "${SUMMARY_TXT}" <<TXT
PR29 Acceptance Summary
- verify: backend/scripts/pr29_verify.sh
- serve_port: ${SERVE_PORT}
- org_id: ${ORG_ID}
- attempt_id: ${ATTEMPT_ID}
- order_no: ${ORDER_NO}
- locked_before: ${LOCKED_BEFORE}
- locked_after_paid: ${LOCKED_AFTER_PAID}
- locked_after_refund: ${LOCKED_AFTER_REFUND}
- ci_verify_mbti: PASS (alias cleaned)
Artifacts:
- backend/artifacts/pr29/skus.json
- backend/artifacts/pr29/attempt_start.json
- backend/artifacts/pr29/submit.json
- backend/artifacts/pr29/report_before.json
- backend/artifacts/pr29/order_create_1.json
- backend/artifacts/pr29/order_create_2.json
- backend/artifacts/pr29/webhook_paid.json
- backend/artifacts/pr29/report_after_paid.json
- backend/artifacts/pr29/webhook_refund.json
- backend/artifacts/pr29/report_after_refund.json
TXT

bash "${BACKEND_DIR}/scripts/sanitize_artifacts.sh" 29

# Shellcheck: verify scripts are syntactically valid
bash -n "${BACKEND_DIR}/scripts/pr29_verify.sh"
bash -n "${BACKEND_DIR}/scripts/pr29_accept.sh"
