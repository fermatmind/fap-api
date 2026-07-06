# PR Train Sidecar Issues

## BIG5-CMS-IMPORT-DRYRUN-01 local worktree freeze-test interference

- repo: fap-api
- PR id / branch: BIG5-CMS-IMPORT-DRYRUN-01 / codex/big5-cms-import-dryrun-01
- blocker type: local worktree / branch-diff interference during full local `scripts/ci_verify_mbti.sh`
- evidence: after the CI-scope test fix, focused tests and desktop package dry-run passed, but local full `cd backend && bash scripts/ci_verify_mbti.sh` failed in `BigFiveResultPageV2CoreBodyPreviewTest::test_runtime_paths_have_no_uncommitted_diff` with IQ method files: `backend/app/Console/Commands/ArticleImportIqMethodPagesDraft.php`, `backend/app/Models/TopicProfileEntry.php`, and `backend/app/Services/Cms/IqMethodPages/IqMethodPagesDraftImporter.php`.
- why not current PR scope: `git diff --name-only origin/main...HEAD` in the isolated PR worktree contains only the Big Five CMS import dry-run command, planner, tests, Kernel registration, and PR train metadata. It does not contain the IQ method files.
- whether required checks are affected: local full-check reproduction was affected; GitHub required checks run in a clean checkout and the current PR fix targets the actual GitHub failure, which was the hardcoded desktop package path in the test.
- recommended follow-up: keep IQ method page work isolated in its own branch/PR and avoid sharing dirty local worktree state with PR-train verification worktrees.

## BIG5-CMS-FAQ-DEDUPE-02 local worktree freeze-test interference

- repo: fap-api
- PR id / branch: BIG5-CMS-FAQ-DEDUPE-02 / codex/big5-cms-faq-dedupe-02
- blocker type: local worktree / branch-diff interference during full local `scripts/ci_verify_mbti.sh`
- evidence: PR2 focused syntax, PHPUnit, desktop package dry-run, Pint, YAML/JSON parse, and diff checks passed, but local full `cd backend && bash scripts/ci_verify_mbti.sh` failed in `BigFiveResultPageV2CoreBodyPreviewTest::test_runtime_paths_have_no_uncommitted_diff` with the same IQ method files: `backend/app/Console/Commands/ArticleImportIqMethodPagesDraft.php`, `backend/app/Models/TopicProfileEntry.php`, and `backend/app/Services/Cms/IqMethodPages/IqMethodPagesDraftImporter.php`.
- why not current PR scope: PR2 only changes `BigFiveCmsImportDraftDryRunPlanner.php`, `PersonalityBigFiveCmsImportDraftDryRunCommandTest.php`, PR train metadata, and this sidecar record. It does not add or modify the IQ method files.
- whether required checks are affected: local full-check reproduction was affected. GitHub required checks run in a clean checkout and should validate the actual PR2 scope without the local IQ branch-diff interference.
- recommended follow-up: isolate or merge/close the IQ method branch separately; do not use that local branch state as a blocker for Big Five CMS import readiness PRs.

## IQ-METHOD-PAGES-ZH-CN-CMS-IMPORT-01

- repo: `fap-api`
- branch: `codex/iq-method-pages-cms-import-01`
- blocker type: `external_full_suite_failure_timeout`
- evidence:
  - `cd backend && composer test` exited with code 1 after Composer process timeout at 300 seconds.
  - Before timeout, unrelated failures were observed in:
    - `Tests\Unit\Services\Content\CulturalCalibrationLayerServiceTest`
    - `Tests\Feature\Architecture\ServiceLayerBoundaryTest`
    - `Tests\Feature\Career\CareerCnProxyPublicOwnerApiTest`
    - `Tests\Feature\Career\CareerJobDetailApiTest`
    - `Tests\Feature\CareerCms\ArticleBaselineImportTest`
  - Focused rerun of `ServiceLayerBoundaryTest` reported existing HTTP helper violations in:
    - `backend/app/Services/SeoIntel/CrawlerLog/CrawlerLogFixtureParser.php`
    - `backend/app/Services/Iq/IqOwnerOriginal30BankService.php`
- why not current PR scope:
  - Current PR scope only adds the IQ method pages CMS draft importer command/service/test, registers the command, extends the topic group whitelist, and updates PR-train metadata.
  - The observed full-suite failures are in Content, Architecture, Career, and CareerCms surfaces outside the changed IQ method importer files.
- whether required checks are affected:
  - Local focused validation for this PR passed.
  - GitHub required checks must still be inspected after PR creation; if a required check fails, inspect the failing job before merge.
- recommended follow-up:
  - Track and fix the failing Content/Career/Architecture tests under their owning PR scopes.
  - Do not mix those repairs into the IQ method pages CMS import PR.

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
