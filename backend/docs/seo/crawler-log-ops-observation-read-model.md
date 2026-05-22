# CRAWLER-LOG-09 Ops SEO Crawler Observation Read Model

## Purpose

CRAWLER-LOG-09 adds the backend read model that lets `/ops/seo` observe crawler-log aggregate rows without reading raw crawler logs.

The model is read-only.

There is no raw log read.

There is no raw persistence.

There is no issue queue write, no URL Truth write, and no search submission.

This is an Ops SEO observation surface only. It is not URL Truth, not Search Channel Queue approval, not a crawler-log reader, and not an issue auto-fix path.

## Read Boundary

The read model may read only:

- `seo_crawler_log_daily_aggregates`

It must not read:

- raw production access logs
- `seo_crawler_logs_daily`
- business DB tables
- order, payment, user, report, email, or attempt tables
- Metabase datasource internals
- Search Channel submission logs
- crawler raw payloads

## Service

Service:

- `App\Services\SeoIntel\OpsDashboard\SeoCrawlerLogObservationReadService`

The service returns small DTO-style arrays:

- `total_count`
- `total_hits`
- aggregate counts by `bot_family`, `surface_family`, `route_family`, `http_status`, `query_risk_state`, and `private_path_blocked`
- safety counts for blocked private paths, sensitive query keys, API/Ops surfaces, and unknown bots
- recent safe aggregate rows

## Safe Fields

Recent rows may include only aggregate-safe fields:

- `log_date`
- `host`
- `surface_family`
- `bot_family`
- `bot_variant`
- `bot_verification_state`
- `route_family`
- `page_entity_type`
- `canonical_path`
- `http_status`
- `method_bucket`
- `query_present`
- `query_risk_state`
- `private_path_blocked`
- `hit_count`
- `first_seen_at`
- `last_seen_at`
- `source_log_family`
- `privacy_transform_version`
- `updated_at`

## Forbidden Fields

The read model must not expose:

- `path_hash`
- `idempotency_key`
- raw IP
- raw user-agent
- raw request URI
- raw query string
- cookies
- headers
- authorization
- tokens
- emails
- order IDs
- payment IDs
- attempt IDs
- provider payloads
- raw log lines
- `event_payload`
- `metadata_json`
- `attributes_json`

## Safety Boundary

CRAWLER-LOG-09 does not:

- read production crawler logs
- persist raw crawler data
- write aggregate rows
- write issue queue rows
- create or mutate URL Truth
- enqueue Search Channel Queue rows
- submit URLs to any search engine
- call GSC, Baidu, IndexNow, Bing, 360, Sogou, or Shenma APIs
- enable scheduler
- deploy
- edit env
- expose Metabase

## Next Task

Next task: `CRAWLER-LOG-10`
