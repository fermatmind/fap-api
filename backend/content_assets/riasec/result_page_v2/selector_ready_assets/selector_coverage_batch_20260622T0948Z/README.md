# RIASEC Result Page V2 Selector Coverage Batch Candidate

- Run id: `selector_coverage_batch_20260622T0948Z`
- Task: `RIASEC-RESULT-SELECTOR-COVERAGE-BATCH-01`
- Schema version: `fap.riasec.result_page_v2.selector_asset.v0.1`
- Asset count: `5`
- Runtime use: `staging_only`
- Production use allowed: `false`
- Ready for runtime: `false`
- Ready for production: `false`

This directory mirrors the final dry-run assets for staging review only. It is not imported into CMS, not wired into runtime selectors, not used as a frontend fallback, and not eligible for pilot or production gates.

The candidate covers the narrow route groups required by the selector coverage batch: `clear_primary`, `near_tie`, `top3`, `low_quality`, and `norm_unavailable`. It preserves the raw draft, repaired draft, final asset, validation report, and safety report in the matching `agent_runs` directory.
