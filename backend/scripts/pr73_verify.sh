#!/usr/bin/env bash
set -euo pipefail

SCRIPT_DIR="$(cd "$(dirname "${BASH_SOURCE[0]}")" && pwd)"
BACKEND_DIR="$(cd "${SCRIPT_DIR}/.." && pwd)"
ARTIFACT_DIR="${BACKEND_DIR}/artifacts/pr73"
REPORT_FILE="${ARTIFACT_DIR}/legacy_service_request_coupling.txt"

mkdir -p "${ARTIFACT_DIR}"

TARGETS=(
  "${BACKEND_DIR}/app/Services/Legacy/LegacyReportService.php"
  "${BACKEND_DIR}/app/Services/Legacy/Mbti/Attempt/LegacyMbtiAttemptLifecycleService.php"
)

PATTERN='use Illuminate\\Http\\Request;|\\Illuminate\\Http\\Request|Request \$request'

if rg -n "${PATTERN}" "${TARGETS[@]}" > "${REPORT_FILE}"; then
  echo "[PR73][FAIL] Legacy service layer still depends on Illuminate\\Http\\Request."
  echo "[PR73][FAIL] Details: ${REPORT_FILE}"
  cat "${REPORT_FILE}"
  exit 1
fi

echo "[PR73][OK] Legacy services are decoupled from Illuminate\\Http\\Request." | tee "${REPORT_FILE}"
