# HELP-CMS-IMPORT-PACKAGE-01

## Decision

`PASS_DRAFT_ONLY_IMPORT_PACKAGE_READY`

The uploaded `fermatmind-help-service-content-drafts-01.zip` has been converted into a backend-owned Help `ContentPage` import package. This PR does not create CMS records, publish pages, change frontend runtime behavior, submit URLs, inspect private URLs, read secrets, run payment flows, or modify payment-provider behavior.

## Package Outputs

- Import package: `backend/docs/help/import-packages/help-service-content-drafts-01.import.v1.json`
- Existing importer source dir: `backend/docs/help/import-packages/content-pages-draft-source`
- Existing importer source file: `backend/docs/help/import-packages/content-pages-draft-source/content_pages.help_service_drafts_01.json`
- Generated gate artifact: `backend/docs/help/generated/help-cms-import-package-01.v1.json`

## Target Pages

The package defines six Help service pages in two locales:

- `/zh/help/unlock-failure`
- `/en/help/unlock-failure`
- `/zh/help/payment-refund`
- `/en/help/payment-refund`
- `/zh/help/result-recovery`
- `/en/help/result-recovery`
- `/zh/help/privacy-data`
- `/en/help/privacy-data`
- `/zh/help/use-boundaries`
- `/en/help/use-boundaries`
- `/zh/help/data-deletion`
- `/en/help/data-deletion`

Every target is marked:

- `support_contact=support@fermatmind.com`
- `robots=noindex,nofollow`
- `is_public=false`
- `is_indexable=false`
- `schema_enabled=false`
- `requires_operator_review=true`
- `publish_allowed=false`

## Existing Runtime Fit

The current backend already has the controlled importer:

```text
content-pages:import-local-baseline
```

It supports `--dry-run`, `--status=draft`, and `--source-dir`. Therefore a future scoped draft-create PR can use:

```text
cd backend && php artisan content-pages:import-local-baseline --dry-run --status=draft --source-dir=docs/help/import-packages/content-pages-draft-source
```

No new broad importer runtime is introduced by this PR.

## Deferred Gates

- `HELP-CONTENT-DRAFT-CREATE-01` remains the first PR allowed to create CMS drafts.
- Operator/editorial review remains required before publish preflight can pass.
- Publish remains blocked until the exact user approval phrase is provided.
- Search submission, sitemap exposure, `llms.txt` exposure, paid ads, and production support automation remain out of scope.
