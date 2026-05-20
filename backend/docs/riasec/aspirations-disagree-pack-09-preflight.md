# RIASEC-FULL-CONTENT-PACK-09-PREFLIGHT

## Decision: CONDITIONAL GO

V7.3 `aspirations_calibration_v1.zh-CN.jsonl` and `disagree_path_v1.zh-CN.jsonl` are acceptable to continue into PACK-09 import preflight output, with backend normalization requirements before runtime import.

The assets parse cleanly:

- aspirations calibration: 70 records
- disagree path: 45 records

## Boundaries

PACK-09 content is exploration and reading-boundary content only.

- Aspirations are user wishes or exploration directions, not measured results.
- Disagree path is reading feedback, not score correction.
- Imported content must not mutate measured_holland_code, RIASEC scores, report snapshots, share, PDF, or history.
- Raw feedback must not be exposed in public share/PDF/history surfaces.
- Missing or invalid content must fail closed with `frontend_fallback_allowed=false`.

## Mapping Gap

Current backend runtime already exposes inline/staging aspirations and disagree slots through `RiasecDeepCopySlotRegistry`, and `RiasecPublicProjectionService` consumes selected slots through `deep_content_slots_v1`.

V7.3 assets are richer record sets:

- aspirations records are keyed by user aspiration domain and intent state
- disagree records are keyed by user context and disagreement state

PACK-09 import must normalize these records into backend-owned slot names or a deterministic selector. It must preserve the existing public shape where practical and must not introduce frontend inference.

## Quality / Near-Tie Dependency

PACK-09 depends on PACK-08 because low-quality, near-tie, broad-profile, and confidence states determine when aspirations/disagree copy is useful, hidden, or collapsed.

The V7.3 disagree asset includes state coverage for:

- normal disagreement
- code order disagreement
- dimension score disagreement
- activity chain disagreement
- occupation example disagreement
- near_tie disagreement
- broad_profile disagreement
- low_quality disagreement
- 60Q / 140Q tension
- aspiration conflict
- role/environment/task reality conflicts

## Public-Surface Exclusion Gap

The disagree asset includes explicit `snapshot_mutation_allowed=false` and `share_pdf_exposure_allowed=false`.

The aspirations asset includes explicit score and measured Holland Code mutation guards, but does not carry every public-surface flag as first-class fields. PACK-09 import must add backend normalized false guards for report snapshot, share/PDF/history measured payload mutation, and raw feedback exposure before emitting any runtime slot.

## Forbidden Claim Scan

Preflight visible-field scan treats forbidden terms in `forbidden_claims`, boundaries, or negative constraints as allowed boundary hits. User-facing positive claim hits must remain zero.

Current preflight result:

- `forbidden_user_claim=0`
- `visible_technical_key_exposure=0`

## No-Go Carry Forward

PACK-09 import must stop if it requires any of the following:

- scorer math, question pack, or Holland Code generation changes
- report snapshot/share/PDF/history measured payload mutation
- feedback action lab or next exploration node import
- frontend fallback copy
- career match, job fit, occupation ranking, success prediction, or hiring suitability claims
- a claim that 140Q is more accurate
- raw feedback public exposure
