# Baidu Resource Platform Live Readiness Contract

## Purpose

BAIDU-LIVE-00 defines Baidu Resource Platform readiness for Search Channel live preparation without pushing URLs.

This PR does not call live Baidu APIs, push URLs, expose tokens, activate a live connector, enable scheduler jobs, run collector writes, edit environment files, deploy services, or change sitemap/llms behavior.

## Authority Boundary

Baidu is a distribution and feedback channel. It is not content authority, URL Truth authority, sitemap authority, `llms.txt` authority, or a separate domestic page truth layer.

Baidu must use the same canonical CMS/backend URL Truth as other Search Channel adapters. It must not create a Baidu-only page set, separate slugs, alternate metadata, domestic-only editorial content, or frontend fallback content.

## Ownership Requirements

Before any future Baidu live operation:

- the FermatMind site must be verified in Baidu Resource Platform
- the Baidu Resource Platform account owner must be documented
- operator responsibility must be documented
- push endpoint and quota policy must be approved
- push token storage must use an approved safe secret channel
- token rotation and revocation ownership must be documented

Unavailable site ownership, operator readiness, token readiness, or endpoint approval is a sidecar blocker for live operation, not a blocker for this docs/test contract.

## Token And Endpoint Handling

Baidu push tokens and endpoints must never be committed, printed, logged, pasted into docs, stored in generated artifacts, or exposed in public error output.

Only masked endpoint descriptions and credential readiness states may appear in reports. Raw tokens, cookies, sessions, emails, order IDs, attempt IDs, payment IDs, raw IPs, and raw payloads are forbidden.

## Sitemap And Push Eligibility

Baidu sitemap and push readiness must start from Search Channel Queue approved canonical URLs. Eligibility requires:

- backend/CMS source authority
- canonical URL present
- published state
- indexable state
- supported URL Truth page type
- claim boundary safe
- Chinese claim boundary linter pass where Chinese copy or domestic search copy is involved
- no private-flow path
- no query-string-only candidate
- no stale slug

Draft, private, noindex, claim-unsafe, unsupported, stale-slug, missing-canonical, or non-backend-authoritative URLs must never be pushed.

## Search Channel Queue Requirement

Baidu push must be driven by Search Channel Queue records after a separate explicit live approval. A Baidu adapter must not bypass URL Truth, create URLs, or submit directly from frontend sitemap, static `llms.txt`, local content copies, production crawler logs, live Baidu responses, Node2 local DB, or raw business DB tables.

## Stop Conditions

Stop immediately if:

- a Baidu URL push is attempted in this PR
- a live Baidu API call is attempted in this PR
- a Baidu token or endpoint secret is exposed
- a draft/private/noindex/claim-unsafe URL becomes eligible
- a Baidu-only page set or alternate Baidu truth layer is introduced
- frontend fallback becomes search truth
- scheduler or collector writes are enabled
- sitemap or `llms.txt` behavior changes

No Baidu push is allowed in BAIDU-LIVE-00.

## Next Task

Next task: INDEXNOW-LIVE-00.
