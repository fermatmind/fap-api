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
ART_DIR="${BACKEND_DIR}/artifacts/pr68"
SERVE_PORT="${SERVE_PORT:-1868}"

mkdir -p "${ART_DIR}"
exec > "${ART_DIR}/pr68_accept.log" 2>&1

cleanup_port() {
  local port="$1"
  lsof -nP -iTCP:"${port}" -sTCP:LISTEN || true
  local pid_list
  pid_list="$(lsof -ti tcp:"${port}" || true)"
  if [ -n "${pid_list}" ]; then
    echo "${pid_list}" | xargs kill -9 || true
  fi
  lsof -nP -iTCP:"${port}" -sTCP:LISTEN || true
}

cleanup() {
  cleanup_port "${SERVE_PORT}"
  cleanup_port 18000
}
trap cleanup EXIT

cleanup_port "${SERVE_PORT}"
cleanup_port 18000

bash -n "${BACKEND_DIR}/scripts/pr68_accept.sh"
bash -n "${BACKEND_DIR}/scripts/pr68_verify.sh"

cd "${BACKEND_DIR}"
composer_audit_with_retry() {
  local attempt
  for attempt in 1 2 3; do
    if composer audit --no-interaction --ignore-unreachable; then
      return 0
    fi
    if [ "${attempt}" -lt 3 ]; then
      echo "composer audit failed (attempt ${attempt}/3), retrying..."
      sleep 5
    fi
  done
  return 1
}

composer install --no-interaction --no-progress | tee "${ART_DIR}/composer_install.txt"
composer validate --strict | tee "${ART_DIR}/composer_validate.txt"
composer_audit_with_retry | tee "${ART_DIR}/composer_audit.txt"
cd "${REPO_DIR}"

ART_DIR="${ART_DIR}" bash "${BACKEND_DIR}/scripts/pr68_verify.sh"

git --no-pager diff --name-only -- .github/workflows > "${ART_DIR}/workflow_changed_files.txt" || true

{
  echo "PR68 Acceptance Summary"
  echo "- pass_items:"
  echo "  - composer_install: PASS"
  echo "  - composer_validate_strict: PASS"
  echo "  - composer_audit_no_interaction: PASS"
  echo "  - pr68_verify: PASS"
  echo "- key_assertions:"
  echo "  - php_version_84_hits_file: ${ART_DIR}/workflow_php_version_84.txt"
  echo "  - php_version_non84_hits_file: ${ART_DIR}/workflow_php_version_non84.txt"
  echo "  - composer_gate_report: ${ART_DIR}/workflow_composer_gate_report.txt"
  echo "- workflow_changes:"
  cat "${ART_DIR}/workflow_changed_files.txt"
} > "${ART_DIR}/summary.txt"

test -f "${BACKEND_DIR}/scripts/sanitize_artifacts.sh" && bash "${BACKEND_DIR}/scripts/sanitize_artifacts.sh" 68 || true

if grep -R -n -E "FAP_ADMIN_TOKEN=|Authorization: Bearer|BEGIN PRIVATE KEY|password=|DB_PASSWORD=|/Users/|/home/|/private/" "${ART_DIR}" >/dev/null; then
  grep -R -n -E "FAP_ADMIN_TOKEN=|Authorization: Bearer|BEGIN PRIVATE KEY|password=|DB_PASSWORD=|/Users/|/home/|/private/" "${ART_DIR}" > "${ART_DIR}/sanitize_failures.txt" || true
  cat "${ART_DIR}/sanitize_failures.txt" >&2 || true
  echo "[PR68][ACCEPT][FAIL] artifact sanitization check failed" >&2
  exit 1
fi

bash -n "${BACKEND_DIR}/scripts/pr68_accept.sh"
bash -n "${BACKEND_DIR}/scripts/pr68_verify.sh"

echo "[PR68][ACCEPT] pass"
