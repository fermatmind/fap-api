# SEO Opportunity Queue Read-only Contract

`SEO-OPPORTUNITY-QUEUE-READONLY-01` adds a private Ops API read model for opportunity candidates derived from the `seo_intel` read model.

The endpoint is read-only:

- no CMS draft creation
- no CMS write
- no Search Channel enqueue, approval, retry, or submission
- no Google/Baidu/IndexNow provider call
- no scheduler or queue worker activation
- no raw query or raw private identifier exposure

## Endpoint

- `GET /api/v0.5/ops/seo-intel/opportunity-queue`

The endpoint requires the same private Ops SEO Intel read permission boundary as the other `/api/v0.5/ops/seo-intel/*` routes.

## Source Gate

The read model can return candidate rows only when `GscDataQualityGate` passes on the underlying `seo_gsc_daily` rows. Fixture, mock, static artifact, stale, unknown, incomplete, or non-Google rows block candidate output.

## Candidate Inputs

- `seo_gsc_daily`
- `seo_urls`
- `GscDataQualityGate`

Candidate rows expose safe aliases only: canonical path, URL hash, query hash, masked query, locale, report date, metrics, and a human-review next step.

## Boundary

The read-only opportunity queue is not an execution queue. A later PR must separately define and approve CMS draft dry-runs or Search Channel readiness before any write action.
