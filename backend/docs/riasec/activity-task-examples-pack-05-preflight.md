# RIASEC-FULL-CONTENT-PACK-05-PREFLIGHT

## Scope

This preflight validates the V7.3 `activity_task_examples_v1.zh-CN` asset before any backend runtime import. It does not wire the asset into `RiasecActivityExplorerService` and does not import occupation examples.

`RIASEC-CONTENT-ACTIVITY-01` keeps that runtime boundary unchanged and repairs the content asset itself: each row now reads as a low-risk activity observation, not a recommendation, identity label, ability claim, or occupation path.

Input asset:

- `/Users/rainie/Desktop/riasec_full_content_assets_v7_3_final_preflight_candidate.zip`
- `09_activity_task_examples_v1.zh-CN/activity_task_examples_v1.zh-CN.jsonl`

Preflight fixture:

- `backend/tests/Fixtures/Riasec/activity_task_examples_v1.zh-CN.jsonl`

## Findings

- Record count: 360 JSONL records.
- Required fields: present for the current V7.3 preflight contract.
- Dimensions: all six RIASEC dimensions are represented.
- Dimension coverage: 60 records each for R, I, A, S, E, and C.
- User-facing activity labels: 360 unique labels.
- User-facing task examples: 360 unique task-example sets.
- Low-risk validation copy: 360 unique validation statements.
- User-facing forbidden claims: 0 in the validated visible fields.
- User-facing technical key exposure: 0 in the validated visible fields.
- User-facing template traces such as `观察版本`, `探索变体`, `找一个与“...接近的小场景` are blocked by preflight.
- `frontend_fallback_allowed`: false for all records.
- `source_status`: 180 records are `content_example_not_registry_match`; 180 records are `commercial_expansion_candidate_not_runtime_imported`.
- `not_a_recommendation`: true for all records.
- Occupation examples: not present in the PACK-05 target asset.

## ACTIVITY-01 repair notes

- Labels were rewritten to remove internal production traces while preserving deterministic activity keys.
- Repeated dimension-level templates were replaced with activity-specific small experiments, observation tasks, and reflection prompts.
- Each validation statement keeps a 15-minute first step, a 30-minute optional follow-up, and an explicit boundary: continued exploration is only interest evidence, not ability, identity, or career conclusion.
- Commercial expansion rows remain marked as not runtime-imported; their visible copy is still safe if a later scoped PR chooses to expose them.
- The fixture and source asset are intentionally identical so preflight tests validate the committed content rather than a stale sample.

## ActivityExplorer mapping gap

Current runtime is still `riasec.activity_explorer.v0.1` with inline/static activity packs in `RiasecActivityExplorerService`. It does not yet load the 360-record file-backed activity/task asset.

PACK-05 import must therefore add a backend-authoritative loader and deterministic mapping from Holland Code / top3 chain into activity/task records while preserving fail-closed behavior for missing or invalid records.

## Boundary rules for import

- Activity/task examples are low-risk exploration prompts.
- They are not career recommendations.
- They are not job-fit claims.
- They are not occupation matching.
- They are not ability proof or skill proof.
- Missing or invalid content must fail closed to an unavailable/omitted module.
- Backend remains the content authority.
- Frontend must not add local interpretation fallback copy.
- No occupation examples are imported in PACK-05.

## Decision: CONDITIONAL GO

PACK-05 can proceed after this preflight merges, provided the import PR:

- keeps occupation examples out of scope,
- preserves no-frontend-fallback behavior,
- validates the file-backed asset at load time,
- normalizes or explicitly handles `commercial_expansion_candidate_not_runtime_imported` rows before runtime emission,
- keeps current result/report/share/PDF/history safety contracts green,
- avoids scorer, question pack, Holland Code generation, feedback, analytics, career registry, and production data changes.
