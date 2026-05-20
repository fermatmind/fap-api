# Google Search Console Live Readiness Contract

## Purpose

GSC-LIVE-00 defines Google Search Console readiness for Search Channel live preparation without making live calls or requesting indexing.

This PR does not connect a live GSC connector, submit URLs, request indexing, activate scheduler jobs, run collector writes, edit environment files, deploy services, or change sitemap/llms behavior.

## Authority Boundary

Google Search Console is a feedback and readiness source only. It is not content authority, URL Truth authority, sitemap authority, `llms.txt` authority, purchase truth, or Search Channel submission authority.

GSC must not:

- create URLs
- discover URLs that bypass CMS/backend URL Truth
- override backend canonical URLs
- override CMS publication, indexability, or claim boundary state
- make draft, private, noindex, unsupported, stale-slug, or claim-unsafe URLs eligible

GSC must not create URLs or promote discovered URLs around backend approval.

CMS/backend URL Truth remains the source of truth for canonical URLs, source authority, publication state, indexability state, and claim safety.

## Ownership And Access Requirements

Before any future read-only GSC operation:

- the FermatMind site property must be verified in Google Search Console
- property ownership and operator responsibility must be documented
- access must use approved service account or OAuth credentials from a safe secret channel
- credentials must not be committed, printed, logged, pasted into docs, or stored in generated artifacts
- the connector must run read-only until a separate explicit approval changes that boundary

Missing property ownership, service account, OAuth approval, or operator account readiness is a sidecar blocker for live operation, not a blocker for this docs/test contract.

## Read-Only Data Window

A future GSC readiness check may read Search Console performance or index status only within an approved bounded window. The initial contract window is `last_16_months_max`, with future operational runs expected to use a narrower window such as `last_28_days` or `last_90_days` unless explicitly approved.

Any read output must be aggregated and sanitized. It must not expose raw queries containing emails, tokens, order IDs, attempt IDs, payment IDs, sessions, cookies, or raw IPs.

## URL Inspection Readiness

URL Inspection may be used only as a read-only/status check after explicit approval and safe credentials are available.

URL Inspection must:

- inspect only CMS/backend URL Truth approved canonical URLs
- treat status as feedback, not authority
- never request indexing from this readiness contract
- never create queue records by itself
- never override draft/private/noindex/claim-unsafe exclusions

## Sitemap Authority Check

GSC sitemap feedback may compare submitted sitemap status against backend-authoritative sitemap expectations. It must not change sitemap generation, `llms.txt` generation, CMS publication state, or URL Truth eligibility.

## Stop Conditions

Stop immediately if:

- a live GSC API call is attempted in this PR
- a URL indexing request is attempted
- a URL submission is attempted
- a credential, token, API key, cookie, session, email, order ID, attempt ID, payment ID, or raw IP is printed or committed
- GSC is used as URL Truth authority
- sitemap or `llms.txt` behavior changes
- scheduler or collector writes are enabled

No live GSC API call is allowed in GSC-LIVE-00.

## Next Task

Next task: BAIDU-LIVE-00.
