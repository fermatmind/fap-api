# AUDIT-11 Career Canonical Expansion Manifest Train Generator

AUDIT-11 adds a read-only manifest train generator for the Career canonical expansion path. It consumes the AUDIT-10 readiness result and emits staged manifest payloads for the 80/300/800/2786 train.

## Purpose

- Generate staged manifest payloads from already eligible readiness slugs.
- Keep rollback groups as explicit slug lists.
- Attach readiness gates to each stage.
- Preserve sidecars for train decisions.
- Keep publishing and mutation disabled in the generated train result.

## Non-Goals

- No rollout apply.
- No production mutation.
- No publication.
- No backfill.
- No deployment.
- No command integration in AUDIT-11.
- No 2786 readiness claim.

## Input Contract

The generator accepts a `CareerCanonical80CohortReadinessResult`. By default it creates stages for:

- 80
- 300
- 800
- 2786

Tests use synthetic readiness results, so no real 2786 planner artifact or production state is required.

## Output Contract

The result serializes as:

```json
{
  "status": "pass|blocked",
  "train_kind": "career_canonical_expansion_manifest_train",
  "train_version": "career.canonical_expansion_manifest_train.v1",
  "readiness_status": "pass|blocked",
  "publishing_allowed": false,
  "mutation_allowed": false,
  "stage_targets": [80, 300, 800, 2786],
  "ready_slug_count": 0,
  "by_reason": {},
  "batches": [],
  "issues": [],
  "sidecars": []
}
```

Each batch contains a `manifest` object with:

- `batch_id`
- `batch_size`
- `slugs`
- `locales`
- `projection_state=published_candidate`
- `release_gate_required=true`
- `surface_equality_required=true`
- `rollback_group`
- `rollout_state`
- candidate route/release-gate semantics

`rollback_group` must match the slug list. It must never be a batch id.

## Issue Reasons

- `readiness_missing`: no AUDIT-10 readiness result was supplied.
- `readiness_not_pass`: readiness status is not pass or rollout is not allowed.
- `insufficient_ready_slugs`: a stage target has fewer ready slugs than required.
- `duplicate_ready_slug`: the readiness slug list contains duplicates.
- `stage_size_invalid`: reserved for invalid stage size handling.
- `sidecar_blocks_train`: a readiness sidecar cannot continue the train.

## AUDIT-12 Consumption

AUDIT-12 should consume generated manifest payloads in read-only validation mode. It must not apply rollout or publish rows.
