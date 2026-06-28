# Big Five Norm Unavailable Revised Content Assets v0.3

## Editorial summary

This package revises the 18 norm-unavailable candidate content assets. The main correction from v0.2 is not length, but lower template repetition and clearer domain-specific logic. The user-facing copy no longer reads like backend rules; each domain explains what remains useful when stable external references are unavailable.

## Coverage

- global: show / soften / hide
- openness: show / soften / hide
- conscientiousness: show / soften / hide
- extraversion: show / soften / hide
- agreeableness: show / soften / hide
- neuroticism: show / soften / hide

## Scope and boundaries

- Candidate content only.
- No final result-page output.
- No frontend copy.
- No CMS / SEO / production / runtime change.
- All candidates remain `runtime_use=staging_only`, `production_use_allowed=false`, `ready_for_pilot=false`, `ready_for_runtime=false`, `ready_for_production=false`.

## What changed from v0.2

1. Removed backend-rule wording.
2. Removed repeated "can read / should wait / observe again" phrasing.
3. Rewrote each domain around its own psychometric meaning.
4. Separated show / soften / hide more clearly.
5. Kept public language free of unsupported external positioning and high-risk claims.

## QA summary

```json
{
  "content_asset_count": 18,
  "forbidden_hit_count": 0,
  "runtime_use_all_staging_only": true,
  "production_use_allowed_true_count": 0,
  "ready_for_pilot_true_count": 0,
  "ready_for_runtime_true_count": 0,
  "ready_for_production_true_count": 0,
  "body_length_min": 180,
  "body_length_max": 200,
  "body_length_outside_180_320": [],
  "duplicate_title_count": 0,
  "duplicate_body_count": 0
}
```

## Codex follow-up checks

- schema validation
- selector contract validation
- candidate_replaces existence check if used later
- state_scope / slot_key mapping check
- body_quality metadata recalculation if importer requires exact metadata
- forbidden-token scan over rendered public text
- result page / PDF / share / history / compare rendered hygiene scan
- human review manifest
- staging import only

## Import verdict

`READY_FOR_CODEX_STAGING_VALIDATION`, not runtime-ready and not production-ready.
