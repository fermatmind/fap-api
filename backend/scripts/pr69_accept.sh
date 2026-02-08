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
ART_DIR="${BACKEND_DIR}/artifacts/pr69"
SERVE_PORT="${SERVE_PORT:-1869}"

mkdir -p "${ART_DIR}"
exec > "${ART_DIR}/pr69_accept.log" 2>&1

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

bash -n "${BACKEND_DIR}/scripts/pr69_accept.sh"
bash -n "${BACKEND_DIR}/scripts/pr69_verify.sh"

{
  php -l "${BACKEND_DIR}/config/queue.php"
  php -l "${BACKEND_DIR}/config/fap.php"
  php -l "${BACKEND_DIR}/app/Providers/AppServiceProvider.php"
  php -l "${BACKEND_DIR}/app/Support/SensitiveDataRedactor.php"
  php -l "${BACKEND_DIR}/app/Http/Controllers/EventController.php"
} > "${ART_DIR}/php_lint.txt"

ART_DIR="${ART_DIR}" bash "${BACKEND_DIR}/scripts/pr69_verify.sh"

cd "${REPO_DIR}"
git --no-pager diff --name-only > "${ART_DIR}/changed_files.txt" || true

{
  echo "PR69 Acceptance Summary"
  echo "- pass_items:"
  echo "  - php_lint: PASS"
  echo "  - pr69_verify: PASS"
  echo "- key_assertions:"
  echo "  - queue_assertions: ${ART_DIR}/queue_assertions.txt"
  echo "  - app_service_provider_assertions: ${ART_DIR}/app_service_provider_assertions.txt"
  echo "  - redactor_assertions: ${ART_DIR}/redactor_assertions.txt"
  echo "  - env_assertions: ${ART_DIR}/env_assertions.txt"
  echo "  - event_controller_assertions: ${ART_DIR}/event_controller_assertions.txt"
  echo "  - migration_assertions: ${ART_DIR}/migration_assertions.txt"
  echo "- changed_files:"
  cat "${ART_DIR}/changed_files.txt"
} > "${ART_DIR}/summary.txt"

test -f "${BACKEND_DIR}/scripts/sanitize_artifacts.sh" && bash "${BACKEND_DIR}/scripts/sanitize_artifacts.sh" 69 || true

if grep -R -n -E "FAP_ADMIN_TOKEN=.+|Authorization: Bearer|BEGIN PRIVATE KEY|password=.+|DB_PASSWORD=.+|/Users/|/home/|/private/" "${ART_DIR}" >/dev/null; then
  grep -R -n -E "FAP_ADMIN_TOKEN=.+|Authorization: Bearer|BEGIN PRIVATE KEY|password=.+|DB_PASSWORD=.+|/Users/|/home/|/private/" "${ART_DIR}" > "${ART_DIR}/sanitize_failures.txt" || true
  cat "${ART_DIR}/sanitize_failures.txt" >&2 || true
  echo "[PR69][ACCEPT][FAIL] artifact sanitization check failed" >&2
  exit 1
fi

bash -n "${BACKEND_DIR}/scripts/pr69_accept.sh"
bash -n "${BACKEND_DIR}/scripts/pr69_verify.sh"

echo "[PR69][ACCEPT] pass"
