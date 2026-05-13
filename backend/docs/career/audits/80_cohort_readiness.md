# Career 80 Cohort Readiness

`career:plan-canonical-80-cohort-readiness` is a read-only planning command for the first Career canonical expansion cohort.

It consumes a full canonical eligibility audit artifact, selects deterministic near-eligible candidates, and writes a readiness artifact. It does not query production, mutate the database, generate rollout manifests, run rollout dry-runs, or approve rollout apply.

## Usage

```bash
php artisan career:plan-canonical-80-cohort-readiness \
  --audit=/tmp/career_2786_canonical_eligibility_audit_after_minimum_index_apply_v2.json \
  --target=80 \
  --json \
  --output=/tmp/career_2786_80_readiness_plan.json
```

Options:

- `--audit=`: required full Career canonical eligibility audit JSON artifact.
- `--target=`: cohort size, default `80`.
- `--output=`: optional JSON artifact path.
- `--json`: emits the readiness artifact to stdout.
- `--include-sidecars`: includes audit sidecars in the readiness output.
- `--strict`: fails on malformed or ambiguous audit rows.

## Output Schema

The artifact schema is `career_80_cohort_readiness.v1`.

Important fields:

- `status`: `pass` or `blocked`.
- `readiness_pass`: true only when enough near-eligible candidates can be selected.
- `candidate_count`: selectable near-eligible candidate slugs.
- `selected_count`: selected cohort size.
- `selection.strategy`: `policy_near_eligible_ranked`.
- `selection.slugs`: deterministic selected slug list.
- `policy_summary`: policy classifier summary from the audit rows.
- `rollout.manifest_generation_allowed`: true only after readiness passes.
- `rollout.apply_allowed`: always false.

`readiness_pass=true` means a read-only manifest train can be prepared next. It does not approve publication expansion or rollout apply.

## Candidate Policy

The command selects from slugs classified as near-eligible or eligible candidates, excluding slugs with hard blockers or remediation-required reasons such as:

- `index_state_missing`
- `occupation_missing`
- `entity_field_missing`

Deferred surface states and expected-not-ready SEO/GEO policy states remain visible on selected rows. They are candidate-stage requirements, not hidden passes.

## Non-goals

- No DB mutation.
- No apply.
- No rollout.
- No manifest generation.
- No live crawl.
- No fap-web change.
- No production deployment.
