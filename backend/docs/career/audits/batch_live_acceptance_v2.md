# AUDIT-12 Career Batch Live Acceptance V2

AUDIT-12 adds a read-only batch live acceptance v2 auditor for arbitrary canonical batches. It consumes synthetic or artifact-provided projection, truth, release-gate, and surface rows.

## Purpose

- Validate arbitrary batch id, slug, and locale sets.
- Check projection rows, truth rows, release gate pass counts, and surface equality.
- Support optional live HTML context as an unverified state when verifier artifacts are missing.
- Emit scalable row-level failures and stable JSON.

## Non-Goals

- No rollout apply.
- No DB mutation.
- No production fetch by default.
- No deployment.
- No 2786 readiness claim.

## Output Contract

The result includes:

- `status`
- `accepted`
- `batch_id`
- `expected_rows`
- projection/truth found counts
- `release_gate`
- `surfaces`
- `read_only=true`
- `writes_database=false`
- `by_reason`
- row-level `issues`
- `sidecars`

## Issue Reasons

- `projection_row_missing`
- `truth_row_missing`
- `release_gate_blocked`
- `surface_mismatch`
- `surface_unverified`
- `locale_row_missing`

## AUDIT-13 Consumption

AUDIT-13 should consume this output as the batch/live-acceptance section of the final 2786 audit artifact. AUDIT-12 does not apply rollout or publish rows.
