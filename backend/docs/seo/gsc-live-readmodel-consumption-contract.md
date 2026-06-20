# GSC Live Read Model Consumption Contract

Task: `SEO-GSC-LIVE-READMODEL-CONSUMPTION-CONTRACT-01`

## Purpose

This contract defines how sanitized live Google Search Console sidecar artifacts may become backend `seo_intel` read model input in a future PR.

This PR does not import sidecar artifacts, write `seo_gsc_daily`, add migrations, add scheduler wiring, enqueue opportunities, mutate CMS content, enqueue or submit Search Channel records, request indexing, or call Google APIs.

## Allowed Future Consumption Boundary

A live GSC sidecar artifact may be considered for future read model import only when all of these are true:

- `data_origin=live_gsc_api`
- `data_quality_gate=pass`
- source engine is `google`
- artifact rows contain sanitized fields only
- metric rows use hashed identifiers for canonical URL and query matching
- report dates satisfy the GSC finalization lag and freshness windows defined by `SEO-GSC-DATA-QUALITY-01`
- the importer target is the backend `seo_intel` read model, not CMS, Search Channel, indexing, or the opportunity queue directly

## Forbidden Artifact Fields

Sidecar artifacts must not expose or forward these fields into a contract-visible read model package:

- raw query
- raw URL
- credential path
- token
- access token
- API key
- client email
- service account JSON
- cookie
- session
- raw payload

## Read Model Boundary

`seo_gsc_daily` remains the future backend read model import target for live GSC rows.

This PR does not add an importer command and does not write `seo_gsc_daily`. A later importer PR must be dry-run first, must preserve sanitized-only fields, must pass the GSC data quality gate, and must remain separate from opportunity queue execution.

## Opportunity Queue Boundary

The opportunity queue must never consume sidecar artifacts directly. It may only read already-imported `seo_intel` read model rows that passed the GSC data quality gate.

Sidecar artifacts are evidence and import input candidates only. They are not queue items, CMS draft packages, search submissions, indexing requests, or execution approvals.

## Negative Guarantees

- no live GSC API call in this PR
- no credential read, print, storage, or mutation
- no `seo_gsc_daily` write
- no migration
- no scheduler activation
- no queue worker activation
- no opportunity queue enqueue
- no CMS draft or CMS write
- no Search Channel enqueue, approval, or submission
- no GSC URL Inspection indexing request
- no sitemap submission
- no production environment change

## Next Allowed Work

After this contract is merged, the next safe implementation step is a dry-run-only read model importer design or preflight PR. That future PR must still avoid scheduler activation, opportunity queue execution, CMS mutation, and search/indexing submission unless separately approved.
