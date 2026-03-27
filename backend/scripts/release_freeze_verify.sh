#!/usr/bin/env bash
set -euo pipefail

SCRIPT_DIR="$(cd "$(dirname "${BASH_SOURCE[0]}")" && pwd)"
BACKEND_DIR="$(cd "${SCRIPT_DIR}/.." && pwd)"

echo "[release-freeze][backend] MBTI + BIG5 release freeze verify"
echo "[release-freeze][backend] step 1/4 legacy isolation gate"
bash "${BACKEND_DIR}/scripts/pr73_verify.sh"

echo "[release-freeze][backend] step 2/4 current MBTI + BIG5 write-path smoke"
cd "${BACKEND_DIR}"
php artisan test \
  tests/Feature/V0_3/MbtiQualityCurrentSubmitTest.php \
  tests/Feature/Compliance/Big5DisclaimerAcceptanceTest.php \
  tests/Feature/Observability/BigFiveMetricsContractTest.php

echo "[release-freeze][backend] step 3/4 current scale gates and BIG5 evidence"
php artisan test \
  tests/Feature/Ops/ScaleIdentityContractCiTest.php \
  tests/Feature/Content/BigFiveQuestionsMinCompiledEvidenceContractTest.php \
  tests/Feature/Performance/Big5PerfBudgetTest.php

echo "[release-freeze][backend] step 4/4 contract freeze set"
php artisan test \
  tests/Feature/V0_3/MbtiReadPathParityContractTest.php \
  tests/Feature/V0_3/MbtiReportHttpContractRegressionTest.php \
  tests/Feature/V0_3/AttemptReportAccessReadTest.php \
  tests/Feature/V0_3/MbtiQualityCurrentSubmitTest.php \
  tests/Feature/V0_3/AttemptPublicReportPdfParityTest.php \
  tests/Feature/V0_3/BigFiveResultEngineFoundationTest.php \
  tests/Feature/Attempts/BigFiveHistoryCompareTest.php \
  tests/Feature/Report/BigFivePdfDeliveryTest.php \
  tests/Feature/V0_3/ShareSummaryContractTest.php

echo "[release-freeze][backend] verify ok"
