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
ART_DIR="${BACKEND_DIR}/artifacts/pr56"

mkdir -p "${ART_DIR}"
exec >"${ART_DIR}/pr56_accept.log" 2>&1

echo "[PR56][ACCEPT] start"
echo "[PR56][ACCEPT] repo=${REPO_DIR}"
echo "[PR56][ACCEPT] art_dir=${ART_DIR}"

cd "${BACKEND_DIR}"
composer install --no-interaction --no-progress
composer validate --strict >"${ART_DIR}/composer_validate.txt"
composer audit --no-interaction >"${ART_DIR}/composer_audit.txt"
composer --version >"${ART_DIR}/composer_version.txt"
cd "${REPO_DIR}"

bash backend/scripts/pr56_verify.sh

bash -n backend/scripts/pr56_accept.sh >"${ART_DIR}/pr56_accept_syntax.txt"
bash -n backend/scripts/pr56_verify.sh >"${ART_DIR}/pr56_verify_syntax.txt"

git status -sb >"${ART_DIR}/git_status_sb.txt"
git --no-pager diff --name-only >"${ART_DIR}/changed_files.txt"

PHP_VERSION_LINES_COUNT="$(wc -l < "${ART_DIR}/workflow_php_version_lines.txt" | awk '{print $1}')"
INSTALL_FILE_COUNT="$(wc -l < "${ART_DIR}/workflow_composer_install_files.txt" | awk '{print $1}')"

{
  echo "PR56 Acceptance Summary"
  echo "repo=${REPO_DIR}"
  echo "composer_version=$(cat "${ART_DIR}/composer_version.txt")"
  echo "verify_status=$(cat "${ART_DIR}/pr56_verify_status.txt")"
  echo "php_version_line_count=${PHP_VERSION_LINES_COUNT}"
  echo "composer_install_workflow_count=${INSTALL_FILE_COUNT}"
  echo "composer_platform=$(cat "${ART_DIR}/composer_platform_php.txt")"
  echo ""
  echo "Changed files:"
  cat "${ART_DIR}/changed_files.txt"
  echo ""
  echo "Workflow gate report:"
  cat "${ART_DIR}/workflow_composer_gate_report.txt"
  echo ""
  echo "Composer validate result:"
  sed -n '1,20p' "${ART_DIR}/composer_validate.txt"
  echo ""
  echo "Composer audit result:"
  sed -n '1,40p' "${ART_DIR}/composer_audit.txt"
} >"${ART_DIR}/summary.txt"

test -f backend/scripts/sanitize_artifacts.sh && bash backend/scripts/sanitize_artifacts.sh 56 || true

echo "[PR56][ACCEPT] pass"
