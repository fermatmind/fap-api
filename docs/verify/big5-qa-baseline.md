# Big Five QA Baseline

## 1. Background And Positioning

This document is the Big Five API-side QA baseline. It formalizes the current Big Five (`BIG5_OCEAN`) release rules that were established by:

- PR1: default blocking gates for Big Five in CI and deploy paths.
- PR2: backend canonical truth fixtures for readable Big Five reports.

This is not a Big Five content strategy document, not a scoring model design document, and not an MBTI QA document. MBTI can share infrastructure, but Big Five has its own baseline here.

## 2. Scope

This baseline applies to the Big Five API layer:

- scoring truth and golden score stability
- norms, percentile, band, and validity behavior
- compiled pack integrity and min-compiled evidence
- result, report, report-access, PDF, and history contracts
- non-MBTI contract isolation

This baseline does not apply to MBTI, does not govern Big Five editorial writing, and does not define new runtime behavior.

## 3. P0 Automatic Blocking Items

P0 means stop-ship. A P0 failure blocks merge when it runs in CI and blocks deploy when it runs in the deploy gate.

| P0 area | Test file | Why P0 | Script / gate |
|---|---|---|---|
| Canonical readable truth | `backend/tests/Feature/Content/BigFiveCanonicalTruthFixturesTest.php` | Freezes the current 120Q, 90Q, and degraded readable report truth across submit, result, report, access, history, and PDF. | `backend/scripts/ci_verify_mbti.sh` through `RUN_BIG5_OCEAN_GATE=1`; deploy gate also sets `RUN_BIG5_OCEAN_GATE=1`. |
| Golden scoring and report variants | `backend/tests/Feature/Content/BigFiveGoldenCasesTest.php` | Protects score outputs and current free/full report section contracts from silent drift. | Same Big Five gate. |
| Norm resolver and bands | `backend/tests/Feature/Content/BigFiveNormResolverBandsTest.php` | Protects calibrated norm group selection and percentile band behavior. | Same Big Five gate. |
| Validity and quality | `backend/tests/Feature/Content/BigFiveValidityItemsTest.php` | Protects validity item handling and quality downgrade behavior. | Same Big Five gate. |
| Pack integrity | `backend/tests/Feature/Content/BigFivePackIntegrityTest.php` | Protects direction metadata, question shape, and pack-level scoring inputs. | Same Big Five gate. |
| Min compiled evidence | `backend/tests/Feature/Content/BigFiveQuestionsMinCompiledEvidenceContractTest.php` | Protects min-compiled question evidence and sidecar contract used by release read paths. | Same Big Five gate; broader evidence also appears in `backend/scripts/ci_verify_scales.sh`. |
| Result engine foundation | `backend/tests/Feature/V0_3/BigFiveResultEngineFoundationTest.php` | Protects public projection shape, trait/facet vectors, comparative payload, and report read path projection. | Same Big Five gate. |
| Report block contract | `backend/tests/Feature/Content/BigFiveReportBlocksContractTest.php` | Protects report section/block structure consumed by web result surfaces. | Same Big Five gate. |
| PDF delivery | `backend/tests/Feature/Report/BigFivePdfDeliveryTest.php` | Protects PDF availability and current readable/full PDF headers. | Same Big Five gate. |
| History and compare | `backend/tests/Feature/Attempts/BigFiveHistoryCompareTest.php` | Protects history rows, no-offer state, form metadata, and compare summary. | Same Big Five gate. |
| MBTI isolation | `backend/tests/Feature/V0_3/NonMbtiReportContractRegressionTest.php` | Protects Big Five from accidentally gaining MBTI-only report fields. | Same Big Five gate. |

Current CI source:

