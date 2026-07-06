# GSC-SEO-INTEL-LIVE-READMODEL-QUALITY-PROOF-READONLY-01

Date: 2026-07-06
Repository: fermatmind/fap-api
Scope: generated docs only

## Decision

Final verdict: BLOCKED_BY_GSC_DATA_QUALITY

Current repository evidence does not prove passable `seo_intel` read-model rows with `data_origin=live_gsc_api`. The train must keep GSC-derived CTR/TDK repair decisions blocked.

## Evidence Summary

- `gsc-data-quality-gate.v1.json` records current foundation status as `data_origin=fixture`.
- `opportunity_queue_eligible=false`.
- The quality gate requires `source_engine=google`, `data_origin=live_gsc_api`, required metric fields, freshness, and finalization lag.
- The live read-model consumption contract forbids direct sidecar artifact consumption by opportunity queues.
- This PR did not call Google Search Console, read credentials, import rows, backfill tables, enqueue Search Channel, or write CMS data.

## Boundary

This is a proof attempt using existing generated/read-model evidence only. It is intentionally blocked because the required live read-model proof is absent.

## Deferred Items

- No live GSC API call.
- No credentials read, printed, validated, or stored.
- No `seo_gsc_daily` import/backfill.
- No GSC-derived CTR/TDK repair queue.
- No CMS, Search Channel, sitemap, URL inspection, fap-web, deploy, or production action.
