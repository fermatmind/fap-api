# CHINA-SEARCH-03 360 / Sogou / Shenma Adapter Contracts

## Purpose

This document defines disabled-by-default adapter contracts for 360, Sogou, and Shenma search channels after SEO-DASH-04B. It does not connect live APIs, add credentials, submit URLs, deploy scheduler jobs, or generate search-engine-specific pages.

## Adapter Boundary

360, Sogou, and Shenma are domestic search channel adapters. They are not alternate SEO truth, CMS truth, backend truth, or purchase truth. The Search Intelligence source hierarchy remains backend business truth, backend events, fap-web public runtime, search engine feedback, then browser analytics.

The adapter names are:

- `so360_foundation`
- `sogou_foundation`
- `shenma_foundation`

Each adapter is fixture-only and dry-run safe in this PR.

## Disabled Defaults

The adapters are disabled by default:

- `SEO_INTEL_COLLECTORS_ENABLED=false`
- `SEO_INTEL_WRITE_ENABLED=false`
- `SEO_INTEL_SO360_ENABLED=false`
- `SEO_INTEL_SOGOU_ENABLED=false`
- `SEO_INTEL_SHENMA_ENABLED=false`
- live API flags are hard-coded false by default
- external API calls remain forbidden
- real URL submission remains forbidden
- scheduler and queue activation remain forbidden

## Schemas

This PR adds logical foundation tables only:

- `seo_search_engine_verification_statuses`: account/API/sitemap/URL-submission capability and verification state by engine.
- `seo_domestic_submission_logs`: dry-run-safe future submission records by engine and canonical URL hash.
- `seo_domestic_index_samples`: future manual or SERP sample observations with title/snippet hashes only.

No production migration is run by this PR.

## URL Eligibility Contract

Future eligible URLs must be all of the following:

- controlled-published
- public-runtime-verified
- indexable
- claim-safe
- non-draft
- non-private-flow
- not `take`, `result`, `order`, `share`, `pay`, `checkout`, or `report_private`

Draft, private, noindex, and claim-unsafe URLs must never be submitted.

## Forbidden Behavior

This PR forbids:

- live 360, Sogou, or Shenma API calls
- credentials, tokens, verification file contents, or site verification values in repo
- real URL submissions
- engine-specific page generation
- sitemap or llms behavior changes
- CMS mutation
- scheduler activation
- queue worker creation
- Metabase deployment
- purchase attribution from search channel submission, keyword, query, or index status

## PII and Revenue Boundary

`seo_intel` detail must not store email, raw cookies, raw order numbers, raw attempt IDs, provider event IDs, payment IDs, raw payment payloads, API keys, secrets, or raw verification artifacts.

Purchase truth remains backend orders, payments, and benefits. Domestic search engines provide search feedback only.

## Next Task

Next task: `CHINA-SEARCH-04`, the Chinese crawler log collector foundation.