- `.github/workflows/ci.yml` sets `RUN_BIG5_OCEAN_GATE=1` for `verify-mbti-${{ matrix.mode }}`.
- `.github/workflows/deploy.yml` sets `RUN_BIG5_OCEAN_GATE=1` before deploy verification.
- `backend/scripts/ci_verify_mbti.sh` runs the Big Five gate by executing `content:lint`, `content:compile`, `verify_big5_norms.sh`, and `php artisan test --filter '(BigFive|Big5|NonMbtiReportContractRegressionTest)'`.

Release-freeze relationship:

- `backend/scripts/release_freeze_verify.sh` remains the mixed MBTI + Big Five release-freeze evidence entry.
- It currently covers Big Five disclaimer, metrics, min-compiled evidence, performance, result foundation, history, and PDF delivery.
- It is not the full Big Five QA baseline by itself. The full P0 Big Five baseline is the default Big Five gate in `backend/scripts/ci_verify_mbti.sh` with `RUN_BIG5_OCEAN_GATE=1`.
- For Big Five release approval, use this document as the stop-ship authority and use `backend/scripts/release_freeze_verify.sh` as the release-freeze evidence bundle.

## 4. P1 Automatic Warning Items

P1 means release warning. These checks should be reviewed before release, but they are not the Big Five P0 stop-ship baseline unless explicitly enabled in the release gate.

| P1 area | Current evidence | Why P1 |
|---|---|---|
| Broader scale sweep | `backend/scripts/ci_verify_scales.sh` | Runs a broader scales-level chain and can request Big Five evidence with `RUN_BIG5_OCEAN_GATE=1`, but it is not the current Big Five release-freeze authority. |
| Telemetry summary | `backend/scripts/ci_verify_scales.sh` calls `big5:telemetry:summary` | Useful for drift and operational monitoring; should warn on missing or suspicious telemetry but should not redefine scoring truth. |
| Commerce reconcile smoke | `backend/scripts/ci_verify_scales.sh` calls `commerce:reconcile` | Useful for operational release confidence; not part of Big Five scoring/report truth. |
| Perf budget | `backend/scripts/ci/verify_big5_perf.sh` through the scales script | Important for release health, but should be treated as P1 unless explicitly promoted to a P0 release gate. |

## 5. P2 Manual Review Items

P2 means manual release review. These items do not automatically fail CI, but release owners should record the review outcome before production promotion.

- Confirm any canonical truth fixture update has a written reason and is paired with a scoring/report/access contract explanation.
- Confirm any `norms_version` change has a release note and is expected for 90Q and 120Q behavior.
- Review the degraded canonical case and confirm low-quality behavior still produces readable report/access/PDF/history contracts.
- Spot-check PDF, history, and report-access on one 120Q sample and one 90Q sample.
- Confirm Big Five remains separate from MBTI report projection and no MBTI-only fields are introduced.

## 6. Canonical Truth Samples

| Fixture | Scenario | Coverage | Primary consumer |
|---|---|---|---|
| `backend/tests/Fixtures/big5/canonical_120_readable.truth.json` | 120Q readable/full report | start, questions, submit, result, report, report-access, history, PDF, trait vector, 30 facets, norms, quality, 13 report sections | `BigFiveCanonicalTruthFixturesTest.php` |
| `backend/tests/Fixtures/big5/canonical_90_readable.truth.json` | 90Q readable/full report | same current product semantics as 120Q while preserving form metadata and question count differences | `BigFiveCanonicalTruthFixturesTest.php` |
| `backend/tests/Fixtures/big5/canonical_degraded.truth.json` | degraded quality readable/full report | attention-check failure and degraded quality behavior without falling back to locked/preview semantics | `BigFiveCanonicalTruthFixturesTest.php` |

These fixtures are backend truth fixtures. Frontend heavy fixtures should map to these scenarios but must not become scoring truth.

## 7. Gate Mapping

