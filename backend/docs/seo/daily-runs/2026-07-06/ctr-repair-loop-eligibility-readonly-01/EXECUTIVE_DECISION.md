# CTR-REPAIR-LOOP-ELIGIBILITY-READONLY-01

Date: 2026-07-06
Repository: fermatmind/fap-api
Scope: generated docs only

## Decision

Final verdict: BLOCKED_BY_GSC_DATA_QUALITY

The CTR repair loop is not eligible to run because current GSC/seo_intel evidence does not prove passable `live_gsc_api` read-model rows.

## Eligibility Summary

- Required: `GscDataQualityGate.status=pass`.
- Required: `opportunity_queue_eligible=true`.
- Current proven state: `data_origin=fixture`, gate blocked.
- Current allowed action: record blocked evidence only.
- Current forbidden action: selecting or writing title/meta/H1/FAQ/internal-link repairs from current GSC evidence.

## Boundary

This PR does not select candidate pages. It does not write CMS fields, Search Channel rows, sitemap, llms, title, meta, H1, FAQ, CTA, or fap-web code.

## Deferred Items

- No live GSC API call.
- No `seo_gsc_daily` import/backfill.
- No CTR candidate queue.
- No TDK dry-run queue.
- No CMS/Search/deploy action.
