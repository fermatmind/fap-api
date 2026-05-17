# Metabase MVP Dashboard Specification

## Purpose

SEO-DASH-05 defines the Metabase MVP dashboard policy for FermatMind Search Intelligence.
This PR does not deploy Metabase, add credentials, create a production connection, create production read-only users, or run production migrations.

## Data Sources

Metabase may read only sanitized `seo_intel` aggregate tables and views.

Metabase must not connect to or query:

- business DB
- CMS write tables
- Node2 local DB
- raw orders
- raw payments
- raw email
- raw event detail
- raw crawler logs

Purchase truth comes from backend orders, payments, and benefits aggregates. GA4, Baidu, GSC, IndexNow, domestic search adapters, and crawler logs are not purchase truth.

Search channels are feedback or adapter signals only. They are not alternate SEO truth. Crawler hits do not grant indexability.

## Read-Only Policy

- Metabase must use a read-only DB user.
- The connection must be scoped to `seo_intel`.
- Metabase must not write.
- Metabase must not query CMS write tables or business DB tables.
- Metabase must not query Node2 local Laravel, DB, queue, php84, or fap-mysql.
- Metabase must not expose raw email, raw order numbers, raw attempt IDs, provider event IDs, payment IDs, raw IPs, cookies, raw user agents, raw payloads, or payment payloads.

## MVP Dashboards

### URL Truth & Drift

- URL count by `page_entity_type`
- `indexability_state`
- drift issue counts
- sitemap/llms parity warnings
- private/noindex exposure warnings

### Search Channel Health

- GSC rows, clicks, impressions, CTR, and position
- Baidu push dry-run/submission status
- IndexNow submission status
- 360/Sogou/Shenma verification and submission status
- `source_engine` breakdown

### Landing Attribution

- `landing_event_count`
- `start_attempt_count`
- `submit_attempt_count`
- `view_result_count`
- `click_unlock_count`
- `create_order_count`
- `purchase_success_count`
- `source_engine`
- `consent_state`
- `traffic_quality`

### Revenue / Cluster

- `revenue_cents`
- `orders_count`
- `purchase_count`
- AOV
- RPV proxy
- purchase rate
- `cluster`
- `page_entity_type`

### Crawler Health

- `bot_family`
- `source_engine`
- `crawl_count`
- `status_code`
- `response_time_bucket`
- private flow hits
- noindex hits

### Internal / QA Filtering

- internal, QA, bot, and non-production counts
- excluded traffic counts
- `traffic_quality` breakdown

## Sanitized Views

This PR defines view contracts only in `backend/docs/seo/generated/metabase-mvp-dashboard.v1.json`.
It does not create SQL views in production.

Planned sanitized views:

- `seo_v_url_truth_overview`
- `seo_v_search_channel_health`
- `seo_v_landing_attribution_daily`
- `seo_v_revenue_cluster_daily`
- `seo_v_crawler_health_daily`
- `seo_v_internal_traffic_filtering`

The views expose aggregate counts, hashes, masked display fields, dates, statuses, source engines, clusters, and page/entity categories only.

## Semantic Boundaries

Dashboard names and metrics must not imply that RIASEC, Big Five, Career Decision, or AI career planning are full recommender runtimes. Use neutral metric labels such as `career_support_view`, `career_direction_page_view`, `riasec_result_view`, `workstyle_content_view`, and `interest_signal_page_view`.

## Next Task

Next task: SEO-DASH-06.
