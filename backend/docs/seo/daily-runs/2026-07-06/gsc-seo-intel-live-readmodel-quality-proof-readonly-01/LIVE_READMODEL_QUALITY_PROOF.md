# GSC / seo_intel Live Readmodel Quality Proof

Date: 2026-07-06
Scope: generated-only proof attempt from existing repository evidence

## Proof Requirement

To pass, future GSC/seo_intel repair selection must prove all of the following from sanitized read-model evidence:

| Gate | Required value |
| --- | --- |
| source engine | `google` |
| data origin | `live_gsc_api` |
| forbidden origins | no `fixture`, `mock`, `static_artifact`, or `unknown` |
| required fields | `canonical_url_hash`, `query_hash`, `clicks`, `impressions` |
| date quality | satisfies GSC finalization lag and freshness window |
| queue source | imported `seo_intel` read-model rows only |
| sensitive fields | no raw query, raw URL, credentials, tokens, cookies, sessions, or raw payloads |

## Current Evidence

| Evidence source | Current result | Pass? |
| --- | --- | --- |
| `backend/docs/seo/generated/gsc-data-quality-gate.v1.json` | `data_origin=fixture`, `data_quality_gate_status=blocked`, `opportunity_queue_eligible=false` | No |
| `backend/docs/seo/generated/gsc-live-readmodel-consumption-contract.v1.json` | Defines future live read-model contract; no live call or import in current evidence | No |
| `backend/docs/seo/daily-runs/2026-07-06/gsc-seo-intel-quality-refresh-readonly-01/` | Prior read-only refresh concluded gate blocked | No |
| `GscDataQualityGate` architecture | Gate logic exists and blocks forbidden origins | Architecture present, data proof absent |
| `seo_gsc_daily` schema | Supports hashed URL/query metrics and idempotency key | Schema present, passable rows absent |

## Decision

The quality proof fails by design because no allowed live `seo_intel` read-model row set is available in the current repository evidence.

Downstream effects:

- `CTR-REPAIR-LOOP-ELIGIBILITY-READONLY-01` should remain blocked unless separate passable live evidence appears before that card.
- `CTR-TDK-REPAIR-DRYRUN-QUEUE-01` should remain blocked.
- Money-intent TDK/CTR repair selection must not use fixture/static/screenshot/UI evidence.

## Non-Actions

This PR did not:

- open Google Search Console;
- call GSC APIs;
- read or handle credentials;
- import or backfill `seo_gsc_daily`;
- write any CMS, Search Channel, sitemap, or runtime state;
- infer purchase truth from GSC/GA.
