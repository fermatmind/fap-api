# CTR-TDK-REPAIR-DRYRUN-QUEUE-01

Date: 2026-07-06
Repository: fermatmind/fap-api
Scope: generated docs only

## Decision

Final verdict: BLOCKED_BY_GSC_DATA_QUALITY

No CTR/TDK dry-run queue can be produced because CTR eligibility is blocked by missing passable live GSC read-model data.

## Queue Summary

- Candidate source required: gated `seo_intel` GSC read-model rows.
- Current candidate source status: unavailable.
- Queue size: 0.
- TDK changes proposed: 0.
- CMS writes: 0.
- Search submissions: 0.

## Boundary

This PR intentionally does not invent candidate pages from screenshots, fixture data, static docs, GSC UI screenshots, GA summaries, or operator intuition.

## Deferred Items

- No TDK dry-run candidate selection.
- No title/meta/H1/FAQ/body/CTA rewrite.
- No CMS write/import/publish.
- No Search Channel, sitemap, URL inspection, fap-web, deploy, or production action.
