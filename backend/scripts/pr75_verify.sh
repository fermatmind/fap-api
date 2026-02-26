#!/usr/bin/env bash
set -euo pipefail

SCRIPT_DIR="$(cd "$(dirname "${BASH_SOURCE[0]}")" && pwd)"
BACKEND_DIR="$(cd "${SCRIPT_DIR}/.." && pwd)"
ARTIFACT_DIR="${BACKEND_DIR}/artifacts/pr75"
REPORT_FILE="${ARTIFACT_DIR}/service_env_usage.txt"

mkdir -p "${ARTIFACT_DIR}"

if rg -n "\\benv\\(" "${BACKEND_DIR}/app" > "${REPORT_FILE}"; then
  echo "[PR75][FAIL] env() must not be used in app/ (runtime services/controllers)."
  echo "[PR75][FAIL] Details: ${REPORT_FILE}"
  cat "${REPORT_FILE}"
  exit 1
fi

echo "[PR75][OK] app/ has no direct env() usage; runtime config is read via config()." | tee "${REPORT_FILE}"
