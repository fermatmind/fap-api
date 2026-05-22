# CRAWLER-LOG-06 Aggregate Observation Design

## Executive Summary

CRAWLER-LOG-06 defines the aggregate observation model that follows the first production crawler log canary.

This design keeps crawler logs as privacy-safe SEO observability. It does not expand production log reads, does not run another canary, does not add scheduler behavior, does not write aggregate rows, does not mutate URL Truth, does not write issue queue rows, does not enqueue Search Channel Queue items, and does not submit URLs to search engines.

The production canary sample recorded in CRAWLER-LOG-05 remains a bounded single-source sample. Its high `blocked_private_path` count is a safety and routing signal only. It is not a URL eligibility signal and must not be interpreted as evidence for URL Truth, canonical state, indexability state, or search submission readiness.

## Design Purpose

The crawler log aggregate observation layer should answer only these questions:

- Which bot families or non-bot traffic groups reached approved surfaces?
- Which route families were observed at aggregate level?
- Which HTTP status buckets appeared by safe dimensions?
- Were private-flow, API, Ops, unknown path, or query-risk counters nonzero?
- Did future approved canaries remain raw-free, no-write, and bounded?
- Which aggregate counters are safe for `/ops/seo` after separate implementation approval?

It must not answer:

- whether a URL exists
- whether a URL is canonical
- whether a URL is indexable
- whether a URL should be submitted to a search engine
- whether a crawler visit should create or modify SEO issue rows
- whether an unknown path should become URL Truth

## Observation Inputs

Allowed inputs for this design are already-sanitized aggregate outputs from approved crawler log dry-runs or canaries.

Allowed future storage source:

- `seo_crawler_logs_daily`, but only after a separate storage approval PR defines and validates persistence.

Forbidden inputs:

- raw production access logs
- raw request rows
- raw IP addresses
- raw user agents
- raw request URIs
- raw query strings
- cookies
- headers
- tokens
- emails
- order IDs
- payment IDs
- attempt IDs
- provider payloads
- application debug logs
- business DB logs
- Node2 local DB or logs
- Tencent RDS business sources
- Metabase raw datasource mutation

CRAWLER-LOG-06 itself performs no production read and no database read/write.

## Aggregate Fact Model

The observation fact should be daily and aggregate-only.

Recommended grain:

- `log_date`
- `host`
- `surface_family`
- `bot_family`
- `bot_variant`
- `bot_verification_state`
- `route_family`
- `page_entity_type`
- `http_status`
- `method_bucket`
- `query_present`
- `query_risk_state`
- `private_path_blocked`
- `source_log_family`
- `privacy_transform_version`

Recommended measures:

- `hit_count`
- `first_seen_at`
- `last_seen_at`

Allowed optional safe path fields:

- `canonical_path`, only when mapped to a safe public URL Truth path or approved public-route allowlist path
- `path_hash`, only for unknown, private, blocked, or unsafe paths

Forbidden aggregate fact fields:

- `ip_address`
- `remote_addr`
- `raw_user_agent`
- `raw_request_uri`
- `raw_query_string`
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

## Safe Dashboard Metrics

The first safe `/ops/seo` crawler-log observation cards can be derived from aggregate counters only:

- total aggregate hits
- bot family breakdown
- bot verification state breakdown
- surface family breakdown
- route family breakdown
- HTTP status breakdown
- method bucket breakdown
- query risk state breakdown
- private path blocked count
- unknown public path count
- static asset hit count
- safe public canonical path count
- non-bot count
- unknown bot count
- latest approved canary timestamp

Safety counters that should render as warning when nonzero:

- private path blocked count
- query risk state `sensitive_key_present`
- unknown public path count
- API surface count
- Ops surface count
- raw persistence attempted count
- write attempted count
- search submission attempted count
- scheduler enabled count

## URL Truth Boundary

Crawler log aggregates may read URL Truth only for safe mapping in a future approved implementation.

Crawler log aggregates must not:

- create `seo_urls`
- update `seo_urls`
- decide canonical truth
- decide indexability truth
- override CMS/backend URL Truth
- create Search Channel Queue items
- approve Search Channel Queue items
- retry Search Channel Queue items
- submit URLs to search engines
- auto-create issue queue rows

Unknown public paths must remain aggregate counters plus hashes until a separate human-reviewed issue workflow evaluates them.

Private-flow and blocked-private paths must never be rendered as raw paths in reports or UI.

## Issue Queue Boundary

Crawler log observation may identify aggregate anomaly candidates such as high `404`, high `unknown_public_path`, high `blocked_private_path`, or unexpected bot families.

In this design, those candidates are not issue queue rows.

Any future issue queue integration must be a separate PR with:

- explicit aggregate-only source fields
- no raw payload
- no raw path for private or unknown paths
- no automatic remediation
- no Search Channel Queue enqueue
- no URL Truth mutation

## Storage Boundary

CRAWLER-LOG-06 does not add a migration and does not write `seo_crawler_logs_daily`.

Future aggregate storage must require:

- `seo_intel` connection only
- table name `seo_crawler_logs_daily` or an explicitly scoped `seo_crawler_*` aggregate table
- no raw persistence fields
- idempotency by date, host, bot family, route family, status, method, query risk, and privacy transform version
- dry-run/no-write command verification before any write mode
- write gate disabled by default
- no scheduler until a separate scheduler readiness task

## Reporting Boundary

Reports and UI may show:

- aggregate counts
- safe dimensions
- safe canonical paths only when mapped to approved public URL Truth
- timestamps for aggregate windows

Reports and UI must not show:

- raw IP
- raw user agent
- raw URI
- raw query string
- raw private path
- raw unknown path
- cookies
- headers
- tokens
- emails
- order IDs
- payment IDs
- attempt IDs
- provider payloads
- event payload
- metadata JSON
- attributes JSON

## Follow-up PR Plan

Recommended next tasks:

1. `CRAWLER-LOG-07｜Aggregate storage contract`
2. `CRAWLER-LOG-08｜Aggregate storage migration and no-write writer gate`
3. `CRAWLER-LOG-09｜Ops SEO crawler observation read model`
4. `CRAWLER-LOG-10｜Ops SEO crawler observation UI`
5. `CRAWLER-LOG-11｜Scheduler readiness scan`

`CRAWLER-LOG-07` should stay docs/generated/test only unless explicitly expanded.

## No-go Conditions

Stop immediately if a proposed crawler log observation implementation requires:

- raw production log reads without explicit source approval
- more than one production source in the same canary
- `max_lines` above the approved limit
- raw persistence
- scheduler activation
- issue queue auto-write
- URL Truth mutation
- Search Channel Queue enqueue
- external search API calls
- URL submission
- Metabase exposure
- business DB access
- Tencent RDS business access
- Node2 local DB or log access

## Final Decision

`crawler_log_aggregate_observation_design_ready_for_storage_contract`

## Next Task

`CRAWLER-LOG-07｜Aggregate storage contract`
