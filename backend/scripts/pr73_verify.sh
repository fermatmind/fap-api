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
ARTIFACT_DIR="${BACKEND_DIR}/artifacts/pr73"
REPORT_FILE="${ARTIFACT_DIR}/legacy_service_request_coupling.txt"

mkdir -p "${ARTIFACT_DIR}"

TARGETS=(
  "${BACKEND_DIR}/app/Services/Legacy/LegacyReportService.php"
  "${BACKEND_DIR}/app/Services/Legacy/Mbti/Attempt/LegacyMbtiAttemptLifecycleService.php"
)

PATTERN='use Illuminate\\Http\\Request;|\\Illuminate\\Http\\Request|Request \$request'

set +e
rg -n "${PATTERN}" "${TARGETS[@]}" > "${REPORT_FILE}"
RG_STATUS=$?
set -e

case "$RG_STATUS" in
  0)
    echo "[PR73][FAIL] Legacy service layer still depends on Illuminate\\Http\\Request."
    echo "[PR73][FAIL] Details: ${REPORT_FILE}"
    cat "${REPORT_FILE}"
    exit 1
    ;;
  1)
    ;;
  *)
    echo "[PR73][FAIL] rg guard execution failed (exit ${RG_STATUS}); no success result was produced." >&2
    exit "$RG_STATUS"
    ;;
esac

echo "[PR73][OK] Legacy services are decoupled from Illuminate\\Http\\Request." | tee "${REPORT_FILE}"
