# /ops/seo Observation Governance Display Readiness

## Purpose

This contract defines future read-only `/ops/seo` display readiness for SEO
observation governance. It is a dashboard contract only. It does not implement
Filament UI, services, migrations, action buttons, write controls, Search
Channel submission, crawler log readers, scheduler controls, collector controls,
CMS write controls, Metabase exposure, or production operations.

The `/ops/seo` page is an operational view, not a truth source. It may display
safe aggregates from approved SEO Intelligence read models after future
implementation, but it must not become URL Truth, content truth, Search Channel
authority, crawler-log authority, search-engine authority, or Metabase proxy.

## Display Authority Boundary

Allowed future display sources:

- sanitized SEO Intelligence observation queue summaries
- sanitized SEO Intelligence issue severity summaries
- sanitized SEO Intelligence entity-key coverage summaries
- sanitized SEO Intelligence crawler aggregate observation counters
- Digital PR observation-only placeholders without automated backlink decisions

Forbidden authority sources:

- frontend fallback authority
- static sitemap authority
- static llms authority
- raw crawler logs
- raw payloads
- raw SQL from operators
- Metabase iframe or proxy
- search engine responses as URL Truth
- local copy authority
- CMS writes from `/ops/seo`

## Required Future Sections

Future `/ops/seo` observation governance display may include these read-only
sections:

- Observation Queue summary by event_type
- Observation Queue summary by event_state
- pending runtime check count
- awaiting search observation count
- awaiting crawler observation count
- needs review count
- muted count
- Issue severity distribution P0/P1/P2/P3
- SLA due / overdue counters
- dedupe cluster counters
- entity key coverage
- missing translation_group_uuid count
- locale pair coverage
- Digital PR observation-only signal placeholders
- Crawler aggregate observation safety counters

## Read-only Panel Contract

Observation Queue panels should show aggregate counts only:

- event_type distribution
- event_state distribution
- priority distribution
- pending runtime checks
- awaiting search engine observation
- awaiting crawler observation
- needs_review and muted counts

Issue governance panels should show operational summaries only:

- P0/P1/P2/P3 distribution
- SLA due count
- SLA overdue count
- dedupe cluster count
- reopened issue count
- muted issue count

Entity governance panels should show coverage summaries only:

- entity_key coverage
- missing translation_group_uuid count
- locale pair coverage
- legacy_unpaired count
- surface-level coverage by research reports, articles, topics, personality
  pages, career guides, career jobs, test landing/detail pages, and
  content/support pages

Crawler and Digital PR panels must remain observation-only:

- crawler aggregate observation safety counters may use sanitized daily
  aggregate tables only
- Digital PR signal placeholders may show manual tracking status only
- backlink observed, referral observed, and mention observed must remain manual
  or safe aggregate observations and must not trigger automated decisions

## Hard Stops

The future `/ops/seo` governance display must include no search submit button.
It must include no approve/retry controls. It must include no scheduler controls.
It must include no collector controls. It must include no raw SQL. It must
include no Metabase iframe/proxy. It must include no raw crawler logs. It must
include no raw payload display. It must include no CMS write controls from this
page.
No CMS write controls from this page.

## Safety Flags

This PR does not implement UI. This PR does not implement services. This PR does
not add migrations. This PR does not add action buttons. This PR does not edit
production env. This PR does not modify fap-web. This PR does not expose
Metabase.

## Future Implementation Notes

Future implementation should be staged after the observation queue, issue
severity, and entity key contracts have implementation approval. Any runtime
panel must read from sanitized read models only, must avoid raw payload display,
and must keep all mutation controls out of `/ops/seo`.

## Final Decision

ops_seo_observation_governance_display_readiness_contract_ready_without_ui

Next task: `SEO-OBS-GOV-06`
