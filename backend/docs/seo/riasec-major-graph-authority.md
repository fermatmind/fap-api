# RIASEC Major Graph Backend Authority

Task: FA30-API-09

This package establishes a backend-only authority contract for a future RIASEC major-cluster graph. It is not a public API route, frontend renderer, CMS write, publish action, production import, deploy, sitemap update, llms update, canonical update, JSON-LD update, queue job, scheduler change, or search submission.

## Source Package

`backend/docs/seo/import-packages/riasec-major-graph-authority/riasec_major_graph_authority.v1.json`

Each cluster must include:

- `cluster_id`
- `cluster_name`
- `riasec_primary_codes`
- `riasec_secondary_codes`
- `discipline_family`
- `learning_activity_families`
- `work_activity_families`
- `evidence_sources`
- `review_status`
- `claim_tier`
- `indexability_status`

## Audit Command

```bash
cd backend
php artisan riasec:major-graph-authority-audit --json --strict
```

The command is read-only. It parses the local source package, validates required fields and claim boundaries, and emits JSON. It does not write DB rows, CMS rows, public routes, search queues, sitemap, llms, canonical, JSON-LD, or deployment state.

## Claim Boundary

Allowed claim tier for this package is `exploration_only`. A future public surface may only use `reviewed_exploration` after operator review and a separate indexability gate.

Forbidden claim families:

- Best or deterministic major recommendation.
- Gaokao admission or school-fit prediction.
- Employment, salary, or career success prediction.
- Claims that replace a counselor, teacher, admissions advisor, parent, or other qualified human reviewer.
- Guaranteed outcome language.

## Indexability Boundary

All clusters in this package are `noindex`. A future indexable cluster must be separately reviewed, must use the `reviewed_exploration` claim tier, and must not be added to sitemap or llms by this PR.

## Deferred

- Public API route.
- Frontend renderer.
- CMS write or production import.
- Operator review.
- Publication.
- Sitemap, llms, canonical, hreflang, JSON-LD, or search submission.
- Any deterministic education, admissions, job, salary, or success outcome claim.
