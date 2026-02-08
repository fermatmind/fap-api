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
export LANG=en_US.UTF-8

REPO_DIR="$(cd "$(dirname "${BASH_SOURCE[0]}")/../.." && pwd)"
BACKEND_DIR="${REPO_DIR}/backend"
ART_DIR="${ART_DIR:-${BACKEND_DIR}/artifacts/pr63}"

mkdir -p "${ART_DIR}"

cd "${BACKEND_DIR}"
php artisan test --testsuite=Unit 2>&1 | tee "${ART_DIR}/verify.log"

grep -E "PASS|OK \\(" "${ART_DIR}/verify.log" >/dev/null
