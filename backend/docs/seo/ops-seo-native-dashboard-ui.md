# OPS-SEO-NATIVE-DASH-02 Filament Native Dashboard UI

## Purpose

`/ops/seo` now renders a native read-only SEO Engine observability dashboard instead of the old static closeout shell.

The page keeps the existing Filament Ops route, auth boundary, and private Metabase boundary. It reads only from the `seo_intel` dashboard read model created in `OPS-SEO-NATIVE-DASH-01`.

## Dashboard Sections

- Overview heartbeat: URL Truth rows, entity mappings, Issue Queue rows, Search Channel Queue items, batches, and events.
- Safety heartbeat: Private-flow leaks, Forbidden authority, and Claim unsafe counters. These should remain zero.
- URL Truth distribution: `page_entity_type`, `locale`, `source_authority`, and `indexability_state`.
- Issue Queue overview: aggregate counts plus recent safe issue rows.
- Search Channel Queue overview: aggregate counts, recent safe queue rows, and event type summary.
- Boundary / hard stops: no public Metabase, no raw SQL, no external submission controls, no scheduler controls, no crawler raw logs, and CMS/backend URL Truth only.

## Read-only Boundary

The UI does not add write controls or mutate any runtime state.

- No Metabase iframe.
- No reverse proxy.
- No public Metabase URL.
- No raw SQL for operators.
- No approve/retry/submit buttons.
- No scheduler or collector controls.
- No GSC, Baidu, IndexNow, 360, Sogou, or Shenma live API calls.
- No sitemap or `llms.txt` behavior changes.

## Safe Display Fields

The page renders only normalized read-model fields:

- canonical path
- locale
- page entity type
- source authority
- indexability state
- issue type
- severity
- status
- channel
- approval state
- execution state
- created / updated timestamps
- event type aggregate counts

Raw metadata, attributes, reason codes, event payloads, evidence, IPs, user agents, cookies, tokens, emails, order IDs, payment IDs, attempt IDs, provider payloads, business DB fields, crawler log details, and Metabase secrets remain hidden.

## Deferred

The next PR adds richer Issue Queue and Search Channel Queue detail panels with scoped filters and pagination while keeping the surface read-only.

Next task: `OPS-SEO-NATIVE-DASH-03`
