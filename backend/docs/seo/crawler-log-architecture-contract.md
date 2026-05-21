# CRAWLER-LOG-00 Crawler Log Architecture Contract

## Purpose

Crawler Log is aggregate search bot observability. It answers which bot families accessed which safe paths, when they were seen, status distribution, whether visits concentrate on indexable URL Truth rows, and whether unknown bots, 404s, private-flow probes, or unexpected hosts appear.

Crawler Log is not URL Truth, not Search Channel Queue, not CMS authority, and not an issue auto-fix system. It must not create URL Truth, decide canonical or indexability state, enqueue search submission, submit URLs, or use frontend fallback, static sitemap, static `llms.txt`, local copies, Node2 local DB, crawler logs, or external search surfaces as authority.

This PR is architecture contract only. It does not read production crawler logs, add a runtime parser, add a scheduler, run collector writes, run migrations, deploy, edit env, expose Metabase, call search APIs, or submit URLs.

## Source Boundary

Allowed future source families after explicit approval:

- nginx / OpenResty access log
- CDN edge access log
- ALB / SLB access log

Any production source must document:

- log path
- log format
- owner
- retention
- whether cookies, headers, or queries are present
- whether private routes are present
- maximum read lines
- read time window

Forbidden sources:

- Node2 local Laravel log
- Node2 local DB
- business DB log
- payment log
- provider webhook log
- application debug log
- raw request payload log
- unapproved production raw access log

## Aggregate Schema

Prefer the existing `seo_crawler_logs_daily` table. If a future PR proves the table is insufficient, any migration must use the `seo_intel` connection and must remain aggregate-only.

Required V1 fields:

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

Allowed hosts are `fermatmind.com`, `www.fermatmind.com`, `api.fermatmind.com`, `ops.fermatmind.com`, and `unknown_host`.

`public_web` is the SEO observation surface. `api` and `ops` are not search assets and must not become URL Truth or search candidates.

## Forbidden Persistent Fields

These fields must not be persisted to `seo_intel` in V1:

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

Wide JSON fields are blocked in V1 because they are too easy to misuse as raw payload storage.

## Bot Normalization

V1 uses user-agent claim classification only:

`bot_verification_state = ua_claim_only`

No DNS reverse verification is performed in V1.

Bot family outputs:

- `googlebot`
- `bingbot`
- `baiduspider`
- `so360`
- `sogou`
- `shenma`
- `yandex`
- `duckduckbot`
- `applebot`
- `bytespider`
- `petalbot`
- `facebook_external_hit`
- `twitterbot`
- `linkedinbot`
- `unknown_bot`
- `non_bot`
- `unknown_user_agent`

Known Google variants map to `web`, `image`, `ads`, `media`, `mobile`, or `unknown`. Other crawlers use `web` or `unknown` unless a safe normalized variant is defined later.

## Path Privacy Transform

Every raw log line must be handled in this order:

1. Parse ephemeral fields in memory.
2. Classify bot family from user-agent in memory.
3. Normalize method, status, and host.
4. Strip query string completely.
5. Classify route family.
6. Detect private route or token risk.
7. Map to URL Truth only when the path is safe.
8. Drop raw IP, raw user-agent, raw request URI, cookie, header, query, and raw log line.
9. Aggregate counters.
10. Persist aggregate-only rows.

Query handling stores only `query_present` and `query_risk_state`.

Allowed query risk states:

- `none`
- `tracking_only`
- `sensitive_key_present`
- `unknown_query_present`

Private paths and unknown paths must not store raw path text. They use `path_hash` and a blocked or unknown route family.

## Private Path Denylist

Any path or host matching these route prefixes must not be stored as raw `canonical_path`:

- `/take`
- `/result`
- `/results`
- `/order`
- `/orders`
- `/checkout`
- `/pay`
- `/payment`
- `/share`
- `/report-private`
- `/report_private`
- `/me`
- `/account`
- `/admin`
- `/ops`
- `/api`

`api.fermatmind.com` and `ops.fermatmind.com` are classified as `api` and `ops` surfaces. They must not enter SEO candidates or URL Truth.

## URL Truth Boundary

Crawler Log may read URL Truth for safe mapping:

`crawler path -> seo_urls canonical_path`

Crawler Log must not:

- create `seo_urls`
- infer canonical truth
- infer indexability truth
- create Search Channel Queue rows
- submit URLs
- auto-write `seo_issue_queue`

Unknown public paths may become future issue candidates only after a separate approval and sanitization step. CRAWLER-LOG-00 does not implement that step.

## Fixture Plan

Future parser work must use synthetic fixtures first. No production logs are allowed in CRAWLER-LOG-00.

The later fixture should cover:

- Googlebot visiting a Research URL with HTTP 200
- Baiduspider visiting a test detail URL with HTTP 200
- Bingbot visiting an unknown public path with HTTP 404
- ordinary browser visiting home with HTTP 200
- Googlebot visiting `/result/xxx`
- Sogou visiting a URL with a token query
- spoofed bot user-agent
- static `.js` / `.css` asset
- API host request
- Ops host request

## Command Contract

A future command may be introduced as:

`php artisan seo-intel:crawler-log-observe`

V1 allowed modes are limited to fixture dry-run/no-write flags such as `--fixture`, `--dry-run`, `--no-write`, `--json`, and `--limit`.

Forbidden flags in V1:

- `--production`
- `--tail`
- `--schedule`
- `--write`

Required dry-run output fields:

- `dry_run`
- `writes_attempted=false`
- `writes_committed=false`
- `production_log_read_attempted=false`
- `external_calls_attempted=false`
- `search_submission_attempted=false`
- `raw_persistence=false`
- `parsed_line_count`
- `aggregate_row_count`
- `blocked_private_path_count`
- `unknown_bot_count`
- `bot_family_breakdown`
- `status_code_breakdown`
- `route_family_breakdown`
- `privacy_transform_version`

## Production Canary Boundary

Production canary is not part of CRAWLER-LOG-00, CRAWLER-LOG-01, CRAWLER-LOG-02, or CRAWLER-LOG-03.

Exact approval phrase required:

`I explicitly approve CRAWLER-LOG-04 production canary for source <log_path> with max_lines=1000 and no raw persistence.`

Production canary limits:

- `max_lines <= 1000`
- single approved log source
- single short time window
- no raw persistence
- no scheduler
- no issue queue write
- no search submission
- no Metabase mutation

## PR Split

- `CRAWLER-LOG-01` readiness scan
- `CRAWLER-LOG-02` fixture parser MVP
- `CRAWLER-LOG-03` aggregate dry-run/no-write
- `CRAWLER-LOG-04` production canary preflight
- `CRAWLER-LOG-04-CANARY` human-approved production log canary

Next task: `CRAWLER-LOG-01`.
