# Research SEO GEO Search Channel Contract

## Purpose

PR-RESEARCH-03 defines the gated SEO/GEO/Search Channel contract for Research Assets. It does not publish Research, change sitemap behavior, change `llms.txt` behavior, enqueue Search Channel records, submit URLs, enable live search APIs, run collector writes, enable scheduler, or create pSEO.

## Authority Boundary

Research pages use `page_entity_type = research_report`.

Allowed authority inputs:

- backend/CMS Research Asset records
- backend public Research payloads returned only for published, approved, public, indexable records
- fap-web Research runtime that renders backend payloads only
- URL Truth observation in `seo_intel`
- future sanitized aggregate tables or views derived from `seo_intel`

Forbidden authority inputs:

- frontend fallback content
- static sitemap fallback
- static `llms.txt` fallback
- Node2 local DB
- Node2 local Laravel
- business DB raw tables
- Metabase business DB connections
- production crawler logs
- raw orders, payments, events, reports, email, provider payloads, payment payloads, cookies, raw IPs, or user agents

## Sitemap Eligibility Gate

A Research URL may enter sitemap only after all gates pass:

- backend CMS source exists
- `page_entity_type = research_report`
- status is published
- review state is approved
- record is public
- record is indexable
- canonical path is present
- locale is explicit
- fap-web route renders backend payload only
- URL Truth supports and observes `research_report`
- claim boundary review passed
- methodology, sample disclaimer, references, author, reviewer, and last reviewed date are present
- no private-flow, raw PII, raw evidence, user-specific report data, or noindex state is present

Draft, archived, private, unapproved, noindex, future-scheduled, unsupported, and claim-unsafe Research records must remain excluded.

## llms.txt Eligibility Gate

Research may enter `llms.txt` only after the sitemap gate passes and a separate `llms.txt` inclusion review confirms:

- the content is public and indexable
- the summary is sanitized
- claim boundaries are preserved
- methodology and sample disclaimer are visible
- references are present
- no raw evidence, private payload, PII, order, payment, attempt, email, cookie, raw IP, or provider payload is exposed
- frontend/static fallback is not used as enumeration truth

## Search Channel Queue Eligibility Gate

Research may enter Search Channel Queue only after:

- sitemap eligibility passes
- URL Truth supports `research_report`
- source authority is backend/CMS
- indexability is explicit and indexable
- claim boundary review passed
- the Search Channel Queue contract accepts `research_report`
- channel credentials, quota, owner, and live-operation approval are completed in a later task

This PR does not create queue records, submit URLs, connect GSC, connect Baidu, connect IndexNow, connect domestic adapters, or enable scheduler.

## URL Truth Support

URL Truth must treat Research as a backend-authoritative page type:

- page entity type: `research_report`
- source authority: backend CMS
- safe observed tables: `seo_urls`, `seo_url_entities`, `seo_issue_queue`
- required states: canonical present, locale explicit, indexability explicit, private-flow absent, forbidden authority absent

Frontend fallback, sitemap fallback, `llms.txt` fallback, live search responses, Node2 local sources, and production crawler logs must not become URL Truth.

## Claim Boundary

Research must remain bounded to evidence-aware, non-diagnostic, reference-oriented language. It must not claim diagnosis, treatment, cure, hiring fit, job competency, exact IQ, guaranteed salary, guaranteed turnover prediction, guaranteed career outcome, or AI career planning authority.

RIASEC, Big Five, and career recommendation surfaces must not expand into full career recommendation claims in this contract.

## Dataset Schema Gate

Dataset schema remains blocked unless a later contract confirms:

- a versioned downloadable asset exists
- the file is stable and public
- checksum or immutable version is present
- license or usage note is present
- methodology and sample disclaimer are attached
- no raw PII or private evidence is included
- the backend contract explicitly marks the asset dataset-ready

This PR does not enable Dataset schema.

## What Was Not Done

- No Research content published.
- No sitemap behavior changed.
- No `llms.txt` behavior changed.
- No Search Channel Queue insertion performed.
- No URL submitted to search engines.
- No live GSC, Baidu, IndexNow, 360, Sogou, or Shenma API connected.
- No scheduler or collector write enabled.
- No production crawler logs read.
- No Metabase operation performed.
- No production env, deploy, RDS, DB user, whitelist, DNS, CDN, or OpenResty change performed.
- No pSEO created.

## Next Task

Next task: `RESEARCH-PUBLISH-READINESS-00`.
