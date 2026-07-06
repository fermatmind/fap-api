# GSC-READMODEL-REPAIR-DESIGN-01

Date: 2026-07-06
Repository: fermatmind/fap-api
Scope: design only / generated docs only

## Decision

Final verdict: READMODEL_REPAIR_DESIGN_READY

The GSC read-model repair path should not start with CTR/TDK edits. It should first prove sanitized live GSC evidence, then dry-run import/readback, then controlled canary import, and only later enable opportunity queue selection.

## Design Summary

Required repair sequence:

1. Sanitized live read-only evidence capture.
2. Dry-run read-model import/readback against the artifact.
3. Controlled canary import to `seo_gsc_daily` only after exact operator approval.
4. Quality gate readback proving `data_origin=live_gsc_api` and `opportunity_queue_eligible=true`.
5. Separate CTR/TDK queue selection PR.

## Boundary

This PR does not execute any of the sequence. It only records the repair design needed after `BLOCKED_BY_GSC_DATA_QUALITY`.

## Deferred Items

- No live GSC API call.
- No credentials read, printed, validated, or stored.
- No `seo_gsc_daily` import/backfill.
- No scheduler, queue, CMS, Search Channel, sitemap, URL inspection, fap-web, deploy, or production action.
