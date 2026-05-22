# CRAWLER-LOG-04-CANARY Human-approved Production Log Canary Runtime

## Purpose

CRAWLER-LOG-04-CANARY adds a single-source read runtime for a human-approved crawler log canary.

This runtime is limited to:

- single-source read
- `max_lines <= 1000`
- no raw persistence
- dry-run / no-write summary
- no scheduler
- no issue queue write
- no URL Truth write
- no search submission

Crawler log canary output remains observability-only. It is not URL Truth, not Search Channel Queue authority, and not CMS authority.

## Command

```bash
cd backend
php artisan seo-intel:crawler-log-observe \
  --source=/var/log/nginx/access.log \
  --approval-phrase="I explicitly approve CRAWLER-LOG-04 production canary for source /var/log/nginx/access.log with max_lines=1000 and no raw persistence." \
  --dry-run \
  --no-write \
  --json \
  --limit=1000
```

## Required gates

The source runtime is fail-closed unless all are true:

- `--source` is provided
- source path is absolute
- source path exists and is readable
- exact approval phrase matches the source path
- `--dry-run` is present
- `--no-write` is present
- `--limit` is bounded to 1000 or less

`--fixture` and `--source` are mutually exclusive.

## Output boundary

The canary may emit only safe summary output:

- aggregate counters
- sanitized breakdowns
- sanitized aggregate rows
- `source_descriptor.basename`
- `source_descriptor.path_hash`

The runtime must not emit:

- raw log lines
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

The absolute source path is used transiently for approval verification and file access. It is not emitted in the JSON summary.

## What this runtime still does not do

- no scheduler
- no DB writes
- no issue queue write
- no URL Truth write
- no Search Channel Queue write
- no external API call
- no search submission
- no deploy

## Approved production execution surface

Current approved production execution context observed during preflight:

- host: `139.224.130.204`
- release shell: `/var/www/fap-api/current/backend`
- source: `/var/log/nginx/access.log`

This document defines runtime only. It does not deploy the runtime and does not perform a production log read by itself.
