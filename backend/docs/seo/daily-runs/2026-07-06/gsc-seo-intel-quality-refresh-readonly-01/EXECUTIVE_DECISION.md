# GSC-SEO-INTEL-QUALITY-REFRESH-READONLY-01

Date: 2026-07-06
Repo: fap-api
Scope: generated-only / read-only GSC and seo_intel quality refresh
Runtime impact: none
CMS impact: none
Search submission impact: none
Deploy impact: none

## Decision

Decision output: `gsc_seo_intel_quality_refresh_completed_gate_blocked`

Current GSC / seo_intel data quality remains blocked for SEO repair decisions. No CTR, impression, average-position, query-page, title/meta/H1/FAQ, internal-link, Search Channel, or CMS repair should be triggered from current GSC evidence.

The repository has the right quality-gate architecture, but this read-only scan did not find passable live read-model evidence for the current SEO/GEO PR train. The active safe state remains:

- GSC live API disabled by default.
- External API calls disabled by default.
- GSC quality gate requires `data_origin=live_gsc_api`.
- Fixture/mock/static/unknown origins are forbidden.
- Opportunity queue must consume only `seo_intel` read-model rows that passed the quality gate.
- Raw GSC payloads, raw URLs, raw queries, credentials, and sidecar direct consumption remain forbidden.

## Current Status

| Gate | Current result | Evidence |
| --- | --- | --- |
| GSC data origin | Blocked | `backend/docs/seo/generated/gsc-data-quality-gate.v1.json` records current foundation status as fixture and `opportunity_queue_eligible=false`. |
| Live API readiness | Not activated | `.env.example` defaults `SEO_INTEL_GSC_ENABLED=false`, `SEO_INTEL_GSC_LIVE_API_ENABLED=false`, and `SEO_INTEL_ALLOW_EXTERNAL_API_CALLS=false`. |
| Read model target | Present but not sufficient by itself | `seo_gsc_daily` exists with hashed URL/query fields, metrics, metadata, and idempotency key support. |
| Quality gate logic | Present | `GscDataQualityGate` checks source engine, allowed data origin, forbidden origins, report date, finalization lag, freshness, and required metrics. |
| Opportunity queue use | Not allowed from current evidence | Contracts require read-model rows after quality gate pass; sidecar artifacts are not queue inputs. |
| Search/CMS action | Not authorized | No Search Channel enqueue, CMS write, sitemap submit, URL Inspection request, or indexing action is allowed by this PR. |

## Decision For Next SEO Train

Proceed to:

`RESULT-INTERPRETATION-PAGE-INVENTORY-READONLY-01`

Reason: GSC/seo_intel is not yet passable for repair selection. The train should continue with repository/runtime inventory work that does not depend on GSC metrics.

## Stop Boundary For Future Repair PRs

Do not open title/meta/H1/FAQ/CTR repair PRs from GSC until a later PR proves all of these:

1. data rows are `source_engine=google`;
2. `data_origin=live_gsc_api`;
3. row source is not fixture/mock/static/unknown;
4. report dates satisfy finalization lag and freshness window;
5. rows include `canonical_url_hash`, `query_hash`, `clicks`, and `impressions`;
6. opportunity candidates read from imported `seo_intel` rows, not raw sidecar artifacts;
7. no purchase, order, payment, or private-result truth is inferred from GSC.

## Deferred Items

This PR intentionally does not:

- call Google Search Console;
- read, print, validate, or store credentials;
- import or backfill `seo_gsc_daily`;
- run migrations;
- enable scheduler or queue workers;
- enqueue Search Channel rows;
- submit sitemap or URLs;
- request indexing;
- write CMS or runtime code;
- access GA or use analytics as purchase truth.
