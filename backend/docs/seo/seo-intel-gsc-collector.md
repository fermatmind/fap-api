# SEO Intelligence GSC Collector Foundation

## Purpose

This document records SEO-DASH-04A, a disabled Google Search Console collector foundation for FermatMind Search Intelligence.

It does not connect live GSC, add credentials, run a production backfill, enable a scheduler, deploy Metabase, or change runtime behavior.

## Source Boundary

GSC is search feedback only. It is not business truth, purchase truth, or a revenue attribution authority.

Purchase truth remains backend orders, payments, and benefit grants. GA4, Baidu Tongji, and GSC telemetry must not directly attribute purchases.

The SEO Collector must not read Node2 local Laravel, Node2 local DB, Node2 local queue, or any quarantined local runtime as authority.

## Collector

Collector name: `gsc_foundation`

Default state:

- `enabled=false`
- `collectors_enabled=false`
- `write_enabled=false`
- `dry_run_default=true`
- `gsc_enabled=false`
- `gsc_live_api_enabled=false`
- `allow_external_api_calls=false`

The dry-run collector uses fixture rows only. It requires no credentials, performs no live API call, attempts no DB writes, and returns safe aggregate metadata.

## T-3 Lag Model

The foundation records a T-3 backfill lag model:

- `gsc_backfill_lag_days=3`
- `gsc_default_window_days=28`

This reflects the expected delay before GSC rows are treated as final enough for search feedback reporting. SEO-DASH-04A does not execute a production backfill.

## Query Handling

Queries are normalized into:

- `query_hash`
- `query_display_masked`
- `query_type`
- `is_brand_query`

Brand terms currently include:

- `fermat`
- `fermatmind`
- `费马`
- `费马测试`
- `fap`

Query text must not directly attribute purchase. Keyword-to-purchase attribution is explicitly forbidden.

## Schema

SEO-DASH-04A adds the planned `seo_gsc_daily` migration only. It stores daily search feedback aggregates for `source_engine=google`.

Forbidden detail fields remain excluded:

- email
- order numbers
- attempt ids
- payment ids
- provider event ids
- cookies
- raw payloads
- raw payment payloads
- raw IPs

## Deferred Items

The following remain deferred:

- live GSC API credential setup
- production GSC backfill
- scheduler activation
- queue worker activation
- Baidu collector
- IndexNow collector
- Metabase deployment
- purchase attribution from GSC queries

## Next Task

Next task: SEO-DASH-04B.
