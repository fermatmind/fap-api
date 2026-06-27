# Big Five Method / Boundary Candidate Normalize v0.5

This committed agent-run package normalizes `big5_method_boundary_candidates_v0_5.zip` into Big Five Result Page V2 agent candidate files.

Scope guarantees:
- runtime_use: staging_only
- production_use_allowed: false
- ready_for_pilot/runtime/production: false
- no fap-web copy
- no final `big5_result_page_v2` payload
- no production import, rollout, release snapshot, CMS, SEO, or search mutation

Files:
- `selector_asset_candidates.jsonl`
- `content_asset_candidates.jsonl`
- `review_manifest.json`
- `normalization_manifest.json`

This package is normalization-only. Staging import is intentionally deferred to a separate PR.
