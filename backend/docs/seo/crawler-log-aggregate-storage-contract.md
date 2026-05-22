# CRAWLER-LOG-07 Aggregate Storage Contract

## Executive Summary

CRAWLER-LOG-07 defines the storage contract for crawler log aggregate observation.

This PR is docs/generated/test only. It does not add a migration, does not write aggregate rows, does not read production crawler logs, does not run another canary, does not enable a scheduler, does not create issue queue rows, does not mutate URL Truth, does not enqueue Search Channel Queue items, and does not submit URLs.

The existing `seo_crawler_logs_daily` table is a legacy aggregate table and is not approved as the CRAWLER-LOG V1 write target because it includes fields that the V1 privacy boundary should not carry forward, including `user_agent_hash`, `path_display_masked`, and `metadata_json`.

The approved CRAWLER-LOG V1 storage target should be a new scoped aggregate table:

`seo_crawler_log_daily_aggregates`

## Storage Goal

Store daily, privacy-safe crawler observation aggregates that can later power `/ops/seo` read-only cards and tables.

The table must store only aggregate dimensions and counts. It must never store raw log rows, raw identifiers, raw request fields, raw private paths, raw unknown paths, query string values, user agents, cookies, headers, tokens, or provider payloads.

Crawler log storage remains observation only. It must not become URL Truth, canonical truth, indexability truth, Search Channel Queue authority, issue auto-remediation authority, or search submission input.

## Target Table

Target table:

`seo_crawler_log_daily_aggregates`

Target connection:

`seo_intel`

Legacy table retained as read/compatibility only:

`seo_crawler_logs_daily`

The legacy table must not receive CRAWLER-LOG V1 writes unless a separate migration removes or permanently blocks forbidden raw-adjacent fields.

## Required Fields

Recommended V1 fields:

- `id`
- `log_date`
- `host`
- `surface_family`
- `bot_family`
- `bot_variant`
- `bot_verification_state`
- `route_family`
- `page_entity_type`
- `canonical_path` nullable
- `path_hash` nullable
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

`canonical_path` is allowed only for safe public paths mapped to CMS/backend URL Truth or an approved public route allowlist.

`path_hash` is required when the raw path is private, blocked, unknown, or unsafe. Raw private and unknown paths must never be stored.

## Required Indexes

Recommended indexes:

- unique `idempotency_key`
- `log_date`
- `host`
- `surface_family`
- `bot_family`
- `route_family`
- `http_status`
- `query_risk_state`
- `private_path_blocked`
- composite `log_date`, `host`, `bot_family`
- composite `log_date`, `surface_family`, `route_family`

## Idempotency Contract

`idempotency_key` must be derived from safe aggregate dimensions only:

- `log_date`
- `host`
- `surface_family`
- `bot_family`
- `bot_variant`
- `bot_verification_state`
- `route_family`
- `page_entity_type`
- `canonical_path` when allowed
- `path_hash` when required
- `http_status`
- `method_bucket`
- `query_present`
- `query_risk_state`
- `private_path_blocked`
- `source_log_family`
- `privacy_transform_version`

The key must not include raw IP, raw user agent, raw URI, raw query string, cookie, header, token, email, order ID, payment ID, attempt ID, provider payload, or raw log text.

## Write Gate

Future aggregate writes must be blocked by default.

Required write gate:

`SEO_INTEL_CRAWLER_LOG_AGGREGATE_WRITE_ENABLED=true`

Persistent environment configuration must not enable this gate until a separate human-approved production storage task.

Any write command must first support dry-run/no-write output and must report:

- `dry_run`
- `no_write`
- `writes_attempted`
- `writes_committed`
- `target_table`
- `target_table_write_attempted`
- `target_table_write_committed`
- `raw_persistence`
- `production_log_read_attempted`
- `external_calls_attempted`
- `search_submission_attempted`
- `scheduler_enabled`
- `collector_write_attempted`

## Forbidden Persistent Fields

These fields are forbidden in the V1 aggregate table:

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

## Read Boundary

Future read models may read from:

- `seo_crawler_log_daily_aggregates`

They may also read legacy `seo_crawler_logs_daily` only for migration/backfill planning, not for the V1 `/ops/seo` dashboard.

Future UI may display:

- aggregate counts
- safe dimensions
- safe canonical paths only when already mapped to public URL Truth
- timestamps

Future UI must not display:

- raw private paths
- raw unknown paths
- hashes as operator-facing route labels
- raw payloads
- raw JSON blobs
- raw identifiers

## No-go Conditions

Stop immediately if storage implementation requires:

- raw persistence
- default write enablement
- production log read without exact source approval
- scheduler activation
- issue queue auto-write
- URL Truth mutation
- Search Channel Queue enqueue
- external search API call
- URL submission
- Metabase exposure
- business DB access
- Tencent RDS business access
- Node2 local DB or log access

## Next Task

`CRAWLER-LOG-08｜Aggregate storage migration and no-write writer gate`
