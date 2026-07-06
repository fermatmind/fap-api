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
