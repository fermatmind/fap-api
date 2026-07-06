# PR Train Sidecar Issues

## fap-api MBTI CMS import gate local main sync blocker

- repo: `fap-api`
- PR id / branch: `MBTI-CMS-26` / `codex/mbti-cms-26-content15-mixed-import-preflight`
- blocker type: `local_main_sync_blocked_by_unrelated_staged_files`
- evidence:
  - PR #2771 is merged at `2026-07-06T04:12:02Z`.
  - `origin/main` contains merge commit `63f1f9b35d90d3f409468640fd1ede3604f7e909`.
  - Remote branch `codex/mbti-cms-26-content15-mixed-import-preflight` is deleted.
  - Local task worktree `/Users/rainie/Desktop/GitHub/fap-api-mbti-cms-26` and local task branch were deleted.
  - Local `main` is checked out in `/Users/rainie/Desktop/GitHub/fap-api-mbti-main-free-faq-content-01` and has unrelated staged files under `backend/docs/seo/daily-runs/2026-07-05/...`.
- why not current PR scope:
  - The staged files belong to a separate SEO/diagnostic daily-runs task and are outside MBTI-CMS-26, MBTI-CMS-27, and MBTI-CMS-28 scope.
  - Touching, unstaging, stashing, or rebasing that worktree would risk altering user/other-task work.
- whether required checks are affected: `false`
  - MBTI-CMS-26 required GitHub checks already passed and PR #2771 is merged.
  - This affects only local `main` sync cleanliness, not the merged PR content or remote branch cleanup.
- recommended follow-up:
  - Finish, commit, stash, or explicitly discard the unrelated staged daily-runs files in `/Users/rainie/Desktop/GitHub/fap-api-mbti-main-free-faq-content-01`.
  - Then run `git -C /Users/rainie/Desktop/GitHub/fap-api-mbti-main-free-faq-content-01 pull --ff-only origin main` and verify `main == origin/main`.

## IQ-METHOD-PAGES-ZH-CN-CMS-READBACK-01

- repo: `fap-api`
- branch: `codex/iq-method-pages-cms-readback-01`
- blocker type: `external_full_suite_failure_timeout`
- evidence:
  - `cd backend && composer test` exceeded the Composer process timeout of 300 seconds and exited with code 1.
  - Failures observed before timeout included:
    - `Tests\Unit\Domain\Career\Audit\CareerFullVisiblePublicationGateTest`
    - `Tests\Unit\Services\Content\CulturalCalibrationLayerServiceTest`
    - `Tests\Unit\Services\Mbti\MbtiResultPersonalizationServiceTest`
    - `Tests\Unit\Services\Report\Pdf\Mbti\MbtiPdfPayloadBuilderTest`
    - `Tests\Feature\Architecture\ServiceLayerBoundaryTest`
    - `Tests\Feature\Career\CareerCnProxyPublicOwnerApiTest`
    - `Tests\Feature\Career\CareerJobDetailApiTest`
    - `Tests\Feature\CareerCms\ArticleBaselineImportTest`
    - `Tests\Feature\ClinicalCombo68\ClinicalComboQuestionsMetaComplianceTest`
    - `Tests\Feature\Commerce\PostPaidAttemptMismatchRepairOpsTenantVisibilityAcceptanceTest`
  - Focused READBACK validation passed: command discovery, targeted Pint, `ArticleIqMethodPagesReadbackCommandTest`, BigFive runtime freeze readback exemption, route:list, real fap-web package import+readback on temp sqlite, YAML/JSON parse, and git diff check.
- why not current PR scope:
  - Current PR scope only adds the read-only IQ method pages CMS readback command/test, command registration, Big Five runtime-freeze classifier exemption for that command, and PR-train metadata.
  - The observed full-suite failures are in Career, Content, MBTI personalization/PDF, Architecture, ClinicalCombo, and Commerce surfaces outside the changed READBACK files.
- whether required checks are affected:
  - Unknown until GitHub checks run; local focused validation for current scope passed.
  - If a required GitHub check fails, inspect the failing job before merge.
- recommended follow-up:
  - Track and fix the failing Career/Content/MBTI/PDF/Architecture/ClinicalCombo/Commerce tests under their owning PR scopes.
  - Do not mix those repairs into the IQ method pages CMS readback PR.

