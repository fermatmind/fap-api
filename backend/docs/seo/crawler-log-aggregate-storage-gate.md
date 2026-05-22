# CRAWLER-LOG-08 Aggregate Storage Migration and Writer Gate

## Executive Summary

CRAWLER-LOG-08 adds the CRAWLER-LOG V1 aggregate storage table and a narrowly scoped writer gate.

This PR does not run production migration, does not read production crawler logs, does not run another canary, does not enable a scheduler, does not write issue queue rows, does not mutate URL Truth, does not enqueue Search Channel Queue rows, does not call external search APIs, and does not submit URLs.

The writer is blocked by default and writes only when explicitly enabled in the command/session environment:

`SEO_INTEL_CRAWLER_LOG_AGGREGATE_WRITE_ENABLED=true`

Persistent environments must not enable this gate until a separate production storage approval task.

## Migration Result

Added `seo_intel` migration:

`database/migrations/seo_intel/2026_05_22_111800_create_seo_crawler_log_daily_aggregates_table.php`

Target table:

`seo_crawler_log_daily_aggregates`

The migration contains:

`protected $connection = 'seo_intel';`

The table contains only aggregate-safe dimensions, counters, timestamps, and an idempotency key. It does not include raw log fields, raw identifiers, raw payloads, raw JSON blobs, or raw private paths.

## Writer Gate

Added writer service:

`App\Services\SeoIntel\CrawlerLog\CrawlerLogAggregateStorageWriter`

Writer behavior:

- dry-run/no-write returns a safe plan and commits nothing
- write attempts are blocked unless the config gate is explicitly enabled
- enabled writes target only `seo_crawler_log_daily_aggregates`
- rows are idempotent by safe aggregate dimensions
- no external calls are made
- no search submission is attempted
- no production log read is attempted
- no scheduler is enabled
- no raw persistence is allowed

## Target Table

`seo_crawler_log_daily_aggregates`

Allowed columns:

- `id`
- `log_date`
- `host`
- `surface_family`
- `bot_family`
- `bot_variant`
- `bot_verification_state`
- `route_family`
- `page_entity_type`
- `canonical_path`
- `path_hash`
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
- `idempotency_key`
- `created_at`
- `updated_at`

Forbidden columns remain absent:

- `ip_address`
- `remote_addr`
- `raw_user_agent`
- `user_agent`
- `user_agent_hash`
- `raw_request_uri`
- `request_uri`
- `raw_query_string`
- `query_string`
- `path_display_masked`
- `cookie`
- `headers`
- `authorization`
- `session_id`
- `token`
- `api_key`
- `email`
- `order_no`
- `attempt_id`
- `payment_id`
- `provider_event_id`
- `raw_payload`
- `raw_log_line`
- `event_payload`
- `metadata_json`
- `attributes_json`

## Safety Boundary

This PR provides the storage primitive only.

It does not approve:

- production migration execution
- production aggregate writes
- scheduler activation
- production source expansion
- issue queue integration
- URL Truth mutation
- Search Channel Queue integration
- search submission
- `/ops/seo` UI display

## Validation

Required local validation includes:

- focused CRAWLER-LOG-08 test
- full `SeoIntel` test filter
- route list
- Pint
- generated JSON validation
- PR train YAML/state JSON validation
- diff checks
- local/testing `migrate --pretend` only for `seo_intel`

## Next Task

`CRAWLER-LOG-09｜Ops SEO crawler observation read model`
