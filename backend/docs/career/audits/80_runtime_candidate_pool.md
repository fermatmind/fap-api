# Career 80 Runtime Candidate Pool Planner

`career:plan-canonical-80-runtime-candidate-pool` is a read-only planner for the first Career 80 canonical expansion cohort.

It exists after the rollout-candidate gate proved that `near_eligible` is not enough for rollout planning. A slug must also have pre-promotion runtime evidence as a `published_candidate` in the runtime projection and truth artifacts.

## Usage

```bash
php artisan career:plan-canonical-80-runtime-candidate-pool \
  --audit=/tmp/career_2786_canonical_eligibility_audit_after_minimum_index_apply_v2.json \
  --projection=/tmp/career_2786_runtime_projection.json \
  --truth=/tmp/career_2786_runtime_truth.json \
  --ledger=/tmp/career_2786_full_release_ledger.json \
  --target=80 \
  --locales=en,zh \
  --json \
  --output=/tmp/career_2786_80_runtime_candidate_pool_plan.json
```

## Inputs

- `--audit`: full canonical eligibility audit artifact.
- `--projection`: runtime publish projection artifact.
- `--truth`: runtime truth artifact.
- `--ledger`: full release ledger artifact.
- `--target`: cohort size, default `80`.
- `--locales`: required locale rows for each candidate, default `en,zh`.

All inputs are JSON artifacts. The command does not query the database.

## Output

The command emits `career_80_runtime_candidate_pool_plan.v1`:

- `pool_pass`: true only when at least `target` slugs are valid runtime candidates.
- `eligible_count` / `selected_count`: count of valid `published_candidate` slugs.
- `exclusions_by_reason`: stable counts for rejected slugs.
- `runtime_candidate_gate`: selected, eligible, and excluded evidence.
- `recovery_plan`: read-only remediation buckets for a later reviewed explicit-slug plan.
- `rollout.apply_allowed`: always false.

## Candidate Rules

A selected slug must:

- be a policy near-eligible or eligible candidate from the audit;
- have no hard blockers or remediation-required reasons;
- exist in the release ledger;
- have projection rows for every required locale;
- have truth rows for every required locale;
- have `runtime_publish_state` / `projection_state` equal to `published_candidate`;
- use `public_canonical_job`;
- have no route/API exposure before promotion.

The command excludes:

- already-published slugs;
- missing ledger members;
- missing projection rows;
- missing truth rows;
- projection/truth state mismatches;
- blocked or review-state runtime rows;
- unexpected API or route exposure.

## Non-goals

- No DB mutation.
- No apply.
- No backfill.
- No rollback or quarantine.
- No manifest generation.
- No rollout dry-run.
- No rollout apply.
- No fap-web action.

If the planner blocks, the next step is a scoped planner/remediation PR or a reviewed approval-gated candidate-state task, not rollout.
