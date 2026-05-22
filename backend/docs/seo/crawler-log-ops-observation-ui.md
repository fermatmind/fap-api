# CRAWLER-LOG-10 Ops SEO Crawler Observation UI

## Purpose

CRAWLER-LOG-10 connects the crawler aggregate observation read model to the native `/ops/seo` Filament page.

The UI is read-only. It is an internal Ops observation panel, not a crawler log reader, not URL Truth, not Search Channel Queue approval, and not a search submission console.

There are no action buttons.

There is no raw log read and no raw persistence.

There is no search submission.

## UI Sections

The `/ops/seo` page now includes:

- crawler observation safety counters
- aggregate cards by `bot_family`
- aggregate cards by `surface_family`
- aggregate cards by `route_family`
- aggregate cards by `http_status`
- aggregate cards by `query_risk_state`
- aggregate cards by `private_path_blocked`
- recent safe aggregate rows

## Safe Display Fields

Recent rows may display only:

- `log_date`
- `host`
- `surface_family`
- `bot_family`
- `bot_variant`
- `route_family`
- `page_entity_type`
- `canonical_path`
- `http_status`
- `method_bucket`
- `query_present`
- `query_risk_state`
- `private_path_blocked`
- `hit_count`
- `last_seen_at`

## Forbidden Display

The UI must not display:

- `path_hash`
- `idempotency_key`
- raw IP
- raw user-agent
- raw request URI
- raw query string
- cookies
- headers
- tokens
- emails
- order IDs
- payment IDs
- attempt IDs
- provider payloads
- raw log lines
- raw JSON
- `metadata_json`
- `attributes_json`
- `event_payload`

## Forbidden Actions

The UI must not add:

- approve buttons
- retry buttons
- submit buttons
- crawler collector controls
- scheduler controls
- issue queue write controls
- URL Truth write controls
- Search Channel Queue write controls
- search submission controls
- Metabase iframe, proxy, or public link

## Boundary

CRAWLER-LOG-10 does not:

- read production crawler logs
- persist raw crawler data
- write aggregate rows
- run migrations
- deploy
- edit env
- enable scheduler
- expose Metabase
- submit URLs
- call GSC, Baidu, IndexNow, Bing, 360, Sogou, or Shenma APIs

## Next Task

Next task: `CRAWLER-LOG-11`
