# IndexNow Live Readiness Contract

## Purpose

INDEXNOW-LIVE-00 defines IndexNow readiness for Search Channel live preparation without submitting URLs.

This PR does not call live IndexNow endpoints, submit URLs, expose keys, activate a live connector, enable scheduler jobs, run collector writes, edit environment files, deploy services, or change sitemap/llms behavior.

## Authority Boundary

IndexNow is a URL update signal channel. It is not content authority, URL Truth authority, sitemap authority, `llms.txt` authority, or indexing/ranking proof.

IndexNow must not bypass CMS/backend URL Truth or Search Channel Queue approval. It must not submit draft, private, noindex, claim-unsafe, stale-slug, missing-canonical, unsupported, or non-backend-authoritative URLs.

## Key Ownership And Verification

Before any future IndexNow submission:

- IndexNow key ownership must be documented
- key rotation and revocation ownership must be documented
- the key must be stored only in a safe secret channel
- public key-file hosting must be explicitly approved
- key file content and key URL must be verified before submission
- host verification must pass for every allowed host

Unavailable key ownership, key-file hosting, key URL verification, or host verification is a sidecar blocker for live operation, not a blocker for this docs/test contract.

## Allowed Hosts

Allowed hosts must be configured explicitly before future live use. The initial allowed host policy is FermatMind-owned canonical public hosts only.

The adapter must reject:

- unknown hosts
- staging hosts unless explicitly approved for testing
- localhost and private network hosts
- third-party hosts
- URLs whose canonical host differs from URL Truth

## Submission Contract

A future IndexNow adapter may submit URLs only after:

- explicit human approval for live submission
- key verification has passed
- allowed host verification has passed
- Search Channel Queue marks the URL approved for IndexNow
- backend CMS URL Truth confirms canonical URL, published state, indexable state, supported page type, and claim safety

Bulk URL submission must use bounded batches, sanitized logs, and queue records. It must not store raw keys, raw payloads, cookies, raw IPs, emails, order IDs, attempt IDs, payment IDs, or sessions.

## Stop Conditions

Stop immediately if:

- a live IndexNow endpoint is called in this PR
- a URL is submitted in this PR
- an IndexNow key is printed, committed, logged, or stored in generated artifacts
- key-file hosting is changed in this PR
- a draft/private/noindex/claim-unsafe URL becomes eligible
- IndexNow bypasses URL Truth or Search Channel Queue
- scheduler or collector writes are enabled
- sitemap or `llms.txt` behavior changes

No live IndexNow submission is allowed in INDEXNOW-LIVE-00.

## Next Task

Next task: SEARCH-CHANNEL-LIVE-01-PREFLIGHT.
