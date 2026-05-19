# RIASEC-FULL-CONTENT-PACK-06-PREFLIGHT

## Scope

This preflight validates the V7.3 `occupation_examples_boundary_v1.zh-CN` asset before any backend runtime import. It does not wire the asset into `RiasecActivityExplorerService`.

Input asset:

- `/Users/rainie/Desktop/riasec_full_content_assets_v7_3_final_preflight_candidate.zip`
- `18_occupation_examples_boundary_v1.zh-CN/occupation_examples_boundary_v1.zh-CN.jsonl`

Preflight fixture:

- `backend/tests/Fixtures/Riasec/occupation_examples_boundary_v1.zh-CN.jsonl`

## Findings

- Record count: 360 JSONL records.
- Required fields: present for the current V7.3 preflight contract.
- `source_status`: `content_example_not_registry_match` for all records.
- `not_a_recommendation`: true for all records.
- `fit_score_allowed`: false for all records.
- `source_url_allowed`: false for all records.
- Education, skill, and qualification boundaries: present for all records.
- User-facing forbidden claims: 0 in the validated visible fields.
- User-facing technical key exposure: 0 in the validated visible fields.
- O*NET / SOC / source URL fields: absent.

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
