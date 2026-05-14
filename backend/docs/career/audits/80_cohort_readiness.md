# Career 80 Cohort Readiness

`career:plan-canonical-80-cohort-readiness` is a read-only planning command for the first Career canonical expansion cohort.

It consumes a full canonical eligibility audit artifact, selects deterministic candidates, and writes a readiness artifact. It does not query production, mutate the database, generate rollout manifests, run rollout dry-runs, or approve rollout apply.

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
- `readiness_pass`: true only when enough rollout-candidate eligible slugs can be selected.
- `candidate_count`: selectable rollout-candidate eligible slugs.
- `selected_count`: selected cohort size.
- `selection.strategy`: `policy_near_eligible_ranked`.
- `selection.slugs`: deterministic selected slug list.
- `rollout_candidate_gate.required`: true.
- `rollout_candidate_gate.expected_runtime_state`: `published_candidate`.
- `rollout_candidate_gate.eligible_count`: slugs that can enter the rollout dry-run candidate gate.
- `rollout_candidate_gate.excluded_count`: otherwise near-eligible slugs rejected before manifest generation.
- `rollout_candidate_gate.exclusions_by_reason`: stable counts for rejected runtime/public-exposure states.
- `policy_summary`: policy classifier summary from the audit rows.
- `rollout.manifest_generation_allowed`: true only after readiness passes.
- `rollout.apply_allowed`: always false.

`readiness_pass=true` means a read-only manifest train can be prepared next. It does not approve publication expansion or rollout apply.

## Candidate Policy

The audit policy can classify a slug as near-eligible for remediation sequencing before it is safe for rollout dry-run promotion. The readiness command now separates:

- `near_eligible_for_remediation`: index/entity/SEO/surface policy is close enough to continue planning.
- `eligible_for_readiness`: no hard blockers or remediation-required index/entity gaps.
- `eligible_for_rollout_dry_run_candidate`: the slug also has clean pre-promotion runtime evidence.

The command selects only rollout-candidate eligible slugs. It excludes slugs with hard blockers or remediation-required reasons such as:

- `index_state_missing`
- `occupation_missing`
- `entity_field_missing`

It also excludes slugs with runtime or public-exposure evidence that the rollout dry-run would reject:

- already published or already public slugs;
- unexpected API or route exposure;
- missing projection rows;
- missing truth rows;
- projection or truth state mismatches;
- blocked runtime state instead of `published_candidate`.

Deferred surface states and expected-not-ready SEO/GEO policy states remain visible on selected rows. They are candidate-stage requirements, not hidden passes.

If fewer than the requested target pass the rollout candidate gate, the artifact blocks with `insufficient_rollout_candidate_eligible_slugs`. That block is expected until runtime candidate state, projection/truth freshness, or readiness selection is repaired.

## Non-goals

- No DB mutation.
- No apply.
- No rollout.
- No manifest generation.
- No live crawl.
- No fap-web change.
- No production deployment.
