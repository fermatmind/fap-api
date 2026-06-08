# Science ContentPage GPT-5.5 Pro Review Draft Package

Status: draft-only, no-write, not for publication.

This package splits the GPT-5.5 Pro content asset into three lanes:

- `review_audit.md`: internal red-team audit, information architecture critique, and rewrite strategy. Not a CMS draft candidate.
- `pages/*.md`: the only files referenced by `manifest.json`; these are the six backend-compatible ContentPage draft candidates.
- `operator_review.md`: operator claim notes, visible FAQ review notes, internal link suggestions, checklist, and final QA table. Not a CMS draft candidate.

Hard boundaries:

- No CMS mutation.
- No database import.
- No real import command enablement.
- No publish.
- No sitemap, llms, footer, search submission, or distribution.
- `/method-boundaries` remains an existing-authority revision proposal, not a new ContentPage record.

Dry-run command:

```bash
cd backend && php artisan content-pages:science-draft-dry-run --package=../backend/docs/seo/import-packages/science-contentpage-gpt55-review-draft-2026-06-08
cd backend && php artisan content-pages:science-pre-import-qa --package=../backend/docs/seo/import-packages/science-contentpage-gpt55-review-draft-2026-06-08
```
