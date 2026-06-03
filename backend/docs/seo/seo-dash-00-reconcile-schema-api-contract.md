# SEO-DASH-00-RECONCILE seo_intel Schema and Read-Only API Contract

## Purpose

SEO-DASH-00-RECONCILE reconciles the current `seo_intel` foundation into a single
schema, privacy, source-of-truth, and read-only API contract for the next SEO
Operations dashboard phase.

This PR is contract-only. It does not add a runtime route, run production
migrations, create a production database, enable collector writes, connect live
GSC/Baidu/GA4 APIs, mutate CMS records, submit URLs, deploy, or modify `fap-web`.

## Current Foundation

The backend already contains the disabled-by-default `seo_intel` foundation:

- a named `seo_intel` Laravel database connection
- isolated `database/migrations/seo_intel` migrations
- `seo_urls` and `seo_url_entities` URL truth tables
- `seo_gsc_daily`, `seo_consent_daily`, attribution, revenue, crawler, search
  channel, and issue queue tables
- `/ops/seo` Filament read services for private operator visibility
- `/ops/seo-operations` CMS operations surfaces that must remain separate from
  the read-only dashboard contract

The foundation is not enough to treat `fap-web` as an authority system.
`fap-web` may consume a read-only API or generated artifact, but it must remain
a dashboard shell.

## Source-of-Truth Boundary

Authoritative systems:

- CMS/backend owns content, metadata, canonical URL, publish state, URL Truth,
  claim boundary, and resource identity.
- Backend orders, payments, and benefits own purchase and unlock truth.
- `seo_intel` observes, aggregates, and queues issues only.
- GSC, Baidu, GA4, crawler logs, sitemap, and competitor inventory are signals,
  not content or purchase authorities.
- `fap-web` is a public renderer and ops shell, not a CMS or SEO authority.

Forbidden authority sources:

- Node2 local Laravel, local DB, local queue, or local filesystem exports
- frontend fallback content or static sitemap/llms fallback as URL Truth
- GA4 or Baidu as purchase truth
- crawler/search observations as CMS publish truth

## Schema Reconciliation

The first read-only API should be backed by sanitized `seo_intel` read models
and may expose only aggregate or safe row fields from:

- `seo_urls`
- `seo_url_entities`
- `seo_issue_queue`
- `seo_gsc_daily`
- `seo_baidu_landing_daily`
- `seo_consent_daily`
- `seo_event_funnel_daily`
- `seo_landing_attribution_daily`
- `seo_revenue_daily`
- `seo_search_channel_queue_items`
- `seo_crawler_log_daily_aggregates`

The API contract must not expose raw business tables, CMS write tables, raw
orders, raw payments, raw browser event payloads, raw crawler logs, raw user
identifiers, provider payloads, cookies, tokens, or secrets.

## Issue Queue Reconciliation

Current backend storage uses `issue_uid`, `source_system`, `source_engine`, and
lifecycle values `open`, `acknowledged`, `resolved`, and `ignored`.

The dashboard/read API may present compatibility aliases:

- `issue_id` aliases storage `issue_uid`
- `source_signal` is derived from `source_system` and `source_engine`
- current `warning` severity maps to dashboard `medium`
- current `acknowledged` maps to dashboard `triaged`
- current `ignored` maps to dashboard `suppressed`

Future governance fields are not yet production schema. They remain required
future fields: `dedupe_key`, `muted_until`, `mute_reason`, `muted_by`,
`owner_team`, `sla_due_at`, `sla_policy`, `reopen_rule`, `reopened_at`,
`last_seen_at`, `occurrence_count`, and `closed_reason`.

## Consent Reconciliation

The read-only API should expose dashboard-facing consent states:

- `analytics_granted`
- `analytics_denied`
- `unknown`
- `not_applicable_backend_business_event`

Existing backend collector/config values may continue to accept
`granted`, `denied`, `unknown`, and `not_applicable` internally until a runtime
normalizer PR lands. External dashboard contract responses should use the
dashboard-facing values above.

Consent state must never be used to override backend purchase truth. Browser
analytics remains behavior telemetry only.

## PII and Safety Boundary

The read-only API must never expose:

- raw email
- raw IP
- raw user agent
- cookie
- token
- API key
- secret
- order number
- attempt id
- payment id
- provider event id
- raw request payload
- raw payment payload
- raw crawler log line

Safe evidence may use hashes, normalized URL hashes, masked display paths,
aggregate counts, and sanitized summaries.

## Read-Only API Contract

Future runtime PRs may add read-only endpoints after separate authorization.
Recommended route family:

- `GET /api/v0.5/ops/seo-intel/overview`
- `GET /api/v0.5/ops/seo-intel/url-truth`
- `GET /api/v0.5/ops/seo-intel/issues`
- `GET /api/v0.5/ops/seo-intel/trends`
- `GET /api/v0.5/ops/seo-intel/page-performance`

The route must be read-only and permission-gated. Preferred future permission:
`admin.seo_intel.read`. Until that permission is implemented, existing
`admin.owner` and `admin.ops.read` may remain the private Filament access
boundary only.

The API must not:

- mutate CMS
- publish, unpublish, or rollback content
- create or modify issue rows
- submit URLs
- retry search channel submissions
- trigger collector writes
- read production raw logs
- expose Metabase links publicly

## Next PRs

1. `SEO-DASH-API-01`: implement the read-only API route and permission contract.
2. `SEO-DASH-MIGRATION-01`: production `seo_intel` migration readiness with
   explicit human approval.
3. `SEO-DASH-COLLECTOR-01`: collector dry-run smoke and live-readiness gates.
4. `SEO-DASH-WEB-API-ADAPTER-01`: switch `fap-web` dashboard shell from artifact
   mock adapter to the read-only API.
