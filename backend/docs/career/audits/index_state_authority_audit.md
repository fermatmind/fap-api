# Career Index-State Authority Audit

AUDIT-5 adds the read-only index-state authority layer for the Career 2786 canonical eligibility audit train.

## Purpose

The auditor consumes canonical slugs from AUDIT-2 plan rows or AUDIT-3 entity inventory output and verifies the latest `index_states` authority row for each occupation.

## Checks

For each canonical slug, AUDIT-5 checks:

- latest `index_states` row exists
- raw `index_state` is indexed-like: `indexable` or `indexed`
- `IndexStateValue::publicFacing()` normalization is represented
- `index_eligible=true`
- explicit `noindex` blockers
- quarantine blocker evidence in raw state or `reason_codes`
- rollback blocker evidence in raw state or `reason_codes`

## Issue Reasons

AUDIT-5 emits:

- `index_state_missing`
- `index_state_not_indexed_like`
- `index_eligible_false`
- `explicit_noindex_block`
- `quarantine_block`
- `rollback_block`

## Row Contract

Each `CareerIndexStateAuthorityRow` serializes as:

- `canonical_slug`
- `occupation_id`
- `index_state_id`
- `raw_index_state`
- `public_index_state`
- `index_eligible`
- `changed_at`
- `index_status`
- `reason_codes`
- `evidence`
- `issues`

`index_status` is an AUDIT-1-compatible `CareerCanonicalEligibilityLayerStatus` with `layer=index` and `source=index_states`.

## Non-Goals

AUDIT-5 does not:

- mutate or backfill `index_states`
- create or update Occupations
- inspect baseline/display metadata
- inspect runtime projection/truth
- inspect SEO/GEO or live surfaces
- run rollout, apply, rollback, quarantine, deployment, or production validation

Local PHPUnit DB usage is limited to normal Laravel test database conventions.

## Consumption by AUDIT-6+

AUDIT-6 should consume this result only as the index layer authority. Runtime publication and projection/truth checks must remain separate and must not be inferred from an indexed-like state alone.

## Readiness Warning

AUDIT-5 does not claim 2786 readiness. It proves only reusable index-state authority inventory behavior for future command integration.

## REPAIR-INDEX-1 Remediation Planning

REPAIR-INDEX-1 adds a read-only remediation plan shape on top of the existing authority audit. `CareerIndexStateAuthorityAuditor::planRemediation()` consumes a `CareerPublicResolutionPlan`, runs the same latest-index-state inspection, and returns `career_index_state_remediation_plan.v1`.

The remediation plan does not apply, seed, backfill, or mutate `index_states`. It classifies each slug into one of:

- `expected_indexed`
- `governed_non_public`
- `not_yet_promoted`

The plan then assigns a non-mutating action:

- `none` for indexed/indexable rows with no issues
- `create_index_state` for expected-public rows missing an index-state authority row
- `review_existing_index_state` for existing rows with noindex, ineligible, quarantine, rollback, or non-indexed-like state
- `defer_governed_non_public` for governed non-public rows that should not be treated as publish-blocking index defects yet
- `defer_until_runtime_promotion` for planner rows that are present but not promoted into the public runtime authority

Rows requiring production DB writes set `approval_required=true` and emit the approval gate:

`I explicitly approve production index_state remediation apply for Career 2786 using reviewed plan <PLAN_PATH>.`

This approval gate is informational in this PR. No apply command is added, and no production mutation is performed.
