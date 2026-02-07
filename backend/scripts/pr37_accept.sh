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

PR_NUM="37"
SERVE_PORT="1837"
ART_DIR="backend/artifacts/pr37"

REPO_DIR="$(cd "$(dirname "${BASH_SOURCE[0]}")/../.." && pwd)"
BACKEND_DIR="${REPO_DIR}/backend"
OUT_DIR="${REPO_DIR}/${ART_DIR}"

DB_CONNECTION=sqlite
DB_DATABASE="/tmp/pr${PR_NUM}.sqlite"
export DB_CONNECTION DB_DATABASE

mkdir -p "${OUT_DIR}"
mkdir -p "$(dirname "${DB_DATABASE}")"
: > "${DB_DATABASE}"

for p in "${SERVE_PORT}" 18000; do
  pid_list="$(lsof -ti tcp:${p} || true)"
  [ -n "${pid_list}" ] && echo "${pid_list}" | xargs kill -9 || true
done

cd "${BACKEND_DIR}"
composer install --no-interaction --no-progress
php artisan migrate:fresh --force | tee "${OUT_DIR}/migrate.log"

cd "${REPO_DIR}"
bash "backend/scripts/pr37_verify.sh"

cat > "${OUT_DIR}/summary.txt" <<TXT
PASS: pr37 acceptance
ART_DIR=${ART_DIR}
SERVE_PORT=${SERVE_PORT}

Checks:
- health endpoint: OK (see ${ART_DIR}/health.json)
- phpunit: StripeSignature + WebhookLock PASS (see ${ART_DIR}/phpunit.log)
TXT

bash "backend/scripts/sanitize_artifacts.sh" "${PR_NUM}"
rm -f "${DB_DATABASE}" || true

bash -n "backend/scripts/pr37_accept.sh"
bash -n "backend/scripts/pr37_verify.sh"