## PR-C verify_mbti local server unavailable

- repo: `fermatmind/fap-api`
- PR id / branch: PR-C / `codex/big5-production-content-audit-c`
- blocker type: `local_environment_unavailable`
- evidence: `bash scripts/verify_mbti.sh` failed at `[1/8] health: http://127.0.0.1:1827` with `curl: (7) Failed to connect to 127.0.0.1 port 1827`.
- why not current PR scope: PR-C only adds a read-only Big Five production content audit command, focused tests, and generated evidence. It does not start, stop, configure, or route the local API server.
- whether required checks are affected: Local `verify_mbti.sh` could not run without the expected local server. Targeted PHPUnit, syntax, Pint, command discovery, route list, and scope validation passed.
- recommended follow-up: Run `bash scripts/verify_mbti.sh` in an environment where the Laravel API is listening on `127.0.0.1:1827`, or use the repository's standard local stack bootstrap before this check.

## BIG5-SITEMAP-LLMS-PERSONALITY-ASSET-14 broad sitemap filter existing failures

- repo: `fermatmind/fap-api`
- PR id / branch: BIG5-SITEMAP-LLMS-PERSONALITY-ASSET-14 / `codex/big5-sitemap-llms-personality-asset-14`
- blocker type: `existing_broad_seo_test_failure`
- evidence: `php artisan test --filter=Sitemap` failed in `Tests\Feature\SeoIntel\EnParity03ContentPagesParityImportPackageTest::deferred_missing_english_pages_do_not_enter_sitemap` because `/en/foundation` is present, and `Tests\Feature\SeoIntel\GlobalEnZhParityP001ContentHelpPolicyDiscoverabilityTest::backend_sitemap_source_does_not_emit_missing_content_help_policy_urls` because `/en/support` is present.
- why not current PR scope: PR14 only adds Big Five `personality_public_content_assets` enumeration and targeted SEO tests. It does not change `ContentPage`, static index URL policy, English foundation/support content, or SeoIntel parity rules.
- whether required checks are affected: Targeted Big Five checks pass. The broad local `Sitemap` filter is affected by pre-existing non-Big-Five failures.
- recommended follow-up: Create a separate content/help policy sitemap cleanup PR for `/en/foundation` and `/en/support` exposure rules.

## MONEY-INTENT-OWNER-PAGE-RUNTIME-RECONCILE-01 generic owner and GSC gate partial

- repo: `fap-api`
- PR id / branch: `MONEY-INTENT-OWNER-PAGE-RUNTIME-RECONCILE-01` / `codex/money-intent-owner-page-runtime-reconcile-01`
- blocker type: `external_generic_owner_and_gsc_quality_gate_partial`
- evidence:
  - Six direct zh assessment owner pages returned HTTP 200, self canonical, `index, follow`, and matching title/H1 evidence.
  - `/zh/tests` is a plausible broad `免费测试` owner with HTTP 200, self canonical, `index, follow`, title `测评入口中心`, and H1 `免费测试`.
  - `免费性格测试` and `免费职业测试` still need unique-owner proof because `/zh/tests`, `/zh/personality`, `/zh/career`, and the RIASEC landing can overlap in intent.
  - Prior P5 evidence keeps GSC/seo_intel quality blocked for write decisions.
- why not current PR scope:
  - Current PR scope is read-only runtime reconciliation and generated evidence only.
  - It is not authorized to write title/meta/H1/FAQ/internal links, mutate CMS/registry data, or run live GSC imports.
- whether required checks are affected: `false`
- recommended follow-up:
  - Complete `MONEY-INTENT-CANNIBALIZATION-LINK-GRAPH-READONLY-01`.
  - Complete the GSC/read-model quality proof card before any TDK/CTR repair selection.
- whether train continued: `true`

## MONEY-INTENT-TDK-DRYRUN-CANDIDATE-SELECTION-01 GSC gate blocked

- repo: `fap-api`
- PR id / branch: `MONEY-INTENT-TDK-DRYRUN-CANDIDATE-SELECTION-01` / `codex/money-intent-tdk-dryrun-candidate-selection-01`
- blocker type: `blocked_by_gsc_data_quality`
- evidence:
  - `GSC-SEO-INTEL-QUALITY-REFRESH-READONLY-01` records the current gate as blocked.
  - Current evidence does not prove `data_origin=live_gsc_api`.
  - Current evidence does not prove opportunity queue eligibility.
  - TDK/CTR repair selection is forbidden from fixture/mock/static/unknown data.
