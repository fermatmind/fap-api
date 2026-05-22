# Observation Queue Schema Contract

## Purpose

SEO-OBS-GOV-02 defines the future `seo_observation_queue` table contract.
This is schema-readiness only. It does not add a real migration, does not run a
migration, does not write `seo_intel`, and does not create a runtime writer.
It does not run a migration in any environment.

The table is intended to track verification work created by CMS/backend changes,
runtime checks, Search Channel Queue lifecycle states, crawler aggregate
observations, claim-boundary checks, Issue Queue lifecycle events, and manual
Digital PR observations.

## Required Table

Future table name: `seo_observation_queue`

## Proposed Fields

- id
- event_uid
- event_type
- event_state
- source_system
- source_event_id nullable
- canonical_url_hash nullable
- canonical_url nullable
- locale nullable
- page_entity_type nullable
- entity_id_or_slug nullable
- entity_key nullable
- entity_source nullable
- observation_target
- runtime_check_state nullable
- search_observation_state nullable
- crawler_observation_state nullable
- claim_boundary_state nullable
- dedupe_key nullable
- priority nullable
- scheduled_for nullable
- observed_at nullable
- closed_at nullable
- safe_context_hash nullable
- created_at
- updated_at

## Idempotency Strategy

`event_uid` must be globally deterministic for a single observation event. It
should be derived from safe values only:

- source system
- event type
- source event id when available
- canonical URL hash when available
- locale
- page entity type
- entity id or slug
- entity key
- observation target
- safe context hash

The future implementation should enforce a unique index on `event_uid`.

## Dedupe Key Strategy

`dedupe_key` groups repeated observations that represent the same operator work.
It should be deterministic and derived from safe values only:

- event type
- observation target
- canonical URL hash or entity key
- locale
- page entity type
- source system

`dedupe_key` must never include raw URLs with query strings, raw request data,
raw crawler-log lines, email, token, cookie, order/payment/attempt identifiers,
or raw payloads.

The schema contract requires no raw payload fields, no raw JSON blobs, no raw crawler-log fields, no search submission behavior, and no CMS mutation behavior.

## Source Authority Boundary

Observation Queue may reference URL Truth and CMS/backend authority by safe
identifiers. It must not create URL Truth, update URL Truth, approve Search
Channel Queue rows, submit URLs, mutate CMS content, or infer truth from crawler
logs or search engine responses.

Allowed source systems:

- cms_backend
- runtime_verifier
- url_truth
- search_channel_queue
- crawler_aggregate_observation
- issue_queue
- claim_boundary_checker
- digital_pr_manual_observation
- ops_seo_read_model

Forbidden authority sources:

- frontend_fallback
- static_sitemap_fallback
- static_llms_fallback
- crawler_log_as_url_truth
- search_engine_response_as_url_truth
- local_copy
- node2_local_db
- tencent_rds_business_table

## Forbidden Proposed Fields

- raw_payload
- raw_log_line
- raw_request_uri
- raw_user_agent
- ip_address
- email
- token
- cookie
- authorization
- order_id
- payment_id
- attempt_id
- provider_payload
- event_payload
- metadata_json containing raw data

## Explicit Non-behavior

The future `seo_observation_queue` table must not:

- submit URLs
- create or mutate Search Channel Queue rows
- write CMS records
- publish or unpublish content
- read raw crawler logs
- store raw JSON blobs
- store raw crawler-log fields
- store raw request payloads
- auto-fix Issue Queue rows
- act as URL Truth
- treat crawler hits as URL Truth
- treat search engine responses as URL Truth

## Final Decision

`observation_queue_schema_contract_ready_without_migration`

Next task: `SEO-OBS-GOV-03`
