# Career 2786 Readiness Policy Classification

`REPAIR-INDEX-ENTITY-POLICY-2` adds a read-only policy layer on top of the
canonical eligibility audit. It does not remove audit reasons and does not turn
blocked rows into passing rows. Its purpose is to explain which blockers must be
fixed now, which are expected pre-publication states, and which checks should be
deferred until a real candidate cohort exists.

## Classifications

- `hard_blocker`: context or unknown blockers that prevent meaningful candidate
  planning until repaired or classified.
- `remediation_required`: entity/index authority gaps that require reviewed
  remediation before a slug can enter an 80-candidate run.
- `expected_not_ready`: SEO/GEO publication policy states that are expected for
  non-candidate rows.
- `deferred_until_candidate`: surface verification states that should not
  trigger a 2786-wide live crawl before a candidate cohort exists.
- `approval_gated`: checks that become required for selected publication
  candidates and need explicit approval or concrete evidence.
- `near_eligible`: slugs with no entity/index hard blockers but remaining
  deferred policy or surface prerequisites.
- `eligible_candidate`: slugs with no classifier blockers for candidate
  selection.

## Surface Verification

Planner-only surface rows are allowed as context evidence, but they are not live
surface acceptance. `surface_unverified` and `surface_artifact_missing` stay in
`by_reason`. For rows that are not selected publication candidates, the policy
classifier marks those reasons as `deferred_until_candidate`.

For selected candidates, surface evidence becomes required before live
acceptance. A future live crawl must be explicit, scoped, read-only, and normally
candidate-limited rather than all 2786 rows.

## SEO/GEO Expected-Not-Ready

`sitemap_expected_not_ready`, `llms_expected_not_ready`, and
`llms_full_expected_not_ready` are expected-not-ready states for rows that are
not yet intended for publication. For selected rollout candidates, those states
become required publication prerequisites.

## Entity And Index Gaps

`occupation_missing`, `entity_field_missing`, and `index_state_missing` remain
`remediation_required`. They are not deferred surface policy and they are not
treated as expected-not-ready. Any production apply/backfill/index-state write
requires a reviewed plan and explicit approval.

## Output

`career:audit-canonical-eligibility` includes a `policy_summary` object with
counts for:

- `hard_blocker_count`
- `remediation_required_count`
- `expected_not_ready_count`
- `deferred_until_candidate_count`
- `approval_gated_count`
- `near_eligible_count`
- `eligible_candidate_count`
- `candidate_blocking_count`

The 80-candidate selector also embeds the same summary so readiness reports can
explain why fewer than 80 candidates are available without running 80 readiness
or generating a rollout manifest.

## Non-Goals

- No live crawl.
- No DB mutation.
- No index-state apply.
- No occupation backfill apply.
- No 80 readiness run.
- No rollout manifest generation.
- No rollout or deploy.
