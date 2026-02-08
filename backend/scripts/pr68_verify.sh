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
ART_DIR="${ART_DIR:-${BACKEND_DIR}/artifacts/pr68}"
WORKFLOW_DIR="${REPO_DIR}/.github/workflows"

mkdir -p "${ART_DIR}"
exec > "${ART_DIR}/verify.log" 2>&1

fail() {
  echo "[PR68][VERIFY][FAIL] $*"
  exit 1
}

echo "[PR68][VERIFY] start"

WORKFLOW_LIST="${ART_DIR}/workflows_list.txt"
WORKFLOW_PHP84="${ART_DIR}/workflow_php_version_84.txt"
WORKFLOW_NON84="${ART_DIR}/workflow_php_version_non84.txt"
WORKFLOW_INSTALL_FILES="${ART_DIR}/workflow_composer_install_files.txt"
WORKFLOW_GATE_REPORT="${ART_DIR}/workflow_composer_gate_report.txt"

find "${WORKFLOW_DIR}" -type f \( -name "*.yml" -o -name "*.yaml" \) | sort > "${WORKFLOW_LIST}"

grep -R -n -E "^[[:space:]]*php-version:[[:space:]]*['\"]?8\\.4" "${WORKFLOW_DIR}" > "${WORKFLOW_PHP84}" || true
if [ ! -s "${WORKFLOW_PHP84}" ]; then
  fail "no php-version 8.4 lines found"
fi

grep -R -n -E "^[[:space:]]*php-version:[[:space:]]*['\"]?8\\.(0|1|2|3|5|6|7|8|9)" "${WORKFLOW_DIR}" > "${WORKFLOW_NON84}" || true
if [ -s "${WORKFLOW_NON84}" ]; then
  fail "found non-8.4 php-version lines; see ${WORKFLOW_NON84}"
fi

grep -R -l -E "composer[[:space:]]+install" "${WORKFLOW_DIR}" | sort > "${WORKFLOW_INSTALL_FILES}" || true
if [ ! -s "${WORKFLOW_INSTALL_FILES}" ]; then
  fail "no workflow contains composer install"
fi

: > "${WORKFLOW_GATE_REPORT}"
while IFS= read -r wf; do
  install_count="$(grep -c -E "composer[[:space:]]+install" "${wf}" || true)"
  validate_count="$(grep -c -E "composer[[:space:]]+validate[[:space:]]+--strict" "${wf}" || true)"
  audit_count="$(grep -c -E "composer[[:space:]]+audit[[:space:]]+--no-interaction" "${wf}" || true)"

  echo "${wf}: install=${install_count} validate=${validate_count} audit=${audit_count}" >> "${WORKFLOW_GATE_REPORT}"

  if [ "${validate_count}" -lt 1 ]; then
    fail "missing composer validate --strict in ${wf}"
  fi
  if [ "${audit_count}" -lt 1 ]; then
    fail "missing composer audit --no-interaction in ${wf}"
  fi
done < "${WORKFLOW_INSTALL_FILES}"

echo "verify=pass" > "${ART_DIR}/verify_done.txt"
echo "[PR68][VERIFY] pass"
