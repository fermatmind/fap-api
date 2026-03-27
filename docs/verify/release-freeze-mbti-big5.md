# MBTI + BIG5 Release Freeze Verify

当前 MBTI 与 BIG5 主链的 release-freeze backend verify 入口是：

- `backend/scripts/release_freeze_verify.sh`

它显式串起四层证据：

1. legacy isolation
   - `backend/scripts/pr73_verify.sh`
2. current MBTI + BIG5 write-path smoke
   - `tests/Feature/V0_3/MbtiQualityCurrentSubmitTest.php`
   - `tests/Feature/Compliance/Big5DisclaimerAcceptanceTest.php`
   - `tests/Feature/Observability/BigFiveMetricsContractTest.php`
3. current scale gates and BIG5 evidence
   - `tests/Feature/Ops/ScaleIdentityContractCiTest.php`
   - `tests/Feature/Content/BigFiveQuestionsMinCompiledEvidenceContractTest.php`
   - `tests/Feature/Performance/Big5PerfBudgetTest.php`
4. backend contract freeze set
   - `tests/Feature/V0_3/MbtiReadPathParityContractTest.php`
   - `tests/Feature/V0_3/MbtiReportHttpContractRegressionTest.php`
   - `tests/Feature/V0_3/AttemptReportAccessReadTest.php`
   - `tests/Feature/V0_3/MbtiQualityCurrentSubmitTest.php`
   - `tests/Feature/V0_3/AttemptPublicReportPdfParityTest.php`
   - `tests/Feature/V0_3/BigFiveResultEngineFoundationTest.php`
   - `tests/Feature/Attempts/BigFiveHistoryCompareTest.php`
   - `tests/Feature/Report/BigFivePdfDeliveryTest.php`
   - `tests/Feature/V0_3/ShareSummaryContractTest.php`

## Scope

This verify chain is for release hardening only:

- freeze current read-side contracts
- prove MBTI + BIG5 smoke parity
- prove legacy MBTI runtime isolation
- collect release evidence without expanding product scope

## Non-goals

This verify chain does not cover:

- new feature work
- scorer changes
- CMS / analytics expansion
- visual baseline expansion
- marketing or product-surface redesign

## Historical notes

Older files such as `docs/verify/pr52_verify.md` and `docs/verify/pr60_recon.md` are historical PR artifacts. Keep them for audit history, but do not treat them as the current release-freeze authority.
`backend/scripts/pr32_verify.sh` and `docs/verify/pr32_verify.md` are also historical PR-specific artifacts and are no longer part of the active release-freeze evidence chain.
`backend/scripts/ci_verify_scales.sh` remains useful for CI-scale sweeps, but it is not the release-freeze authority because it still preserves older MBTI baseline behavior that is broader than the current MBTI + BIG5 launch surface.