- why not current PR scope:
  - Current PR is generated-only blocked reporting.
  - It is not authorized to call live GSC, import data, write CMS fields, write title/meta/H1/FAQ, or submit Search URLs.
- whether required checks are affected: `false`
- recommended follow-up:
  - Complete `GSC-SEO-INTEL-LIVE-READMODEL-QUALITY-PROOF-READONLY-01` or a separately authorized equivalent before any TDK/CTR selection.
- whether train continued: `true`

## SECURITY-169-API-06 external dirty main worktree

- repo: `fermatmind/fap-api`
- PR id / branch: SECURITY-169-API-06 / `codex/security-169-api-06-fix-public-question-pack-cache-privacy`
- blocker type: `external_dirty_local_main_worktree`
- evidence: `/Users/rainie/Desktop/GitHub/fap-api-mbti-main-free-faq-content-01` held `main` with pre-existing staged files under `backend/docs/seo/daily-runs/2026-07-05/...`. The worktree was fast-forwarded to `origin/main` at `98b3986355bb85491989aa57a55e04d2d0436122`, but those staged SEO files remain.
- why not current PR scope: the staged files are SEO daily-run documentation paths, unrelated to API06 tenant/org scale registry and question-pack cache privacy.
- whether required checks are affected: `false`
- recommended follow-up: finish, stash, or commit the SEO daily-run staged files in their owning task/thread; do not attach them to SECURITY-169 API PR scopes.
- whether train continued: `true`

## RIASEC-SIX-TYPE-PAGES-ENTITY-GRAPH-MATRIX-01 authority ambiguity

- repo: `fap-api`
- PR id / branch: `RIASEC-SIX-TYPE-PAGES-ENTITY-GRAPH-MATRIX-01` / `codex/riasec-six-type-pages-entity-graph-matrix-01`
- blocker type: `blocked_by_authority_ambiguity`
- evidence:
  - `ScaleRegistrySeeder` defines RIASEC and names the six Holland/RIASEC dimensions.
  - `RiasecPackLoader` and RIASEC result projection services support assessment/result contracts.
  - Current generated-only scan did not prove canonical public owner routes for the six individual RIASEC type pages.
- why not current PR scope:
  - Current PR is generated-only evidence reporting.
  - It is not authorized to create routes, write CMS content, mutate sitemap/llms/schema/canonical/noindex, or edit fap-web.
- whether required checks are affected: `false`
- recommended follow-up:
  - Run a separate `RIASEC-SIX-TYPE-PUBLIC-OWNER-ROUTE-DESIGN-01` generated-only design PR before any runtime/CMS/discoverability implementation.
- whether train continued: `true`

## CAREER-MAJOR-PAGES-ENTITY-GRAPH-MATRIX-01 authority ambiguity

- repo: `fap-api`
- PR id / branch: `CAREER-MAJOR-PAGES-ENTITY-GRAPH-MATRIX-01` / `codex/career-major-pages-entity-graph-matrix-01`
- blocker type: `blocked_by_authority_ambiguity`
- evidence:
  - Career job/detail backend controllers, occupation tables, and indexability fields exist.
  - Sitemap source filtering references runtime-published career job detail URLs.
  - Standalone major-page owner routes, CMS authority, and indexability policy were not proven in current generated-only scan.
- why not current PR scope:
  - Current PR is generated-only evidence reporting.
  - It is not authorized to create routes, import CMS content, mutate sitemap/llms/schema/canonical/noindex, or edit fap-web.
- whether required checks are affected: `false`
- recommended follow-up:
  - Run `CAREER-PAGES-RUNTIME-DISCOVERABILITY-READBACK-01` for career pages.
  - Run `MAJOR-PAGES-PUBLIC-AUTHORITY-DESIGN-01` before any standalone major-page implementation.
- whether train continued: `true`

## GSC-SEO-INTEL-LIVE-READMODEL-QUALITY-PROOF-READONLY-01 GSC data quality