| QA clause | Test file | Fixture / truth | Script | Workflow / deploy gate | Blocking level |
|---|---|---|---|---|---|
| Canonical readable truth | `BigFiveCanonicalTruthFixturesTest.php` | `canonical_120_readable`, `canonical_90_readable`, `canonical_degraded` | `backend/scripts/ci_verify_mbti.sh` | `.github/workflows/ci.yml`; `.github/workflows/deploy.yml` | P0 stop-ship |
| Golden scoring/report | `BigFiveGoldenCasesTest.php` | compiled golden cases | `backend/scripts/ci_verify_mbti.sh` | CI and deploy Big Five gate | P0 stop-ship |
| Norms/bands | `BigFiveNormResolverBandsTest.php` | norms import seed and resolver outputs | `backend/scripts/ci_verify_mbti.sh` | CI and deploy Big Five gate | P0 stop-ship |
| Validity/quality | `BigFiveValidityItemsTest.php` | validity item metadata and quality flags | `backend/scripts/ci_verify_mbti.sh` | CI and deploy Big Five gate | P0 stop-ship |
| Pack integrity | `BigFivePackIntegrityTest.php` | `BIG5_OCEAN` compiled pack | `backend/scripts/ci_verify_mbti.sh` | CI and deploy Big Five gate | P0 stop-ship |
| Min compiled evidence | `BigFiveQuestionsMinCompiledEvidenceContractTest.php` | `questions.min.compiled.json` content evidence | `backend/scripts/ci_verify_mbti.sh`; `backend/scripts/ci_verify_scales.sh` | CI and release evidence | P0 in Big Five gate; P1 broader scale sweep |
| Result/report projection | `BigFiveResultEngineFoundationTest.php`; `BigFiveReportBlocksContractTest.php` | public projection and report blocks | `backend/scripts/ci_verify_mbti.sh` | CI and deploy Big Five gate | P0 stop-ship |
| PDF/history/access | `BigFivePdfDeliveryTest.php`; `BigFiveHistoryCompareTest.php` | readable/full access and PDF contracts | `backend/scripts/ci_verify_mbti.sh` | CI and deploy Big Five gate | P0 stop-ship |
| Non-MBTI isolation | `NonMbtiReportContractRegressionTest.php` | Big Five/SDS non-MBTI contract shape | `backend/scripts/ci_verify_mbti.sh` | CI and deploy Big Five gate | P0 stop-ship |
| Telemetry/perf/reconcile | scale-level telemetry/perf/reconcile checks | operational evidence | `backend/scripts/ci_verify_scales.sh` | release evidence when invoked | P1 warning unless promoted |
| Release-freeze evidence bundle | `Big5DisclaimerAcceptanceTest.php`; `BigFiveMetricsContractTest.php`; `BigFiveQuestionsMinCompiledEvidenceContractTest.php`; `Big5PerfBudgetTest.php`; `BigFiveResultEngineFoundationTest.php`; `BigFiveHistoryCompareTest.php`; `BigFivePdfDeliveryTest.php` | release smoke, evidence, access, history, PDF | `backend/scripts/release_freeze_verify.sh` | manual release-freeze run | P0 for release-freeze execution; not a replacement for full P0 baseline |
| Manual sample review | release owner checklist | 120Q, 90Q, degraded samples | none | release owner record | P2 manual |

## 8. Release Failure Handling

- Any P0 test failure blocks merge and deploy. Do not bypass by updating fixtures unless the scoring/report/access truth change is intentional and reviewed.
- Any CI or deploy gate failure caused by `RUN_BIG5_OCEAN_GATE=1` is a Big Five release blocker.
- P1 failures should be recorded as release warnings. Release owners may proceed only when the warning is understood and not tied to score/report correctness.
- P2 findings should be recorded in release notes or closeout notes. They should become P0/P1 only when they reveal a repeatable contract failure.

## 9. Non-Goals

- Do not change MBTI.
- Do not change the Big Five scoring algorithm.
- Do not change Big Five runtime behavior.
- Do not change Big Five editorial/body content.
- Do not create a second verify system.
