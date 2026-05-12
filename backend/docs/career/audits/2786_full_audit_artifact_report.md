# AUDIT-13 Career 2786 Full Audit Artifact Report

AUDIT-13 adds the final read-only artifact builder for the Career 2786 canonical eligibility audit train.

## Purpose

- Summarize canonical eligibility totals.
- Include by-reason and by-layer counts.
- Carry sidecars.
- Include readiness, manifest train, and batch live acceptance sections when supplied.
- Produce a stable JSON artifact for the completed audit stack.

## Non-Goals

- No rollout apply.
- No 2786 publication.
- No DB mutation.
- No deployment.
- No automatic expansion start.

## Output Contract

The artifact contains:

- `artifact_kind=career_2786_canonical_eligibility_audit_report`
- `artifact_version`
- `status`
- `total_expected`
- `audited_count`
- `eligible_count`
- `blocked_count`
- `ready_for_expansion`
- `by_reason`
- `by_layer`
- `sections`
- `sidecars`
- `read_only=true`
- `writes_database=false`

`ready_for_expansion` is true only when the expected count, audited count, blocked count, and supplied section statuses all pass. AUDIT-13 does not start expansion.
