# Research Asset Backend MVP

PR-RESEARCH-01 adds the backend/CMS authority foundation for Research Asset records. It does not import report content, publish Research, add sitemap entries, add `llms.txt` entries, enqueue Search Channel submissions, run collector writes, or change production infrastructure.

## Authority Boundary

Research Assets are backend/CMS authoritative. Frontend runtime may render only payloads returned by the Research public API. Frontend local files, hardcoded report copy, static JSON, sitemap fallback, and `llms.txt` fallback must not become Research truth.

## Entity Contract

Research records use:

- model: `ResearchReport`
- table: `research_reports`
- public page entity type: `research_report`
- public route family: `/research/{slug}`
- CMS route family: `/api/v0.5/internal/research-reports/{slug}`
- public API route family: `/api/v0.5/research/{slug}`

Required metadata fields:

- `research_type`
- `methodology`
- `sample_disclaimer`
- `claim_boundary`
- `author_name`
- `reviewer_name`
- `references`
- `last_reviewed_at`
- `downloadable_asset_placeholder`

The initial allowed `research_type` values are:

- `salary_turnover`
- `methodology`
- `psychometric_research`
- `market_research`
- `other`

## Draft / Published Gate

Public reads require all gates:

- `status = published`
- `review_state = approved`
- `is_public = true`
- `is_indexable = true`
- `published_at` is null or not in the future

Draft, archived, unapproved, private, noindex, and future-scheduled Research records return 404 from the public API. Internal CMS endpoints may read and update draft records behind CMS admin middleware.

Draft or archived records cannot be marked public or indexable. Published records require the approved review state.

## Public API Boundary

The public API exposes only published/indexable Research records and safe Research metadata. It does not expose business DB data, user reports, orders, payments, attempts, emails, raw event detail, raw crawler logs, provider payloads, payment payloads, cookies, raw IPs, or private CMS write data.

## SEO / Search Boundary

This PR does not make Research eligible for sitemap, `llms.txt`, URL Truth, Search Channel Queue, Search Channel live submission, Dataset schema, or pSEO. Internal payloads explicitly keep:

- `sitemap_eligible = false`
- `llms_eligible = false`
- `search_channel_eligible = false`

Those gates must be defined by PR-RESEARCH-03 before any exposure change.

## Claim Boundary

Research copy must remain claim-safe. Research Assets must not claim diagnosis, treatment, cure, hiring fit, job competency, exact IQ, guaranteed salary, guaranteed turnover prediction, guaranteed career outcome, or AI career planning authority.

RIASEC, Big Five, and career recommendation language remains bounded. This PR does not expand those claim surfaces.

## What Was Not Done

- No Research content imported.
- No Research content published.
- No sitemap behavior changed.
- No `llms.txt` behavior changed.
- No Search Channel Queue or live submission changed.
- No Metabase operation performed.
- No collector write or scheduler activation performed.
- No production env, deploy, RDS, DB user, whitelist, DNS, CDN, or OpenResty change performed.
