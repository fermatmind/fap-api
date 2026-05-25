# RESULT-EN-PARITY-06 Big Five ResultPageV2 EN Asset Catalog Parity

Decision output for this PR: `result_en_parity_06_bigfive_v2_en_assets_ready_for_human_review_batch`

This PR adds a non-runtime English draft catalog for Big Five ResultPageV2 assets and records the generated parity inventory. It does not change scoring, selector logic, runtime release gates, CMS, production data, deployment, Search Channel submission, or fap-web.

## Scope

Covered ResultPageV2 asset groups:

- route matrix
- coupling assets
- scenario action assets
- facet assets
- canonical profiles
- core body
- selector-ready assets

Generated artifact:

- `backend/docs/seo/generated/result-en-parity-06-bigfive-v2-en-assets.v1.json`

Draft package:

- `backend/content_packs/BIG5_OCEAN/v2/drafts/en_parity/result_page_v2_en_asset_catalog_draft.v1.json`

## Runtime Boundary

The English draft package is explicitly:

- `runtime_use = draft_review_only`
- `ready_for_runtime = false`
- `ready_for_production = false`
- `production_use_allowed = false`

The current zh-CN runtime registry remains under:

- `backend/content_packs/BIG5_OCEAN/v2/registry`

This PR does not wire the draft package into runtime selection.

## Fail-Closed Rule

English Big Five V2 output must not silently fall back to zh-CN interpretation copy. Until reviewed English assets are promoted through a future release package, missing or unreviewed English modules must omit the unavailable module or render an explicit unavailable state.

## Draft Coverage

The draft catalog accounts for:

- 5 OCEAN trait labels.
- 30 facet label seeds.
- 8 ResultPageV2 section headlines.
- 4 scenario labels.
- no-zh-fallback selector policy.

It intentionally defers full English prose for route rows, synergies, modifiers, scenario actions, facet explanations, profile signatures, core body modules, and selector fixtures.

## Claim Boundary

Big Five remains a trait-vector, workstyle, and behavioral explanation system.

Allowed language:

- trait tendency
- workstyle pattern
- behavioral explanation
- contextual self-reflection
- snapshot-based support

Forbidden language:

- precise career matching
- hiring fit
- career success prediction
- salary prediction
- turnover prediction
- clinical diagnosis
- treatment advice

## Deferred Items

The next Big Five content batch should convert one draft group at a time into a reviewed English import package with checksums, selector fixtures, and release review. Do not flip this draft package to runtime use.

Repository rule impact: Big Five ResultPageV2 interpretation assets remain backend content-pack authoritative. This PR adds a draft asset catalog only and does not transfer authority to frontend code or runtime fallback.
