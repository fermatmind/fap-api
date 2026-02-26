#!/usr/bin/env bash
set -euo pipefail

SCRIPT_DIR="$(cd "$(dirname "${BASH_SOURCE[0]}")" && pwd)"
BACKEND_DIR="$(cd "${SCRIPT_DIR}/.." && pwd)"
ARTIFACT_DIR="${BACKEND_DIR}/artifacts/pr77"
REPORT_FILE="${ARTIFACT_DIR}/share_flow_alignment.txt"

mkdir -p "${ARTIFACT_DIR}"
cd "${BACKEND_DIR}"

php artisan test tests/Feature/V0_3/ShareFlowCoreAlignmentTest.php | tee "${REPORT_FILE}"
