# SCIENCE-CONTENTPAGE-REAL-IMPORT-COMMAND-ENABLEMENT-01

## Decision
GO for controlled CMS draft import after this PR is merged, GitHub checks are green, and the operator provides the exact approval phrase.

NO-GO for production import, publish, sitemap, llms, footer, search submission, or social distribution inside this PR.

## Command
`content-pages:science-import-drafts`

Default no-write dry-run:

```bash
cd backend && php artisan content-pages:science-import-drafts --package=../backend/docs/seo/import-packages/science-contentpage-gpt55-review-draft-2026-06-08
```

Controlled execute form for a later task:

```bash
cd backend && php artisan content-pages:science-import-drafts --package=../backend/docs/seo/import-packages/science-contentpage-gpt55-review-draft-2026-06-08 --execute --approval-phrase=SCIENCE_CONTENTPAGE_NON_PUBLIC_DRAFT_IMPORT_APPROVED
```

## Guardrails
- `--execute` and `--dry-run` cannot be combined.
- `--execute` requires exact approval phrase: `SCIENCE_CONTENTPAGE_NON_PUBLIC_DRAFT_IMPORT_APPROVED`.
- Dry-run can continue no-write when local DB lookup is unavailable; existing-row status is then `Unknown`.
- Execute mode still requires a live database connection.
- Existing ContentPage rows are skipped, not updated.
- `/method-boundaries` is skipped as existing authority revision-only.
- Created rows are draft, non-public, non-indexable, and `publish_allowed=false`.
- `faq_schema_eligible=false` and `schema_enabled=false`.
- `published_at`, `operator_approved_at`, and `schema_eligibility_reviewed_at` stay null.

## Expected Plan
- Create missing non-public draft candidates: 5.
- Existing authority revision-only: 1.
- Publish/discoverability: blocked.
- Private routes: blocked by prior pre-import QA gate.

## Deferred
- Controlled CMS draft import.
- Post-import QA.
- Publish/discoverability gate.
- Production no-write shell evidence.
