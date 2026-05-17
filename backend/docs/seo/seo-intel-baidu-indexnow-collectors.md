# SEO Intelligence Baidu and IndexNow Collector Foundations

## Purpose

SEO-DASH-04B adds disabled Baidu and IndexNow collector foundations for FermatMind Search Intelligence.

This is not a production deployment. It does not connect live Baidu or IndexNow APIs, add credentials, submit real URLs, enable scheduler jobs, create queue workers, deploy Metabase, or change runtime behavior.

## Search Channel Boundary

Baidu and IndexNow are search channel adapters. They are not alternate SEO truth, business truth, or purchase truth.

Backend orders, payments, and benefit grants remain purchase truth. Search channels must not directly attribute purchase, and keyword/query/channel feedback cannot become a revenue authority.

## Collectors

Collectors added:

- `baidu_foundation`
- `indexnow_foundation`

Default state:

- `enabled=false`
- `collectors_enabled=false`
- `write_enabled=false`
- `dry_run_default=true`
- `baidu_enabled=false`
- `baidu_live_api_enabled=false`
- `indexnow_enabled=false`
- `indexnow_live_api_enabled=false`
- `allow_external_api_calls=false`

Dry-run collectors use fixture-only normalized URL candidates and return safe hash/count metadata. They do not require credentials, call external APIs, write the database, or submit URLs.

## URL Eligibility

Future live submission eligibility must require controlled-publish, public-runtime-verified, indexable URLs.

The validators reject:

- draft URLs
- private flows
- non-indexable URLs
- malformed canonical URLs

Draft URLs must never be submitted. Private result/order/take/share/pay flows must never be submitted.

## Baidu Boundary

Baidu push is not equivalent to Baidu indexation. A future successful push response would only mean the endpoint accepted a submission request, not that a page is indexed or ranked.

SEO-DASH-04B only adds push log and Baidu landing foundation schema. It does not connect Baidu Resource Platform, Baidu Tongji, Baidu push tokens, or live endpoints.

## IndexNow Boundary

IndexNow submission is not equivalent to indexing or ranking. A future successful submission would only mean the endpoint accepted a URL update signal.

SEO-DASH-04B only adds IndexNow submission foundation schema. It does not add IndexNow keys, live endpoints, or provider submission calls.

## Schema

Tables added:

- `seo_baidu_push_logs`
- `seo_baidu_landing_daily`
- `seo_indexnow_submissions`

Forbidden detail fields remain excluded:

- email
- order numbers
- attempt ids
- payment ids
- provider event ids
- cookies
- raw payloads
- raw payment payloads
- raw IPs
- Baidu tokens
- IndexNow keys
- API keys
- secrets

## Deferred Items

The following remain deferred:

- live Baidu credential setup
- live IndexNow key setup
- production URL submission
- production backfill
- scheduler activation
- queue worker activation
- Metabase deployment
- GSC live connector changes
- sitemap or llms behavior changes
- engine-specific page generation

## Next Task

Next task: CHINA-SEARCH-03.
