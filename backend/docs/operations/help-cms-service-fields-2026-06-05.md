# HELP-CMS-SERVICE-FIELDS-01

Decision: `CONTENT_PAGE_HELP_SERVICE_FIELDS_ADDED_WITHOUT_CMS_MUTATION`

This task adds first-class ContentPage fields required by the Help service content train. It did not publish, deploy, mutate CMS data, submit search URLs, access private result/order/share/pay/payment/history URLs, read secrets/env/cookies/tokens, run payment/refund flows, change payment-provider behavior, rewrite Help content, add frontend fallback content, or claim Operator approval.

## Added ContentPage Authority Fields

| Field | Contract |
| --- | --- |
| `support_contact` | Nullable email string for Help service support contact authority. |
| `policy_version` | Nullable string for policy/review version markers. |
| `reviewer` | Nullable string for reviewer or review-role markers. |
| `faq_items` | Nullable JSON list of `{ question, answer }` items. |
| `schema_enabled` | Boolean gate, default `false`, for backend/CMS schema authority. |

## Surfaces Updated

- ContentPage migration adds the fields without changing existing rows.
- ContentPage model fillable/casts/source hash include the fields.
- Internal ContentPage update validates and saves the fields.
- Public/internal ContentPage payloads expose the fields.
- ContentPage translation revision payloads preserve the fields.
- Filament ContentPage editor exposes the fields.
- Focused ContentPage API test covers draft-only Help service field persistence.
- BigFive runtime-freeze classifier explicitly ignores only the Help service ContentPage field-contract files changed in this PR.

## Remaining Hard Gates

- No CMS draft mutation in this PR.
- No publish without separate exact publish authorization.
- No Operator approval claimed by Codex.
- Help service content package still needs a separate policy/contact revision application PR.
- FAQPage schema runtime remains a later fap-web task after content/schema authority is ready.

## Validation Commands

```bash
php -l backend/app/Models/ContentPage.php
php -l backend/app/Http/Controllers/API/V0_5/Cms/ContentPageController.php
php -l backend/app/Services/Cms/ContentPageTranslationAdapter.php
php -l backend/app/Filament/Ops/Resources/ContentPageResource.php
php -l backend/database/migrations/2026_06_05_150000_add_help_service_fields_to_content_pages.php
php -l backend/tests/Feature/ContentPages/ContentPagePublicApiTest.php
php -l backend/tests/Unit/Services/BigFive/ResultPageV2/BigFiveResultPageV2CoreBodyPreviewTest.php
python3 -m json.tool backend/docs/help/generated/help-cms-service-fields-01.v1.json >/dev/null
python3 -m json.tool docs/codex/pr-train-state.json >/dev/null
python3 -c "import yaml, pathlib; yaml.safe_load(pathlib.Path('docs/codex/pr-train.yaml').read_text()); print('yaml ok')"
cd backend && APP_ENV=testing DB_CONNECTION=sqlite DB_DATABASE=':memory:' php artisan test --filter=ContentPagePublicApiTest --no-ansi
cd backend && APP_ENV=testing DB_CONNECTION=sqlite DB_DATABASE=':memory:' php artisan migrate --pretend --no-ansi --force
cd backend && APP_ENV=testing DB_CONNECTION=sqlite DB_DATABASE=':memory:' php artisan test --filter='BigFiveResultPageV2CoreBodyPreviewTest::test_runtime_freeze_classifier_ignores_content_page_help_service_field_contract_files|BigFiveResultPageV2CoreBodyPreviewTest::test_runtime_paths_have_no_uncommitted_diff' --no-ansi
git diff --check -- backend/app/Models/ContentPage.php backend/app/Http/Controllers/API/V0_5/Cms/ContentPageController.php backend/app/Services/Cms/ContentPageTranslationAdapter.php backend/app/Filament/Ops/Resources/ContentPageResource.php backend/database/migrations/2026_06_05_150000_add_help_service_fields_to_content_pages.php backend/tests/Feature/ContentPages/ContentPagePublicApiTest.php backend/tests/Unit/Services/BigFive/ResultPageV2/BigFiveResultPageV2CoreBodyPreviewTest.php backend/docs/help/generated/help-cms-service-fields-01.v1.json backend/docs/operations/help-cms-service-fields-2026-06-05.md docs/codex/pr-train.yaml docs/codex/pr-train-state.json
git diff --cached --check
```
