# RIASEC Deep Copy Slot Schema Contract

Status: schema contract only
Schema: `riasec.deep_copy_slot_schema.v1`

This contract defines the backend-authoritative shape for future RIASEC V11 deep content slots. It does not import final editorial copy, does not render UI, and does not change scoring.

## Authority Rules

- `scale_code` must be `RIASEC`.
- Runtime copy must come from backend/CMS registry slots after validation.
- Missing content must fail closed with `omit_module` or `reject_payload`.
- Frontend fallback copy is forbidden.
- Slots are allowed to carry schema fixture text only in tests.

## Slot Groups

- `method_assets`
- `dimension_deep_copy`
- `pair_blend_copy`
- `quality_copy`
- `module_visibility_copy`
- `140q_layer_copy`
- `structural_difference_copy`
- `aspirations_copy`
- `feedback_response_copy`

## Required Metadata

Every slot payload must include:

- `slot_key`
- `slot_group`
- `scale_code`
- `locale`
- `content_version`
- `applicable_form_codes`
- `applicable_profile_shapes`
- `applicable_quality_states`
- `applicable_codes` or `applicable_dimensions`
- `forbidden_claims`
- `required_boundaries`
- `evidence_level`
- `source_status`
- `review_status`
- `fallback_behavior`

Slots tied to interpretation routing must include `interpretation_rule_version`. Slots tied to quality routing must include `quality_rule_version`. Module-visibility boundary slots must include `module_visibility_policy_id`.

## Required Boundaries

- `interest_evidence_only`
- `not_career_recommendation`
- `not_job_fit`
- `not_success_prediction`
- `not_ability_or_skill_measure`
- `no_60q_140q_raw_delta`
- `140q_contextual_not_more_accurate`
- `feedback_does_not_mutate_measured_result`
- `missing_content_fails_closed`
- `frontend_fallback_forbidden`

## Forbidden Claims

Validators must reject content or fields that introduce:

- career matching or occupation matching
- job fit, fit scores, rankings, or hiring suitability
- career success or success probability
- ability or skill inference
- 140Q as a more accurate result
- 60Q / 140Q raw score delta
- unsupported norm, percentile, z-score, or t-score claims
- invented source URL, O*NET, or SOC source rows
- AI-generated formal report runtime

## Future PR Usage

`RIASEC-DEEP-COPY-02` should add the deterministic registry loader/resolver on top of this schema. Later PRs can wire approved slots into projection/composer output only after the resolver validates slot metadata and fails closed for missing content.
