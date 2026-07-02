# RIASEC-FULL-CONTENT-PACK-06-PREFLIGHT

## Scope

This preflight validates the V7.3 `occupation_examples_boundary_v1.zh-CN` asset before any backend runtime import. It does not wire the asset into `RiasecActivityExplorerService`.

`RIASEC-CONTENT-OCC-01` keeps that runtime boundary unchanged and repairs the content asset itself: each occupation-like name is framed as a work-scene observation entry, not as a recommendation, rank, fit judgment, qualification signal, or career outcome claim.

Input asset:

- `/Users/rainie/Desktop/riasec_full_content_assets_v7_3_final_preflight_candidate.zip`
- `18_occupation_examples_boundary_v1.zh-CN/occupation_examples_boundary_v1.zh-CN.jsonl`

Preflight fixture:

- `backend/tests/Fixtures/Riasec/occupation_examples_boundary_v1.zh-CN.jsonl`

## Findings

- Record count: 360 JSONL records.
- Required fields: present for the current V7.3 preflight contract.
- Dimension coverage: R 80, I 80, A 80, S 40, E 40, C 40 records.
- Occupation/work-scene labels: 360 unique labels.
- `why_it_may_appear`: 360 unique contextual explanations.
- `common_tasks`: 360 unique task sets.
- `task_examples`: 360 unique low-risk observation task sets.
- `reality_check`: 360 unique next-step checks.
- `source_status`: `content_example_not_registry_match` for all records.
- `not_a_recommendation`: true for all records.
- `fit_score_allowed`: false for all records.
- `source_url_allowed`: false for all records.
- Education, skill, and qualification boundaries: present for all records.
- User-facing forbidden claims: 0 in the validated visible fields.
- User-facing technical key exposure: 0 in the validated visible fields.
- O*NET / SOC / source URL fields: absent.

## OCC-01 repair notes

- Repeated dimension-level templates were replaced with occupation-specific observation language.
- Each row now tells the reader to inspect concrete activities, conditions, responsibilities, and boundaries before continuing.
- The copy blocks direct choice, ability, income, opportunity, long-term outcome, ranking, fit-score, and qualification conclusions.
- Education, skill, and qualification boundaries remain separate from interest evidence and must be checked outside this asset.
- The fixture and source asset are intentionally identical so preflight tests validate the committed content rather than a stale sample.

## Activity/task mapping gap

PACK-05 imported concrete activity/task records keyed by specific activity keys. The V7.3 occupation examples use activity-family keys such as `r_activity_family`, plus `primary_activity_dimension`.

PACK-06 import must therefore connect occupation examples through PACK-05 activity/task mapping by `primary_activity_dimension` or another explicit backend mapping layer. It must not introduce a direct Holland Code to occupation example route.

## Boundary rules for import

- Occupation examples are work-scene examples, not matches.
- Occupation examples must be reached through activity/task examples.
- Occupation names are not recommendations, rankings, fit scores, or success predictions.
- Education, skill, and qualification copy must remain boundary copy, not ability proof.
- No source URL, O*NET, SOC, career registry row, or external registry source may be invented.
- Missing or invalid content must fail closed to an unavailable/omitted occupation example module.
- Backend remains the content authority.
- Frontend must not add local interpretation fallback copy.

## Decision: CONDITIONAL GO

PACK-06 can proceed after this preflight merges, provided the import PR:

- keeps occupation examples connected through PACK-05 activity/task records,
- preserves examples-only boundaries and public-safe source status,
- avoids direct Code -> occupation routing,
- validates the file-backed asset at load time,
- keeps current result/report/share/PDF/history safety contracts green,
- avoids scorer, question pack, Holland Code generation, feedback, analytics, career registry, production data, source URL, O*NET, and SOC changes.
