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
