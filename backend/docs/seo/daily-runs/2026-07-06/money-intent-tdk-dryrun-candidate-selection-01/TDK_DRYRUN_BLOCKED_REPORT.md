# TDK Dry-Run Candidate Selection Blocked Report

## Blocking Evidence

Current GSC / seo_intel evidence remains blocked for SEO repair decisions:

- `GSC-SEO-INTEL-QUALITY-REFRESH-READONLY-01` final decision: `gsc_seo_intel_quality_refresh_completed_gate_blocked`.
- Current foundation status is fixture/static or missing live passable read-model proof.
- `GscDataQualityGate` requires `source_engine=google` and `data_origin=live_gsc_api`.
- Opportunity queue must consume only quality-gated read-model rows.
- Current evidence does not prove opportunity queue eligibility.

## Consequence

This PR cannot safely choose TDK dry-run candidates for money-intent pages. Candidate selection would risk using stale/noisy/non-live data and would violate the current GSC quality gate.

## Allowed State

| Action | Status |
| --- | --- |
| Select TDK candidate pages | blocked |
| Draft replacement title/meta/H1 | blocked |
| Write CMS or registry fields | blocked |
| Submit Search URLs | blocked |
| Use GSC/GA as purchase truth | blocked |
| Record blocker and continue train | allowed |

## Required Unblocker

Before a future TDK candidate-selection PR:

1. Prove sanitized live Google read-model rows exist.
2. Prove `data_origin=live_gsc_api`.
3. Prove freshness/finalization gate pass.
4. Prove required metrics exist without exposing raw query or raw URL payloads.
5. Prove opportunity queue eligibility.

## Boundary

No TDK text, FAQ text, internal-link text, metadata, CMS row, schema, sitemap, llms, Search Channel, runtime, fap-web, or DB changes are included.