- repo: `fap-api`
- PR id / branch: `GSC-SEO-INTEL-LIVE-READMODEL-QUALITY-PROOF-READONLY-01` / `codex/gsc-seo-intel-live-readmodel-quality-proof-readonly-01`
- blocker type: `blocked_by_gsc_data_quality`
- evidence:
  - Existing generated gate evidence records `data_origin=fixture`.
  - `opportunity_queue_eligible=false`.
  - Required pass condition is `data_origin=live_gsc_api` plus sanitized read-model quality gate pass.
- why not current PR scope:
  - Current PR is generated-only proof reporting.
  - It is not authorized to call live GSC, read credentials, import `seo_gsc_daily`, write CMS, enqueue Search Channel, or submit URLs.
- whether required checks are affected: `false`
- recommended follow-up:
  - Separately authorize sanitized live read-only GSC evidence capture and dry-run import readback before any CTR/TDK queue.
- whether train continued: `true`

## CTR-REPAIR-LOOP-ELIGIBILITY-READONLY-01 GSC data quality

- repo: `fap-api`
- PR id / branch: `CTR-REPAIR-LOOP-ELIGIBILITY-READONLY-01` / `codex/ctr-repair-loop-eligibility-readonly-01`
- blocker type: `blocked_by_gsc_data_quality`
- evidence:
  - Current GSC quality proof records `data_origin=fixture`.
  - `opportunity_queue_eligible=false`.
  - CTR repair loop requires passable live read-model rows before selecting pages.
- why not current PR scope:
  - Current PR is generated-only eligibility reporting.
  - It is not authorized to call GSC, import rows, write CMS fields, enqueue Search Channel, or edit title/meta/H1/FAQ.
- whether required checks are affected: `false`
- recommended follow-up:
  - Re-run CTR eligibility only after sanitized live GSC evidence and read-model quality gate pass.
- whether train continued: `true`

## CTR-TDK-REPAIR-DRYRUN-QUEUE-01 GSC data quality

- repo: `fap-api`
- PR id / branch: `CTR-TDK-REPAIR-DRYRUN-QUEUE-01` / `codex/ctr-tdk-repair-dryrun-queue-01`
- blocker type: `blocked_by_gsc_data_quality`
- evidence:
  - CTR eligibility is blocked.
  - No passable live GSC read-model row set exists.
  - Candidate queue size is 0.
- why not current PR scope:
  - Current PR is generated-only dry-run queue reporting.
  - It is not authorized to select from fixture/static evidence, edit TDK, write CMS, or enqueue Search Channel.
- whether required checks are affected: `false`
- recommended follow-up:
  - Re-run dry-run queue only after CTR eligibility passes from gated live `seo_intel` rows.
- whether train continued: `true`

## SECURITY-169-API-07 external dirty main worktree

- repo: `fermatmind/fap-api`
- PR id / branch: SECURITY-169-API-07 / `codex/security-169-api-07-harden-attempt-start-retake-analytics-abuse-cont`
- blocker type: `external_dirty_local_main_worktree`
- evidence:
  - PR #2797 merged on GitHub with merge commit `4161bef5e7e30930a630b3284ddf4ecb26208692`.
  - `origin/main` contains `4161bef5e7e30930a630b3284ddf4ecb26208692`.
  - Remote task branch `codex/security-169-api-07-harden-attempt-start-retake-analytics-abuse-cont` was deleted by merge cleanup.
  - Local `gh pr merge --squash --delete-branch` reported `fatal: 'main' is already used by worktree at '/Users/rainie/Desktop/GitHub/fap-api-mbti-main-free-faq-content-01'` after the remote merge completed.
  - That main worktree still has pre-existing staged SEO daily-run docs under `backend/docs/seo/daily-runs/2026-07-05/...`.
- why not current PR scope:
  - The staged SEO daily-run docs predate API07 and are unrelated to attempt start, retake, analytics abuse controls, or the scoped Big Five CI classifier fix.
  - API07 implementation and GitHub checks were complete before the local main worktree cleanup conflict surfaced.
- whether required checks are affected: `false`
- recommended follow-up:
  - Finish, stash, or commit the SEO daily-run staged files in their owning task/thread.
  - Do not attach those staged docs to SECURITY-169 API PR scopes.
  - Use an isolated PR-train worktree from `origin/main` for ledger closeout and next API PRs until the user-owned main worktree is clean.
- whether train continued: `true`
