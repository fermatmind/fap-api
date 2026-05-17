# Chinese Crawler Log Collector Foundation

## Purpose

CHINA-SEARCH-04 adds the disabled-by-default Chinese crawler log collector foundation for FermatMind Search Intelligence.
It is fixture-only and dry-run safe. It does not read production logs, connect CDN logs, change OpenResty or Nginx, or enable scheduler or queue execution.

## Scope and Non-Changes

- Adds logical `seo_crawler_logs_daily` schema for aggregated crawler observations.
- Adds Chinese crawler user-agent classification for Baiduspider, 360Spider, Sogou, Shenma/Yisou, Bytespider, and AI crawlers.
- Adds fixture log parsing, privacy sanitization, daily aggregation, and a dry-run collector.
- Does not read production logs.
- Does not store raw IPs, raw cookies, raw user agents, raw log lines, auth headers, emails, order numbers, attempt IDs, payment IDs, provider event IDs, tokens, or secrets.
- Does not change sitemap, llms, CMS, payment, order, report, email, recommendation, or scoring behavior.
- Does not use Node2 local Laravel, DB, queue, php84, or fap-mysql as a data source.

## Data Boundary

Chinese crawler logs are observation data only. They are not SEO truth, business truth, or purchase truth.

Crawler hits do not grant indexability and do not make draft, private, noindex, or claim-unsafe URLs eligible for search submission. Private-flow or noindex crawler hits are warnings/issues only.

Purchase truth remains backend orders, payments, and benefits. Crawler logs must not directly attribute purchase.

## Supported Bot Families

- `googlebot`
- `bingbot`
- `baiduspider`
- `so360_spider`
- `sogou_spider`
- `shenma_yisou`
- `bytespider`
- `ai_crawler`
- `unknown_bot`
- `human_or_unknown`

## Source Engine Mapping

- `googlebot` -> `google`
- `bingbot` -> `bing_indexnow`
- `baiduspider` -> `baidu`
- `so360_spider` -> `so360`
- `sogou_spider` -> `sogou`
- `shenma_yisou` -> `shenma`
- `bytespider` -> `ai_search`
- `ai_crawler` -> `ai_search`
- `unknown_bot` -> `unknown`
- `human_or_unknown` -> `unknown`

## Privacy Rules

The parser strips query strings before display or hashing. It stores only path hashes, masked display paths, and user-agent hashes. It does not expose raw IPs, raw cookies, raw user-agent strings, raw request lines, auth headers, or query secrets.

Private flow path prefixes such as `/take`, `/result`, `/orders`, `/share`, `/pay`, `/checkout`, and private report paths are flagged with `private_flow_hit=true`.

## Deferred

- Production log path/access approval.
- CDN/OpenResty/Nginx log integration.
- Scheduler activation.
- Queue workers.
- Production `seo_intel` DB creation or migration execution.
- Metabase deployment.

## Next Task

Next task: SEO-DASH-05.
