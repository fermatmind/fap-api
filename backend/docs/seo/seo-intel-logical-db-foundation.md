# SEO-DASH-01A seo_intel Logical DB Foundation

Version: 1
Status: schema/config/docs foundation only

## Purpose

This document records the SEO-DASH-01A logical database foundation for FermatMind Search Intelligence. It introduces a disabled-by-default `seo_intel` configuration boundary, a named Laravel database connection, and the first foundation migrations for URL inventory and non-PII internal traffic exclusion rules.

This PR does not implement collectors, scheduler jobs, dashboards, external search integrations, production database creation, or production migration execution.

## Non-Changes

- No production `seo_intel` database was created.
- No production migration was run.
- `seo_intel` is disabled by default.
- `seo_intel` writes are disabled by default.
- SEO collectors are disabled by default.
- Metabase is not deployed.
- GSC, Baidu, and IndexNow are not connected.
- Node2 local Laravel, local DB, and local queue are not data sources.
- fap-web runtime, sitemap, llms, payment, order, report, email, recommendation, and scoring behavior are unchanged.

## Configuration Boundary

The `seo_intel` config uses explicit environment keys only and defaults to a non-operational state:

- `SEO_INTEL_ENABLED=false`
- `SEO_INTEL_DB_CONNECTION=seo_intel`
- `SEO_INTEL_WRITE_ENABLED=false`
- `SEO_INTEL_COLLECTORS_ENABLED=false`

The database connection is named `seo_intel` and must be pointed at a separately approved logical SEO intelligence database before any production activation. It must not default to the business database or Node2 local database.

## Tables Added

### seo_urls

Canonical URL-level SEO inventory foundation.

Primary key model:

- SEO URL key: `canonical_url_hash + locale`
- URL entity hint: `page_entity_type + entity_id_or_slug + locale`

This table stores URL inventory metadata such as source authority, indexability state, lastmod evidence, cluster, first/last seen timestamps, and non-sensitive metadata JSON.

### seo_url_entities

Entity-level mapping from an SEO URL to the backend/CMS authority object.

Allowed page entity types are documented here and in the generated contract artifact:

- `home`
- `test_hub`
- `test_detail`
- `article`
- `topic`
- `personality`
- `career_job`
- `career_recommendation`
- `methodology`
- `dataset`
- `report_preview`
- `landing_page`

Forbidden SEO entity types:

- `take`
- `result`
- `order`
- `share`
- `pay`
- `checkout`
- `report_private`

Private result/order/take/share/pay flows are not SEO URL entities.

### seo_internal_traffic_rules

Non-PII rule foundation for excluding QA, internal, bot, crawler, and non-production traffic from default dashboards.

Allowed rule examples:

- `utm`
- `qa_campaign`
- `internal_ip_hash`
- `qa_email_hash`
- `test_user_hash`
- `test_order_hash`
- `bot_user_agent`
- `environment`

Rules must use hashes or masked display values. Raw email, raw IP, raw order identifiers, raw attempt identifiers, raw cookies, and raw payment identifiers must not be stored here.

## PII Guardrails

The foundation tables intentionally do not include these forbidden columns:

- `email`
- `order_no`
- `attempt_id`
- `payment_id`
- `provider_event_id`
- `cookie`
- `raw_payload`
- `payment_payload`

Normal dashboards must not expose raw order identifiers or raw attempt identifiers. Payment provider event identifiers and payment payloads must not enter `seo_intel` detail. Browser analytics and search channel telemetry are not purchase truth.

## Source-of-Truth Boundary

The data source hierarchy remains:

1. Backend business truth.
2. Backend events.
3. fap-web public runtime.
4. Search engine data.
5. Browser analytics.

Purchase truth comes from backend order, payment, and benefit state, not GA4 or Baidu. `/api/track` is transport, not final source of truth. SEO Collector must not read Node2 local Laravel.

## Deferred Tables

These logical tables are intentionally deferred to later PRs:

- `seo_event_funnel_daily`
- `seo_landing_attribution_daily`
- `seo_revenue_daily`
- `seo_cluster_daily`
- `seo_search_channel_status`
- `seo_consent_daily`
- `seo_issue_queue`
- `seo_gsc_daily`
- `seo_baidu_push_logs`
- `seo_indexnow_submissions`
- `seo_crawler_logs_daily`

## Next Task

The next implementation task is `SEO-DASH-01B`: disabled collector skeleton. It must not enable collectors or scheduler jobs by default.
