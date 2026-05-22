# CRAWLER-LOG-04 Production Canary Preflight

## Purpose

CRAWLER-LOG-04 defines the production canary preflight for crawler log observability. This is not a production log read, not a canary execution, not a scheduler activation, not a collector write, and not a database migration.

Crawler logs remain aggregate observability only. They are not URL Truth, not Search Channel Queue, not CMS authority, not canonical truth, not indexability truth, and not an issue auto-fix system.

## Current Runtime State

The only available crawler-log observe command remains fixture-only:

```bash
php artisan seo-intel:crawler-log-observe --fixture --dry-run --no-write --json --limit=20
```

Current command posture:

- synthetic fixture only
- dry-run required
- no-write required
- no production mode
- no tail mode
- no schedule mode
- no write mode
- no submit mode

CRAWLER-LOG-04 does not add production source access or a production log reader.

## Canary Source Approval Requirements

Before any future human-approved canary, the operator must approve exactly one production source with these fields recorded outside raw logs:

- source log family
- log path
- log format
- owning system / owner
- retention policy
- approved execution environment
- host / surface family
- whether query strings are present
- whether cookies or headers are present
- whether private routes can appear
- maximum line count
- time window
- privacy classification
- rollback / abort owner

Allowed future source families:

- nginx / OpenResty access log
- CDN edge access log
- ALB / SLB access log

Forbidden sources:

- Node2 local Laravel log
- Node2 local DB
- business DB log
- payment log
- provider webhook log
- application debug log
- raw request payload log
- unapproved production raw access log

## Canary Limits

Any future production canary must be separate from this PR and must satisfy all limits:

- exact human approval phrase is required
- single source only
- short time window only
- `max_lines <= 1000`
- no raw persistence
- no scheduler
- no issue queue write
- no Search Channel Queue write
- no URL Truth write
- no search submission
- no external search API call
- no Metabase mutation
- no business DB / Tencent RDS / Node2 access

Required approval phrase:

```text
I explicitly approve CRAWLER-LOG-04 production canary for source <log_path> with max_lines=1000 and no raw persistence.
```

## Privacy Boundary

The future canary may parse raw production lines in memory only after exact approval. It must not persist or output:

- raw IP / remote address
- raw user-agent
- raw request URI
- raw query string
- cookie
- headers
- authorization
- session IDs
- tokens / API keys
- emails
- order IDs
- attempt IDs
- payment IDs
- provider event IDs
- raw payloads
- raw log lines
- `event_payload`
- `metadata_json`
- `attributes_json`

Unknown, private, API, Ops, and static paths must remain hash-only. Known public paths may map to safe canonical paths only for observability, and that mapping must not create URL Truth.

## URL Truth Boundary

Crawler logs may read existing URL Truth only to classify safe public paths in memory. They must not:

- create `seo_urls`
- infer canonical truth
- infer indexability truth
- create Search Channel Queue rows
- submit URLs
- auto-write `seo_issue_queue`
- use frontend fallback, static sitemap, static `llms.txt`, local copies, Node2 local DB, crawler logs, or external search surfaces as authority

## No-go Conditions

Stop before canary if any condition is true:

- exact approval phrase is missing
- source is not approved
- source owner is unknown
- log format is unknown
- max line count is greater than 1000
- requested source includes cookies, headers, raw payloads, or private route detail without a privacy transform plan
- requested output includes raw fields
- write mode is requested
- scheduler is requested
- issue queue write is requested
- URL Truth creation is requested
- Search Channel Queue creation is requested
- search submission is requested
- Metabase mutation is requested
- business DB / Tencent RDS / Node2 access is requested

## Final Decision

`crawler_log_production_canary_preflight_ready_for_exact_human_approval`

## Next Task

`CRAWLER-LOG-04-CANARY｜Human-approved production log canary`
