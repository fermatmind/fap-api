# Competitor Alternatives Source Ledger

Task: FA30-API-10

This package establishes a backend-only source-ledger contract for future competitor alternatives pages. It is not a crawler, scraper, public page, public API route, frontend renderer, SEO runtime change, CMS write, production import, deploy, sitemap update, llms update, canonical update, JSON-LD update, queue job, scheduler change, or search submission.

## Source Package

`backend/docs/seo/import-packages/competitor-alternatives-source-ledger/competitor_alternatives_source_ledger.v1.json`

Each ledger entry must include:

- `ledger_id`
- `comparison_surface`
- `operator_reviewed_source_notes`
- `fermatmind_first_party_facts`
- `allowed_claims`
- `forbidden_claims`
- `source_review_status`
- `claim_review_status`
- `legal_review_status`
- `indexability_status`

## Audit Command

```bash
cd backend
php artisan competitor-alternatives:source-ledger-audit --json --strict
```

The command is read-only. It parses the local source package, validates source and claim boundaries, and emits JSON. It does not scrape sites, write DB rows, write CMS rows, create public routes, enqueue search jobs, change SEO runtime, or deploy.

## Source Boundary

Allowed:

- Operator-reviewed source notes.
- First-party FermatMind facts.
- Claim/legal/indexability review states.
- Explicit allowed and forbidden comparison claim boundaries.

Forbidden:

- Scraped competitor descriptions, reviews, ratings, prices, rankings, testimonials, recommendation text, or marketing copy.
- Superiority, ranking, endorsement, price, or review-score claims.
- Public URL authority before separate claim and legal review.

## Indexability Boundary

All entries in this package are `noindex`. A future indexable alternatives page must be separately approved through claim review and legal review, and must be introduced in a separate public runtime PR.

## Deferred

- Public alternatives pages.
- Frontend renderer.
- Public API route.
- CMS write or production import.
- Sitemap, llms, canonical, hreflang, JSON-LD, or search submission.
- Any crawler, scraper, or competitor-content ingestion.
