# HELP-CONTENTPAGE-IMPORTER-SERVICE-FIELDS-01

Decision: `CONTENT_PAGE_BASELINE_IMPORTER_SUPPORTS_HELP_SERVICE_FIELDS_WITHOUT_CMS_MUTATION`

This PR updates the backend `content-pages:import-local-baseline` importer so local ContentPage source rows can materialize the Help service fields already present on the `ContentPage` model and database schema. It does not mutate CMS rows, publish, deploy, submit search URLs, access private result/order/share/pay/payment/history URLs, read secrets/env/cookies/tokens, run payment/refund actions, change payment-provider behavior, or claim Operator approval.

## Scope

- Importer field support:
  - `support_contact`
  - `policy_version`
  - `reviewer`
  - `faq_items`
  - `schema_enabled`
- Focused contract:
  - Import the existing 12-row Help service source package in draft mode.
  - Assert all 12 imported rows stay draft, non-public, non-indexable, unpublished, and `owner_review`.
  - Assert all 12 rows receive `support@fermatmind.com`, `help_service_policy.v1`, `Unknown`, four FAQ items, and `schema_enabled=false`.
- Runtime-freeze scope guard:
  - Reuse the existing `ContentPagesImportLocalBaseline.php` allowlist evidence test.

## Deferred

- No CMS sync in this PR.
- No production deploy/runtime sync in this PR.
- No Operator review approval claim.
- No publish.

## Next Gate

`HELP-CMS-SERVICE-FIELDS-PROD-RUNTIME-01` should sync production runtime so the deployed importer includes these field mappings. After that, `HELP-CONTENT-DRAFT-POLICY-CMS-SYNC-01` can be re-evaluated for scoped CMS draft sync.

## Validation

```bash
php -l backend/app/Console/Commands/ContentPagesImportLocalBaseline.php
php -l backend/tests/Feature/ContentPages/ContentPagePublicApiTest.php
php -l backend/tests/Unit/Services/BigFive/ResultPageV2/BigFiveResultPageV2CoreBodyPreviewTest.php
python3 -m json.tool backend/docs/help/generated/help-contentpage-importer-service-fields-01.v1.json >/dev/null
python3 -m json.tool docs/codex/pr-train-state.json >/dev/null
python3 -c "import yaml, pathlib; yaml.safe_load(pathlib.Path('docs/codex/pr-train.yaml').read_text()); print('yaml ok')"
cd backend && APP_ENV=testing DB_CONNECTION=sqlite DB_DATABASE=':memory:' php artisan test --filter='ContentPagePublicApiTest::test_help_service_importer_materializes_service_fields_on_draft_rows|ContentPagePublicApiTest::test_help_service_fields_are_first_class_content_page_contracts' --no-ansi
cd backend && APP_ENV=testing DB_CONNECTION=sqlite DB_DATABASE=':memory:' php artisan test --filter='BigFiveResultPageV2CoreBodyPreviewTest::test_runtime_freeze_classifier_ignores_content_pages_local_baseline_import_package_changes|BigFiveResultPageV2CoreBodyPreviewTest::test_runtime_paths_have_no_uncommitted_diff' --no-ansi
git diff --check -- backend/app/Console/Commands/ContentPagesImportLocalBaseline.php backend/tests/Feature/ContentPages/ContentPagePublicApiTest.php backend/tests/Unit/Services/BigFive/ResultPageV2/BigFiveResultPageV2CoreBodyPreviewTest.php backend/docs/help/generated/help-contentpage-importer-service-fields-01.v1.json backend/docs/operations/help-contentpage-importer-service-fields-2026-06-08.md docs/codex/pr-train.yaml docs/codex/pr-train-state.json
git diff --cached --check
```
