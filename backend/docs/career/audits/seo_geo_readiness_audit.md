# Career SEO/GEO Readiness Audit

AUDIT-7 adds a read-only static SEO/GEO readiness layer for the Career 2786 canonical eligibility audit train. It consumes backend artifact arrays and checks whether expected public-resolution rows carry the static signals needed for search, sitemap, LLMS, dataset/search, structured metadata, and AI citation readiness.

AUDIT-7 does not fetch live HTML, modify fap-web, deploy, mutate DB state, apply rollout, backfill data, or implement the full `career:audit-canonical-eligibility` command.

## Inputs

The auditor accepts:

- AUDIT-2 normalized plan rows, array rows, or slug lists
- expected locales such as `["en", "zh"]`
- backend artifact arrays with rows under `items`, `rows`, `seo_geo.items`, `seo_geo.rows`, `projection.items`, or `truth.items`

Rows are matched by lowercase `slug` or `canonical_slug` plus lowercase `locale`. Tests use synthetic rows only.

## Checks

For each expected slug and locale, AUDIT-7 checks:

- canonical self path
- robots indexability
- sitemap eligibility
- LLMS eligibility
- LLMS-full eligibility
- structured metadata readiness
- dataset eligibility
- search eligibility
- AI citation metadata readiness

The canonical self path defaults to `/{locale}/career/jobs/{slug}`. The auditor can derive a path from `canonical_url` when `canonical_path` is not supplied.

## Issue Reasons

- `canonical_not_self`
- `robots_noindex`
- `sitemap_missing`
- `llms_missing`
- `llms_full_missing`
- `structured_data_missing`
- `dataset_missing`
- `search_missing`
- `citation_metadata_missing`

## AUDIT-1 Layer Status

Each row emits an AUDIT-1-compatible `seo_geo` layer status:

```json
{
  "layer": "seo_geo",
  "status": "pass",
  "reasons": [],
  "evidence": [
    {
      "slug": "actuaries",
      "locale": "en"
    },
    {
      "canonical_path": "/en/career/jobs/actuaries",
      "canonical_self": true
    }
  ],
  "source": "seo_geo_artifacts"
}
```

Blocked rows use `status=blocked` and include the static readiness reason codes.

## Non-Goals

AUDIT-7 does not:

- inspect live HTML
- call fap-web
- deploy
- mutate DB or runtime state
- run rollout apply/backfill/rollback/quarantine
- audit API/live surfaces
- generate expansion manifests
- claim 2786 readiness

## Consumption By AUDIT-8+

AUDIT-8 should consume AUDIT-7 output as the static SEO/GEO prerequisite before API or optional live HTML surface checks. Missing SEO/GEO signals should remain distinct from surface mismatches so future reports can distinguish backend static readiness from runtime surface rendering.
