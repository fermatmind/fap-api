# CRAWLER-LOG-02 Fixture Parser MVP

## Purpose

CRAWLER-LOG-02 adds a synthetic fixture-only parser MVP for crawler log observability. It proves the CRAWLER-LOG-00 privacy transform and normalization rules can be applied before any production log read, command activation, scheduler, migration, or database write.

Crawler logs remain aggregate observability only. Crawler logs are not URL Truth, not Search Channel Queue, not CMS authority, not canonical truth, not indexability truth, and not an issue auto-fix system.

## What Changed

- Added `CrawlerLogFixtureParser` under `App\Services\SeoIntel\CrawlerLog`.
- Added a synthetic nginx-style fixture at `backend/tests/Fixtures/SeoIntel/crawler_logs/nginx_access_sample.log`.
- Added focused tests proving sanitized parser output, bot normalization, path privacy, query risk classification, and no raw persistence.
- Added generated contract artifact for the fixture parser MVP.

## Fixture Scope

The fixture covers:

- Googlebot visiting a Research URL with HTTP 200.
- Baiduspider visiting a test detail URL with HTTP 200.
- Bingbot visiting an unknown public path with HTTP 404.
- Ordinary browser visiting the home page with HTTP 200.
- Googlebot visiting a private result path.
- Sogou visiting a URL with a sensitive query key.
- A spoofed Googlebot user-agent claim.
- Static asset access.
- API host access.
- Ops host access.

The fixture is synthetic test data only. It is not a production log, not a production export, and not a live source approval.

## Parser Output

The parser returns in-memory sanitized rows and a dry-run report only. It does not write `seo_crawler_logs_daily`, `seo_urls`, `seo_issue_queue`, Search Channel Queue tables, Metabase, or any external service.

V1 output fields:

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
- `seo_candidate`
- `hit_count`
- `first_seen_at`
- `last_seen_at`
- `source_log_family`
- `privacy_transform_version`

Privacy transform version: `crawler_log_privacy_transform_v1`.

## Privacy Boundary

The parser may inspect raw fixture line fields in memory, then drops them before returning output.

Forbidden output fields:

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
- raw JSON payload fields

Private paths, API paths, Ops paths, static assets, and unknown public paths do not return raw path text as `canonical_path`; they use `path_hash` only.

## Bot Normalization

V1 verification state is fixed to `ua_claim_only`. No DNS reverse verification is performed in this PR.

Supported normalized bot families include:

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

## URL Truth Boundary

Known public fixture paths may map to safe canonical paths for parser verification, but crawler logs do not create URL Truth.

Crawler logs must not:

- create `seo_urls`
- infer canonical truth
- infer indexability truth
- create Search Channel Queue rows
- submit URLs
- auto-write `seo_issue_queue`
- use frontend fallback, static sitemap, static `llms.txt`, local copies, Node2 local DB, crawler logs, or external search surfaces as authority

## Safety Flags

- no production crawler log read
- no database writes
- no collector writes
- no scheduler
- no command activation
- no migration
- no deployment
- no env edit
- no external search API call
- no search submission
- no Metabase exposure
- no business DB / Tencent RDS / Node2 access

## Next Task

`CRAWLER-LOG-03｜Aggregate dry-run / no-write`
