# SEO Intel Drift Collector and Crawler Log Foundation

## Purpose

SEO-DASH-02B adds disabled-by-default foundation code for metadata drift checks and crawler log parsing inside the `seo_intel` boundary. It is a dry-run collector foundation only. It does not deploy, schedule, crawl production, read production logs, mutate CMS content, or change sitemap / llms behavior.

## Scope

This PR introduces:

- `drift_foundation` collector registration.
- `crawler_log_foundation` collector registration.
- HTML snapshot parsing utilities for caller-provided HTML strings.
- Metadata drift comparison utilities for canonical, title, description, robots, JSON-LD, and hreflang fields.
- Sitemap / llms parity comparison utilities for normalized URL sets.
- Crawler user-agent classification for Googlebot, Bingbot, Baiduspider, 360Spider, Sogou, Shenma / Yisou, Bytespider, AI crawlers, unknown bots, and human / unknown traffic.
- Crawler log line parsing from fixtures or caller-provided strings without exposing raw IPs or cookies.

## Non-Changes

This PR does not:

- Run production migrations.
- Create the production `seo_intel` database.
- Enable collectors by default.
- Enable writes by default.
- Enable scheduler jobs or queue workers.
- Read production crawler logs.
- Crawl production broadly.
- Fetch public HTML by default.
- Connect GSC, Baidu, IndexNow, or Metabase.
- Modify CMS content, sitemap output, or llms output.
- Use Node2 local Laravel, Node2 local DB, or Node2 local queue as a data source.

## Collector Boundary

Both collectors remain dry-run safe:

- `enabled=false`
- `collectors_enabled=false`
- `write_enabled=false`
- `dry_run_default=true`
- `allow_external_api_calls=false`
- `allow_production_crawl=false`
- `allow_production_log_read=false`

Collector output reports hashes, counts, and issue types. It must not contain raw HTML bodies, raw IPs, cookies, emails, order numbers, attempt IDs, payment IDs, provider payloads, or secrets.

## Drift Foundation

`drift_foundation` exercises fixture-backed parsing and comparison only. It can parse a supplied HTML string and summarize:

- canonical URL hash
- title hash
- description hash
- robots hash
- JSON-LD count and types
- hreflang count
- metadata drift issue types
- sitemap / llms parity issue buckets

It records warnings in the collector result. It does not write issue queues and does not mutate CMS records.

## Crawler Log Foundation

`crawler_log_foundation` uses a fixture log line to prove parser shape. It returns:

- bot family
- path
- method
- status code
- response time
- timestamp
- user-agent hash

Raw IPs and cookies are intentionally not emitted.

## Deferred Work

Deferred to later PRs:

- public HTML single-URL smoke implementation
- production-safe crawl gating
- crawler log file access runbook
- drift issue queue writes
- CMS issue summary API
- GSC, Baidu, IndexNow collectors
- Metabase dashboards

## Next Task

Next task: `SEO-DASH-03A`.
