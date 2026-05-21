# CRAWLER-LOG-01 Readiness Scan

## Executive Summary

CRAWLER-LOG-01 is a read-only repository readiness scan for crawler log observability. It does not read production crawler logs, add a runtime parser, enable scheduler jobs, run collector writes, run migrations, deploy, edit environment files, call external search APIs, expose Metabase, or submit URLs.

Final readiness result: `ready_for_crawler_log_fixture_parser_mvp`.

The codebase is ready to proceed to a synthetic fixture parser MVP, with one important schema caveat: the existing `seo_crawler_logs_daily` table exists under the `seo_intel` connection, but it is an earlier aggregate shape and does not fully match the CRAWLER-LOG-00 V1 architecture contract. CRAWLER-LOG-02 should stay fixture-only and no-write. Any persistent aggregate write in a later PR must first reconcile the table shape or add a scoped `seo_intel` migration.

## Current Local State

- Current task: `CRAWLER-LOG-01`.
- Source branch target: `main`.
- Previous dependency: `CRAWLER-LOG-00` architecture contract.
- CRAWLER-LOG-00 merge commit observed in local main: `316223c6e9ece53da47bbd7523bb648a2cfb3c85`.
- Existing architecture contract files:
  - `backend/docs/seo/crawler-log-architecture-contract.md`
  - `backend/docs/seo/generated/crawler-log-architecture-contract.v1.json`
  - `backend/tests/Feature/SeoIntel/SeoIntelCrawlerLogArchitectureContractTest.php`
- Existing older readiness contract files:
  - `backend/docs/seo/crawler-log-readiness-contract.md`
  - `backend/docs/seo/generated/crawler-log-readiness-contract.v1.json`
  - `backend/tests/Feature/SeoIntel/SeoIntelCrawlerLogReadinessContractTest.php`

## Existing Crawler Log Surface

Existing crawler-log related code is present, but it is foundation-level and not the CRAWLER-LOG-00 V1 runtime:

- `backend/app/Services/SeoIntel/Collectors/CrawlerLogFoundationCollector.php`
- `backend/app/Services/SeoIntel/Collectors/ChineseCrawlerLogCollector.php`
- `backend/app/Services/SeoIntel/Drift/CrawlerLogLineParser.php`
- `backend/app/Services/SeoIntel/Drift/CrawlerUserAgentClassifier.php`
- `backend/app/Services/SeoIntel/CrawlerLogLineParser.php`
- `backend/app/Services/SeoIntel/CrawlerLogPrivacySanitizer.php`
- `backend/app/Services/SeoIntel/CrawlerLogDailyAggregator.php`
- `backend/app/Services/SeoIntel/ChineseCrawlerUserAgentClassifier.php`

The current manual collector command is `php artisan seo-intel:collect`. No dedicated `php artisan seo-intel:crawler-log-observe` command exists yet.

## Source Approval Readiness

Allowed future source families remain:

- nginx / OpenResty access log
- CDN edge access log
- ALB / SLB access log

No approved production crawler log source is configured in this scan. No production log path, source owner, retention window, masking posture, max line count, or read window is approved for live access.

Forbidden sources remain blocked:

- Node2 local Laravel logs
- Node2 local DB
- business DB logs
- payment logs
- provider webhook logs
- application debug logs
- raw request payload logs
- unapproved production raw access logs

## Schema Readiness

The existing migration `backend/database/migrations/seo_intel/2026_05_17_001600_create_seo_crawler_logs_daily_table.php` is scoped to:

`protected $connection = 'seo_intel';`

The table `seo_crawler_logs_daily` exists as an aggregate table. Current fields include:

- `report_date`
- `canonical_url_hash`
- `path_hash`
- `path_display_masked`
- `locale`
- `page_entity_type`
- `source_engine`
- `bot_family`
- `user_agent_hash`
- `method`
- `status_code`
- `response_time_bucket`
- `crawl_count`
- `robots_allowed`
- `blocked_by_robots`
- `private_flow_hit`
- `noindex_hit`
- `metadata_json`

CRAWLER-LOG-00 V1 requires a stricter aggregate shape:

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

