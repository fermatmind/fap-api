# Career Gold Diff Rules

## Purpose
- Validate the structural integrity of future Career batch draft manifests.
- Keep Codex draft artifacts inside controlled authoring boundaries.
- Prevent later batch work from mutating frozen first-wave authority artifacts.

## What Gold Diff Validates
- Required top-level schema keys are present.
- Required occupation-level keys are present.
- Unexpected top-level and occupation-level keys are rejected.
- Forbidden engine-owned keys are absent from batch draft manifests.
- `manifest_version` and `manifest_kind` are present.
- `manifest_kind` must remain `career_batch_draft_template`.
- `scope.first_wave_overlap_allowed` must remain `false`.
- Duplicate `draft_id`, `occupation_uuid`, and `canonical_slug` values are rejected.
- First-wave boundary drift is rejected:
  - a batch draft must not reuse any `canonical_slug` already frozen in `docs/career/first_wave_manifest.json`
- The file remains machine-readable JSON.

## Required Top-Level Keys
- `manifest_version`
- `manifest_kind`
- `generated_from`
- `generated_at`
- `wave_name`
- `batch_id`
- `scope`
- `engine_boundary`
- `occupations`

## Required Occupation-Level Keys
- `draft_id`
- `occupation_uuid`
- `canonical_slug`
- `canonical_title_en`
- `canonical_title_zh`
- `family_uuid`
- `source_refs`
- `alias_candidates`
- `editorial_patch`
- `human_moat_tags`
- `task_prototype_signature`
- `authoring_status`
- `notes`

## Allowed Top-Level Keys
- `manifest_version`
- `manifest_kind`
- `generated_from`
- `generated_at`
- `wave_name`
- `batch_id`
- `scope`
- `engine_boundary`
- `occupations`

## Allowed Occupation-Level Keys
- `draft_id`
- `occupation_uuid`
- `canonical_slug`
- `canonical_title_en`
- `canonical_title_zh`
- `family_uuid`
- `source_refs`
- `alias_candidates`
- `editorial_patch`
- `human_moat_tags`
- `task_prototype_signature`
- `authoring_status`
- `notes`

## Allowed Editorial-Only Fields
- `alias_candidates`
- `editorial_patch`
- `human_moat_tags`
- `task_prototype_signature`
- `notes`

These fields may hold structured draft guidance, but they are still non-authoritative.

## Forbidden Engine-Owned Keys
- `crosswalk_mode`
- `wave_classification`
- `publish_reason_codes`
- `trust_seed`
- `reviewer_seed`
- `index_seed`
- `claim_seed`
- `score_summary`
- `trust_summary`
- `claim_permissions`
- `seo_contract`
- `provenance_meta`

## First-Wave Boundary Protection
- `docs/career/first_wave_manifest.json` is frozen publish truth for the first-wave 10 occupations.
- `docs/career/first_wave_aliases.json` is frozen first-wave alias authority.
- Batch draft manifests must not:
  - reuse first-wave `canonical_slug` values
  - edit or reinterpret first-wave publish seeds
  - treat test fixtures as replacement authority

## What Gold Diff Must NOT Do
- No truth compute.
- No score compute.
- No trust compile.
- No claim compile.
- No auto-fix.
- No file mutation.
- No alias invention beyond the provided draft payload.

## Review Rule
- Passing gold diff only means the draft is structurally acceptable.
- It does not mean the draft is publish-ready.
- Laravel engine outputs remain the only valid authority for truth, trust, claim, and publish decisions.
