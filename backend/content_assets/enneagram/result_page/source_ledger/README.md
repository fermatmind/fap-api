# Enneagram Result Page Source Ledger

Status: `ENNEAGRAM-RESULT-SOURCE-LEDGER-01`

This directory records source authority for the Enneagram result-page asset agent. It is evidence and validator scaffold only. It does not generate candidate payloads, import candidate releases, activate runtime releases, switch production behavior, write CMS data, or provide frontend fallback content.

## Files

- `source_ledger.json`: normalized source-boundary contract consumed by `enneagram:result-page-agent audit`.
- `source_ledger_template_v0_1.json`: schema-like template for future source ledger rows.
- `README.md`: operator-facing boundary summary.

## Required Contract

The ledger must keep:

- `schema_version=fap.enneagram.result_page.source_ledger.v0.1`
- `runtime_use=not_runtime`
- `production_use_allowed=false`
- `cms_write_performed=false`
- `runtime_change_performed=false`
- `frontend_fallback_allowed=false`
- `activation_happened=false`
- candidate baseline SHA `a9fd3eb474ea2ca0130d06ad2b1640305d9160ee1a74e559ad4f60bfc4db56c0`
- runtime registry SHA `ac5bdaab3c761b0d01a56f92679aa58341110d64de0f47a1fa0062b64f76f97f`
- expected payload count `630`
- launch scope `1R-A` through `1R-H`
- out-of-launch scope exactly `1R-I`, `1R-J`

## Source Boundaries

Every future asset claim must trace to one required source id, one allowed source label, a bounded claim category, and an explicit copy policy. Internal asset-stream rows are provenance contracts only; they are not runtime content, not CMS import material, and not permission to copy external or private working paths into public payloads.

The validator harness fails closed when a provided candidate directory has missing required artifacts, mismatched hashes, wrong payload count, source-mapping failures, metadata leakage, forbidden claim families, legacy residuals, or FC144 boundary violations.
