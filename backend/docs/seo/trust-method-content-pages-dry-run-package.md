# Trust And Method ContentPage Dry-Run Package

Task: FA30-API-08

This package adds a backend-only ContentPage dry-run package for trust and method page candidates. It is not a CMS write, publish action, deploy, search submission, sitemap update, llms update, canonical update, JSON-LD update, queue job, scheduler change, or frontend change.

## Candidate Pages

| Slug | Page type | Status | Public | Indexable | Publish allowed |
| --- | --- | --- | --- | --- | --- |
| `assessment-method` | `methodology` | `draft` | false | false | false |
| `score-interpretation-boundaries` | `boundary` | `draft` | false | false | false |
| `data-privacy-method` | `privacy` | `draft` | false | false | false |
| `review-and-evidence-process` | `trust` | `draft` | false | false | false |

## Dry-Run Command

```bash
cd backend
php artisan content-pages:import-local-baseline --dry-run --status=draft --source-dir=docs/seo/import-packages/trust-method-content-pages-dry-run
```

Expected local empty-database dry-run summary:

- `dry_run=1`
- `status_mode=draft`
- `pages_found=4`
- `will_create=4`
- `will_update=0`
- `will_skip=0`

## Gates

- The package is draft-only.
- All rows are `isPublic=false` and `isIndexable=false`.
- All rows set `publish_allowed=false`.
- All rows require operator approval.
- All rows keep `claim_gate_status=not_reviewed`.
- Schema remains disabled.
- No package row may include private URLs, payment references, tokens, credentials, DB URLs, or Redis URLs.

## Deferred

- CMS write or production import.
- Operator review.
- Claim gate review.
- Legal review for privacy or boundary pages.
- Publication.
- Sitemap, llms, canonical, hreflang, JSON-LD, or footer exposure.
- Search submission.
- Frontend rendering.
