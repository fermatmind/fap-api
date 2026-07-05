# PR Train Sidecar Issues

## BIG5-CMS-IMPORT-DRYRUN-01 local worktree freeze-test interference

- repo: fap-api
- PR id / branch: BIG5-CMS-IMPORT-DRYRUN-01 / codex/big5-cms-import-dryrun-01
- blocker type: local worktree / branch-diff interference during full local `scripts/ci_verify_mbti.sh`
- evidence: after the CI-scope test fix, focused tests and desktop package dry-run passed, but local full `cd backend && bash scripts/ci_verify_mbti.sh` failed in `BigFiveResultPageV2CoreBodyPreviewTest::test_runtime_paths_have_no_uncommitted_diff` with IQ method files: `backend/app/Console/Commands/ArticleImportIqMethodPagesDraft.php`, `backend/app/Models/TopicProfileEntry.php`, and `backend/app/Services/Cms/IqMethodPages/IqMethodPagesDraftImporter.php`.
- why not current PR scope: `git diff --name-only origin/main...HEAD` in the isolated PR worktree contains only the Big Five CMS import dry-run command, planner, tests, Kernel registration, and PR train metadata. It does not contain the IQ method files.
- whether required checks are affected: local full-check reproduction was affected; GitHub required checks run in a clean checkout and the current PR fix targets the actual GitHub failure, which was the hardcoded desktop package path in the test.
- recommended follow-up: keep IQ method page work isolated in its own branch/PR and avoid sharing dirty local worktree state with PR-train verification worktrees.

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
