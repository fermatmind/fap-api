# GSC Data Quality Gate

## Purpose

`SEO-GSC-DATA-QUALITY-01` adds a backend-only quality gate for Google Search Console rows before any future opportunity queue may use them.

The gate is read-only. It does not call Google Search Console, does not request indexing, does not enqueue Search Channel items, does not write CMS drafts, does not run migrations, and does not enable scheduler or queue workers.

## Gate Contract

GSC rows are eligible for future opportunity scoring only when all of these are true:

- source engine is `google`
- data origin is `live_gsc_api`
- row source is not `fixture`, `mock`, `static_artifact`, or `unknown`
- report date satisfies the configured GSC finalization lag
- report date is not older than the configured freshness window
- required metric fields are present: canonical URL hash, query hash, clicks, and impressions

Current `gsc_foundation` dry-run data remains fixture-only, so its gate status is intentionally `blocked` and `opportunity_queue_eligible=false`.

## Current Defaults

- `gsc_backfill_lag_days=3`
- `gsc_data_quality.max_report_age_days=10`
- `gsc_data_quality.allowed_data_origins=["live_gsc_api"]`
- `gsc_data_quality.forbidden_data_origins=["fixture","mock","static_artifact","unknown"]`

## Boundary

This gate is a prerequisite for future opportunity queue work. It is not the opportunity queue, does not score opportunities, and does not authorize CMS/search execution.

Deferred:

- live GSC connector activation
- GSC credential validation
- production GSC backfill
- opportunity queue implementation
- CMS draft/package generation
- Search Channel enqueue/approval/submission
