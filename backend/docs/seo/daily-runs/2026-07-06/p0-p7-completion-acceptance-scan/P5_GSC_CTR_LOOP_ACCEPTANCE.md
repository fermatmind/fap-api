# P5 GSC / CTR Repair Loop Acceptance

Status: `BLOCKED`

## Acceptance Result

P5 is blocked. Current GSC/seo_intel evidence is not eligible for CTR repair, opportunity scoring, title/meta/H1/FAQ repair, Search Channel enqueue, or CMS action.

## Evidence Used

- `backend/docs/seo/daily-runs/2026-07-06/gsc-seo-intel-quality-refresh-readonly-01/`
- `backend/docs/seo/gsc-data-quality-gate.md`
- `backend/docs/seo/gsc-live-readmodel-consumption-contract.md`
- `backend/docs/seo/opportunity-queue-readonly.md`

## Blocking Evidence

- Current foundation status is fixture/static evidence, not `live_gsc_api`.
- `opportunity_queue_eligible=false` from current generated evidence.
- The quality gate requires source engine `google`, data origin `live_gsc_api`, non-fixture row source, freshness/finalization checks, and required metric fields.
- Opportunity queue must consume imported read-model rows after gate pass, not raw sidecars or manually copied screenshots.

## Missing Before COMPLETE

- Live/non-fixture GSC read-model evidence.
- Passing `GscDataQualityGate`.
- Eligible opportunity queue candidates for high-impression, rank 5-15, low CTR pages.
- At least one repair loop with dry-run, write or no-op closeout, and D7/D28 observation plan.

## Acceptance Decision

`BLOCKED`: no CTR repair loop should run until the data-quality gate passes.
