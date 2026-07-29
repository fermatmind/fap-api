#!/usr/bin/env bash
set -euo pipefail

SCRIPT_PATH="${BASH_SOURCE[0]}"
if [[ "$SCRIPT_PATH" != */* ]]; then
  SCRIPT_PATH="./$SCRIPT_PATH"
fi
SCRIPT_DIR="$(cd "${SCRIPT_PATH%/*}" && pwd)"
# shellcheck source=backend/scripts/ci/require_tools.sh
source "${SCRIPT_DIR}/ci/require_tools.sh"
require_tools rg

BACKEND_DIR="$(cd "${SCRIPT_DIR}/.." && pwd)"
ARTIFACT_DIR="${BACKEND_DIR}/artifacts/pr75"
REPORT_FILE="${ARTIFACT_DIR}/service_env_usage.txt"

mkdir -p "${ARTIFACT_DIR}"

set +e
rg -n "\\benv\\(" "${BACKEND_DIR}/app" > "${REPORT_FILE}"
RG_STATUS=$?
set -e

case "$RG_STATUS" in
  0)
    echo "[PR75][FAIL] env() must not be used in app/ (runtime services/controllers)."
    echo "[PR75][FAIL] Details: ${REPORT_FILE}"
    cat "${REPORT_FILE}"
    exit 1
    ;;
  1)
    ;;
  *)
    echo "[PR75][FAIL] rg guard execution failed (exit ${RG_STATUS}); no success result was produced." >&2
    exit "$RG_STATUS"
    ;;
esac

echo "[PR75][OK] app/ has no direct env() usage; runtime config is read via config()." | tee "${REPORT_FILE}"
