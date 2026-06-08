# SCIENCE-CONTENTPAGE-REAL-IMPORT-COMMAND-ENABLEMENT-01

## Decision
GO for controlled CMS draft import after this PR is merged, GitHub checks are green, and the operator provides the exact approval phrase.

NO-GO for production import, publish, sitemap, llms, footer, search submission, or social distribution inside this PR.

## 2026-06-08 Closeout Update

The command enablement PR was later followed by:

- fap-api #1983, which removed the production parser dependency blocker.
- A production no-write dry-run that passed with `writes_committed=0`.
- A controlled production execute using the exact approval phrase `SCIENCE_CONTENTPAGE_NON_PUBLIC_DRAFT_IMPORT_APPROVED`.
- A post-execute idempotency dry-run that skipped the five existing rows without creating duplicates.

Controlled import result:

```text
ok=1
mode=execute
dry_run=0
writes_committed=1
pages_seen=6
planned_create_count=5
skipped_existing_count=0
authority_revision_only_count=1
blocked_count=0
created_count=5
publish_allowed=0
discoverability_allowed=0
```

This closeout means controlled non-public draft import is now complete. It does **not** change the publish/discoverability decision: public publish, sitemap, llms, footer, search submission, and social distribution remain NO-GO.

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
- CMS operator publication review.
- Claim/science/legal review closeout.
- Publish/discoverability gate.
- Any public publish or search/distribution action.