Schema gap: the existing table does not contain the full V1 field set and includes fields now forbidden for V1 persistence, including `user_agent_hash`, `path_display_masked`, and `metadata_json`. CRAWLER-LOG-02 can proceed as fixture-only/no-write, but CRAWLER-LOG-03 or any persistent aggregate write needs a schema reconciliation decision first.

## Parser / Collector Readiness

Existing foundation collectors are disabled-by-default and fixture-based:

- `CrawlerLogFoundationCollector` parses one fixture line and reports `reads_production_logs=false`.
- `ChineseCrawlerLogCollector` uses synthetic fixture lines and reports `production_log_read_attempted=false`.
- `SeoIntelCollectorManager` forces `allow_production_log_read=false` unless global config allows it.
- `config/seo_intel.php` has `allow_production_log_read=false` and `chinese_crawler_live_log_read_enabled=false`.

Current parser gaps against CRAWLER-LOG-00 V1:

- no host / `surface_family` normalization
- no `bot_variant`
- no `bot_verification_state=ua_claim_only` output
- no V1 `route_family` map
- no `query_present` / `query_risk_state`
- private path denylist is incomplete against the V1 contract
- unknown public paths do not consistently use V1 `path_hash` only semantics
- current bot family names differ from V1 for several crawlers, such as `so360_spider`, `sogou_spider`, and `shenma_yisou`

These gaps are appropriate for CRAWLER-LOG-02 fixture parser MVP scope.

## Scheduler / Command Readiness

No crawler-log scheduler entry was found. `backend/app/Console/Kernel.php` does not schedule `seo-intel:collect`, `crawler_log_foundation`, `chinese_crawler_log_foundation`, or a crawler-log observe command.

The existing `seo-intel:collect` command has no `--production`, `--tail`, `--schedule`, or crawler-log `--write` mode. The CRAWLER-LOG-00 proposed command `seo-intel:crawler-log-observe` is not implemented yet.

## Privacy Boundary

CRAWLER-LOG-01 did not read production crawler logs and did not inspect raw production log lines.

V1 must continue to forbid persistence of:

- raw IP / remote address
- raw user-agent
- raw request URI
- raw query string
- cookies
- headers
- authorization
- sessions
- tokens / API keys
- emails
- order IDs
- attempt IDs
- payment IDs
- provider event IDs
- raw payloads
- raw log lines
- wide raw JSON payload fields

Current foundation code avoids production log access, but the existing aggregate table and foundation collector shape are not strict enough for V1 persistence. The next runtime PR must enforce the CRAWLER-LOG-00 privacy transform before any write path is introduced.

## URL Truth Boundary

Crawler logs remain observation data only. They must not:

- create `seo_urls`
- decide canonical truth
- decide indexability truth
- create Search Channel Queue rows
- submit URLs
- auto-write `seo_issue_queue`
- use frontend fallback, static sitemap, static `llms.txt`, Node2 local DB, crawler logs, or external search sources as URL authority

Unknown crawler paths can only become sanitized future issue candidates after a separate approval and sanitization step.

## Recommended Next PR

Next task: `CRAWLER-LOG-02｜Fixture parser MVP`.

Recommended scope:

- add synthetic fixture file only
- add a V1 parser/normalizer service that accepts fixture lines
- output aggregate DTO-style rows in memory
- prove no raw IP, raw UA, raw URI, query string, cookies, headers, tokens, private paths, or raw log lines are returned
- prove V1 bot families, route families, surface families, query risk states, and private path denylist behavior
- no database writes
- no production log read
- no scheduler
- no external API

## No-go Conditions

Stop future crawler-log work if any of these appear:

- production log read without explicit canary approval
- raw IP / raw UA / raw URI / query / cookie / header persistence
- crawler logs used as URL Truth
- crawler logs creating Search Channel Queue rows
- crawler logs auto-writing `seo_issue_queue`
- scheduler activation
- collector write mode before schema reconciliation
- Node2 local DB or business DB access
- Metabase exposure
- search engine URL submission

## Final Decision

`ready_for_crawler_log_fixture_parser_mvp`

## Next Task

`CRAWLER-LOG-02｜Fixture parser MVP`
