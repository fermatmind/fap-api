# P5 GSC / CTR Blocker Detail

Status carried from acceptance scan: `BLOCKED`

## Exact Blocker

P5 is blocked because current GSC/seo_intel evidence is not passable live read-model data.

## Current Gate State

| Gate | Current evidence | Status |
| --- | --- | --- |
| Data origin | Current foundation status is fixture/static from #2760 evidence. | blocked |
| Required origin | `live_gsc_api` required by `GscDataQualityGate`. | missing |
| Forbidden origins | fixture/mock/static/unknown forbidden. | current evidence falls here |
| Opportunity queue | `opportunity_queue_eligible=false` from current evidence. | not eligible |
| Repair authority | No title/meta/H1/FAQ/CTR repair should use current GSC evidence. | held |

## Needed Artifact Or Read Model

One of these must exist before CTR repair resumes:

1. A sanitized live sidecar/read-model proof showing `source_engine=google`, `data_origin=live_gsc_api`, finalization/freshness pass, and required metric fields; or
2. A separately authorized live GSC read/import dry-run that produces eligible `seo_intel` rows without raw query/URL/private data exposure.

## Required Repair Before CTR Loop

- Prove non-fixture GSC read-model data.
- Prove `GscDataQualityGate=pass`.
- Prove opportunity queue eligibility.
- Select candidate pages from gated rows only.
- Keep CMS/Search execution separate and explicitly authorized.

## P5 Blocker Field

`gsc_data_quality_gate_blocked_fixture_or_missing_live_readmodel`
