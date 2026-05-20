# Search Channel Queue Runtime MVP

## Purpose

`SEARCH-CHANNEL-QUEUE-01` adds a controlled Search Channel Queue runtime MVP for planning URL distribution readiness from verified `seo_intel` URL Truth. It does not submit URLs, request indexing, call Google Search Console, call Baidu Resource Platform, call IndexNow, call domestic search engines, enable scheduler jobs, read production crawler logs, run production collector writes, deploy services, edit environment files, or change sitemap/llms behavior.

The runtime exists to make future live canary approval auditable and bounded. It creates a dry-run-first queue model with explicit eligibility, approval, batch, retry, and audit state while keeping live submission impossible in this PR.

## Runtime Queue Model

Target connection: `seo_intel`.

Target runtime tables:

- `seo_search_channel_queue_items`
- `seo_search_channel_queue_batches`
- `seo_search_channel_queue_events`

Protected legacy/foundation tables that this command must not write:

- `seo_baidu_push_logs`
- `seo_indexnow_submissions`
- `seo_domestic_submission_logs`

Queue items hold canonical URL, locale, page/entity identity, source authority, source table, channel, eligibility state, approval state, execution state, indexability state, claim boundary state, private-flow flag, reason codes, lastmod, optional content hash, URL hash, and idempotency key.

Batches group planned queue items by channel. The MVP writes `dry_run` batches only when the explicit write gate is enabled.

Events record batch and queue-item planning audit events only when queue writes are enabled.

## Channels

Supported channels:

- `google_sitemap`
- `gsc_readiness`
- `baidu_push`
- `indexnow`
- `so360_submit`
- `sogou_submit`
- `shenma_submit`
- `llms_queue`

Channel semantics:

- `google_sitemap`, `gsc_readiness`, and `llms_queue` are readiness/discoverability channels only.
- `baidu_push`, `indexnow`, `so360_submit`, `sogou_submit`, and `shenma_submit` are future submission channels, but this runtime does not implement live submission.
- No channel may bypass CMS/backend URL Truth or Search Channel Queue approval.

## Eligibility Rules

Allowed page entity types:

- `research_report`
- `home`
- `test_hub`
- `test_detail`

Approved source authorities:

- `backend_cms`
- `backend_public_surface`
- `scale_catalog`

Hard excluded page/entity types:

- `take`
- `result`
- `order`
- `checkout`
- `pay`
- `share`
- `report_private`
- `private_report`
- `user_account`
- `email_lookup`
- `admin`
- `ops`

A URL may enter the planned queue only when it exists in `seo_urls`, has an approved backend/CMS source authority, is public, is indexable, has a valid canonical URL, uses a supported page entity type, is not private flow, is claim-safe, is not draft, is not noindex, is not stale slug, and is not sourced from frontend fallback, static sitemap fallback, static `llms.txt` fallback, Node2 local DB, crawler logs, or external search responses.

## Command Behavior

Command:

```bash
php artisan seo-intel:search-channel-queue
```

Required safe modes:

```bash
php artisan seo-intel:search-channel-queue --dry-run --no-write --json --limit=20
php artisan seo-intel:search-channel-queue --dry-run --no-write --json --channel=indexnow --limit=20
php artisan seo-intel:search-channel-queue --dry-run --no-write --json --page-type=research_report --limit=20
```

Dry-run/no-write mode plans candidates and reports safety state without writing rows.

Enqueue mode is blocked unless the command-session write gate is explicitly enabled:

```bash
SEO_INTEL_SEARCH_CHANNEL_QUEUE_WRITE_ENABLED=true php artisan seo-intel:search-channel-queue --json --limit=20
```

Persistent environment configuration must not enable queue writes by default.

The command output includes:

- `dry_run`
- `writes_attempted`
- `writes_committed`
- `external_calls_attempted=false`
- `search_submission_attempted=false`
- `crawler_log_read_attempted=false`
- `candidate_count`
- `eligible_count`
- `blocked_count`
- `planned_queue_count`
- `channel_breakdown`
- `page_type_breakdown`
- `reason_code_breakdown`
- `target_tables`
- `safety_flags`

## Safety Gates

Live submission is not implemented.

There is no `--submit` option.

The command does not make HTTP requests, does not store external API credentials, does not read crawler logs, does not write collector output, does not alter sitemap/llms behavior, and does not activate scheduler jobs.

Any future live submitter must be a separate human-approved task after production migration preflight, queue write approval, safe credential readiness, and canary scope approval.

## Next Task

Next task: `SEARCH-CHANNEL-QUEUE-01-PROD-MIGRATION-PREFLIGHT`.
