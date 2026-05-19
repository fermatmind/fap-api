# URL Truth MVP Dashboard Spec and Sanitized Views Plan

## Purpose

SEO-DASH-PROD-04B defines the first dashboard and sanitized view plan for the post-03D `seo_intel` state.
This is a docs, generated-contract, and test PR only. It does not deploy Metabase, create SQL views, add credentials, edit environment files, run collector writes, enable scheduler, or connect external search APIs.

## Current Safe Data State

The MVP dashboard starts from the controlled canary state:

- `seo_urls`: 7 rows
- `seo_url_entities`: 7 rows
- `seo_issue_queue`: 5 rows
- all other checked collector tables: 0 rows

Observed safe source authorities are:

- `backend_public_surface`
- `scale_catalog`

The dashboard must show empty-state panels for collectors that have not passed a production write canary. Empty-state panels must not imply missing production data is a failure.

## Dashboard Groups

### URL Truth Overview

Purpose: confirm the current backend-authoritative URL Truth inventory.

Required cards:

- total URL count
- total URL/entity mapping count
- count by `page_entity_type`
- count by `locale`
- count by `indexability_state`
- count by `source_authority`
- latest `first_seen_at` and `last_seen_at` ranges

The source authority distribution must make backend-approved authorities visible and must keep frontend, static, and Node2 sources out of the truth model.

### Issue Queue Overview

Purpose: inspect sanitized drift and URL Truth observations created by controlled canaries.

Required cards:

- issue count by `issue_type`
- issue count by `severity`
- issue count by `status`
- issue count by source table family
- newest issue observation timestamp

Issue cards must not expose raw evidence, raw URLs with query strings, raw payloads, raw identifiers, or raw PII.

### Safety Checks

Purpose: keep authority, privacy, and indexability boundaries visible.

Required cards:

- forbidden source authority count
- private-flow count
- private-flow indexable count
- unsupported page entity type count
- missing URL/entity mapping count
- missing or unknown indexability state count
- missing lastmod for indexable URL count

### Collector Empty States

Purpose: make not-yet-enabled collectors visible without pretending they have data.

Required cards:

- GSC daily rows
- Baidu push rows
- IndexNow submission rows
- domestic submission rows
- crawler daily rows
- event funnel rows
- revenue rows
- cluster rows
- consent rows

All of these cards should display zero/empty until the relevant collector passes a controlled write canary or an approved read-only dashboard operation creates a sanitized view.

## Sanitized View Plan

This PR defines view contracts only. It does not create SQL views.

Planned sanitized views:

- `seo_v_url_truth_mvp_overview`
- `seo_v_url_truth_authority_distribution`
- `seo_v_url_truth_indexability_distribution`
- `seo_v_url_truth_private_flow_safety`
- `seo_v_issue_queue_mvp_overview`
- `seo_v_collector_empty_state_status`

Views may source only from `seo_intel` tables and must expose aggregate counts, dates, safe enums, status values, page entity types, locale, source authority, and sanitized issue types.

Views must not source from:

- business DB
- CMS write tables
- Node2 local DB
- Node2 local Laravel runtime
- frontend fallback
- static sitemap fallback
- static `llms.txt` fallback
- live search APIs
- production crawler logs

Views must not expose:

- raw email
- raw order number
- raw attempt ID
- payment ID
- provider event ID
- cookie
- raw IP
- raw user agent
- raw payload, payment payload, or provider payload
- token, API key, or secret

## Dashboard Access Boundary

The dashboard is intended for a future Metabase connection that reads only `seo_intel` through the SEO-DASH-PROD-04A read-only boundary.
No business DB connection, CMS write table connection, Node2 local source, or production crawler log source is allowed.

## Stop Conditions

Stop implementation or production planning if any of these occur:

- a sanitized view requires a business DB source
- a sanitized view exposes raw PII or raw evidence
- a dashboard needs raw order, payment, event, email, user, report, or attempt data
- frontend fallback, static sitemap fallback, or static `llms.txt` fallback becomes dashboard truth
- private-flow pages appear as indexable
- Metabase deployment or credentials are introduced in this PR
- scheduler, collector writes, external search APIs, URL submission, or production crawler log reads are triggered

## Next Task

Next task: PR-RESEARCH-00.
