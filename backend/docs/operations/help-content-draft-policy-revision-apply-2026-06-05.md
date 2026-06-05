# HELP-CONTENT-DRAFT-POLICY-REVISION-APPLY-01

Decision: `LOCAL_IMPORT_PACKAGE_REVISED_WITH_CMS_SYNC_AND_PUBLISH_BLOCKED`

This PR applies the policy-owner answers and direct-email support decision to the local Help service import package only. It does not mutate CMS rows, publish pages, deploy, submit search URLs, access private result/order/share/pay/payment/history URLs, read secrets/env/cookies/tokens, run payment/refund actions, change payment-provider behavior, or claim Operator approval.

## Inputs

- Policy answers: `backend/docs/help/generated/help-content-draft-policy-owner-answers-01.v1.json`
- Contact support decision: `backend/docs/help/generated/help-content-draft-contact-support-decision-01.v1.json`
- Import package: `backend/docs/help/import-packages/help-service-content-drafts-01.import.v1.json`
- ContentPage source rows: `backend/docs/help/import-packages/content-pages-draft-source/content_pages.help_service_drafts_01.json`

## Applied Facts

- Support contact mode: direct email
- Support contact: `support@fermatmind.com`
- Contact-support route dependency: removed from the package gate
- Refund exclusion answer: policy owner supplied
- Refund handling-time answer: policy owner supplied
- Data-deletion handling-time answer: policy owner supplied
- Retained-data exception answer: policy owner supplied
- Privacy analytics wording confirmation: policy owner supplied

## Package Checks

- Targets: 12
- ContentPage source rows: 12
- Locales: `zh-CN`, `en`
- First-class source fields present: `support_contact`, `policy_version`, `reviewer`, `faq_items`, `schema_enabled`
- `support_contact`: `support@fermatmind.com`
- `policy_version`: `help_service_policy.v1`
- `schema_enabled`: `false`
- Draft only: yes
- Non-public: yes
- Non-indexable: yes
- Published: no
- Operator review required: yes

## Guardrails

- CMS mutation: not performed
- Publish: not performed
- Deploy: not performed
- Search submission: not performed
- Payment/refund action: not performed
- Private URL access: not performed
- Secret/env/cookie/token read: not performed
- Operator approval claimed: no

## Sidecars

- `HELP-CONTENT-DRAFT-POLICY-CMS-SYNC-01-AUTHORIZATION`: syncing the revised package to the 12 existing CMS draft rows requires separate explicit CMS mutation authorization.
- `HELP-CONTENT-DRAFT-OPERATOR-REVIEW-R2-01`: review can be requested after CMS draft sync; Codex must not claim review passed.
- `HELP-CONTENT-DRAFT-PUBLISH-BLOCK`: publish remains blocked until Operator review passes, publish preflight passes, and exact publish authorization is provided.

## Validation

```bash
python3 -m json.tool backend/docs/help/import-packages/help-service-content-drafts-01.import.v1.json >/dev/null
python3 -m json.tool backend/docs/help/import-packages/content-pages-draft-source/content_pages.help_service_drafts_01.json >/dev/null
python3 -m json.tool backend/docs/help/generated/help-content-draft-policy-revision-apply-01.v1.json >/dev/null
php -l backend/tests/Feature/Cms/HelpContentImportPackageTest.php
cd backend && APP_ENV=testing DB_CONNECTION=sqlite DB_DATABASE=':memory:' php artisan test --filter=HelpContentImportPackageTest --no-ansi
python3 -m json.tool docs/codex/pr-train-state.json >/dev/null
python3 -c "import yaml, pathlib; yaml.safe_load(pathlib.Path('docs/codex/pr-train.yaml').read_text()); print('yaml ok')"
git diff --check -- backend/docs/help/import-packages/help-service-content-drafts-01.import.v1.json backend/docs/help/import-packages/content-pages-draft-source/content_pages.help_service_drafts_01.json backend/docs/help/generated/help-content-draft-policy-revision-apply-01.v1.json backend/docs/operations/help-content-draft-policy-revision-apply-2026-06-05.md backend/tests/Feature/Cms/HelpContentImportPackageTest.php docs/codex/pr-train.yaml docs/codex/pr-train-state.json
git diff --cached --check
```
