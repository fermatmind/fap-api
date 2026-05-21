# OPS-SEO-NATIVE-DASH-01 Read Model

Task: `OPS-SEO-NATIVE-DASH-01`

This PR adds the native read-only `/ops/seo` dashboard read model for the Laravel Filament Ops portal.
It is the backend read layer for the native read-only /ops/seo dashboard read model.

## Scope

- Add service-only read services under `App\Services\SeoIntel\OpsDashboard`.
- Read only from the approved `seo_intel` connection.
- Return small DTO-style arrays for heartbeat KPIs, safety counters, URL Truth distributions, Issue Queue aggregates, Search Channel Queue aggregates, and safe recent rows.

## Allowed Tables

- `seo_urls`
- `seo_url_entities`
- `seo_issue_queue`
- `seo_search_channel_queue_items`
- `seo_search_channel_queue_batches`
- `seo_search_channel_queue_events`

## Boundary

- No writes.
- No Metabase.
- No raw SQL for operators.
- No business DB, Tencent RDS, Node2 local DB, or crawler raw log reads.
- No queue approval, retry, submit, scheduler, or collector controls.

## Safe Field Policy

Allowed read-model output is limited to safe aggregate counts and safe row fields such as canonical path, locale, page entity type, source authority, indexability state, issue type, severity, status, channel, approval state, execution state, and timestamps.

The read model must not expose:

- `metadata_json`
- `attributes_json`
- `event_payload`
- raw evidence
- raw payload
- raw IP
- raw user agent
- cookies
- tokens
- emails
- order IDs
- payment IDs
- attempt IDs

## Implementation Shape

Use a service-only read model.

- `SeoDashboardOverviewReadService`
- `SeoUrlTruthReadService`
- `SeoIssueQueueReadService`
- `SeoSearchChannelQueueReadService`

Each service uses the Laravel query builder on the `seo_intel` connection. This PR does not add dashboard UI rendering.

## What Was Not Done

- No Filament page or Blade changes.
- No migrations.
- No env edits.
- No deployment.
- No Metabase iframe/proxy/API coupling.
- No external search API call.
- No scheduler or collector activation.

Next task: `OPS-SEO-NATIVE-DASH-02`
