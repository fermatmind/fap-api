# Big Five Domain Bands Revised v0.3 Normalized Candidates

Task: `BIG5-DOMAIN-BANDS-CANDIDATE-NORMALIZE-01`

This package normalizes the GPT-provided second-block domain band ZIP into backend-recognized candidate JSONL. It does not import candidates into staging, does not enable runtime, does not touch production, does not write fap-web copy, and does not touch CMS/SEO/search.

## Contents

- `selector_asset_candidates.jsonl`: 25 selector candidates reused from the governed domain-band candidate shape with refreshed public title/summary.
- `content_asset_candidates.jsonl`: 25 revised content candidates after risk-wording repair and body quality recalculation.
- `normalization_manifest.json`: source package and normalization metadata.
- `candidate_generation_summary.json`: coverage, repair, leak, and rendered hygiene summary.
- `review_manifest.json`: human review manifest for staging validation only.
- `repair_log.json`: risk-wording repair evidence.
- `source_qa_scan.json` / `source_review.md`: source package evidence copied from the ZIP for traceability.

## Gates

- `runtime_use`: `staging_only`
- `production_use_allowed`: `false`
- `ready_for_runtime`: `false`
- `ready_for_production`: `false`
- Staging import: deferred to a separate PR.
