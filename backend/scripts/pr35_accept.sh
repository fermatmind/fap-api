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

ART_DIR="backend/artifacts/pr35"
mkdir -p "${ART_DIR}"

for p in 1835 18000; do
  pid_list="$(lsof -ti tcp:${p} || true)"
  [ -n "${pid_list}" ] && echo "${pid_list}" | xargs kill -9 || true
done

cd backend
composer install --no-interaction --no-progress

: > /tmp/pr35.sqlite
DB_CONNECTION=sqlite DB_DATABASE=/tmp/pr35.sqlite php artisan migrate:fresh --force

cd ..
bash backend/scripts/pr35_verify.sh

{
  echo "PASS: pr35 acceptance"
  echo "ART_DIR=${ART_DIR}"
  echo "SERVE_PORT=1835"
} > "${ART_DIR}/summary.txt"

bash backend/scripts/sanitize_artifacts.sh 35 || true

rm -f /tmp/pr35.sqlite || true
