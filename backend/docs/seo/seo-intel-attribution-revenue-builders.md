# SEO Intelligence Attribution and Revenue Builders

## Purpose

This document records the SEO-DASH-03B attribution and revenue builder foundation. It adds daily aggregate schema and disabled-by-default builder services for Search Intelligence reporting. It does not enable production writes, schedulers, queue workers, external APIs, or dashboard deployment.

## Source of Truth

Purchase truth comes from backend orders, payment, and benefit grant records. GA4 and Baidu are behavior telemetry only and must not be used as purchase truth. `/api/track` remains a browser transport surface, not the final source of truth for revenue or purchase status.

## Aggregate Tables

This PR adds planned daily aggregate tables:

- `seo_event_funnel_daily` for canonical funnel event counts.
- `seo_landing_attribution_daily` for first-touch, last-touch, CTA-touch, and landing event aggregates.
- `seo_revenue_daily` for backend-business-truth revenue aggregates, AOV, RPV proxy, and purchase rate.
- `seo_cluster_daily` for cluster-level funnel and revenue rollups.
- `seo_consent_daily` for consent-state event counts.

These tables are schema foundation only. Production migration execution is still prohibited until human approval.

## Builder Boundaries

The builders aggregate fixture or test/local inputs only in this PR. They normalize `source_engine` and `consent_state`, exclude internal/QA/bot/non-production traffic by default, and keep only aggregate counters. Keyword-to-purchase attribution is forbidden.

`AOV` is computed as revenue divided by backend order count. `RPV_proxy` is explicitly named a proxy because it uses available proxy denominators such as result views or session-like counts, not a guaranteed session source.

## PII Boundary

The aggregate schema and builders must not output email, raw cookies, raw order numbers, raw attempt identifiers, payment identifiers, provider event identifiers, payment payloads, raw IPs, or raw payloads. Normal dashboards must use aggregate rows only.

## Disabled by Default

The `attribution_revenue_foundation` collector remains disabled by default. Writes require both collectors and writes to be explicitly enabled in future approved local/test contexts. No scheduler activation, queue worker, external search connector, GSC, Baidu, IndexNow, Metabase, production crawl, or production log read is introduced.

## Runtime Boundary

Node2 local Laravel, local DB, and local queue remain non-authority and are not data sources. Backend authority remains the Node3/fap-api-prod candidate accepted by BACKEND-RUNTIME-02D.

## Semantic Boundary

RIASEC, Big Five, and Career Decision are not modeled as full recommender runtimes. Metric names must stay at the support/content/signal level rather than claiming full AI career planning, diagnosis, or IQ authority.

## Next Task

The next task is `SEO-DASH-04A`, covering disabled-by-default first search channel collector foundations.
