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

For progressive cohorts after candidate preparation and candidate-aware artifact
refresh, pass the explicit readiness delta slug artifact instead of relying on
the legacy 80 near-eligible selector:

```bash
php artisan career:plan-canonical-80-runtime-candidate-pool \
  --audit=/tmp/career_2786_canonical_eligibility_audit_candidate_aware_300.json \
  --projection=/tmp/career_300_runtime_projection_candidate_aware.json \
  --truth=/tmp/career_300_runtime_truth_candidate_aware.json \
  --ledger=/tmp/career_300_full_release_ledger_candidate_aware.json \
  --target=220 \
  --target-total=300 \
  --cohort=career_80_to_300_delta \
  --delta-slugs=/tmp/career_300_delta_slugs.txt \
  --locales=en,zh \
  --json \
  --output=/tmp/career_300_runtime_candidate_pool_after_refresh.json
```

## Inputs

- `--audit`: full canonical eligibility audit artifact.
- `--projection`: runtime publish projection artifact.
- `--truth`: runtime truth artifact.
- `--ledger`: full release ledger artifact.
- `--target`: cohort size, default `80`.
- `--delta-slugs`: optional explicit progressive delta slug artifact for
  300/800/2786 pool planning.
- `--readiness-plan`: optional progressive readiness artifact containing
  `selected_slugs`.
- `--target-total`: optional progressive target public total metadata.
- `--cohort`: optional progressive cohort identifier.
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

Candidate-aware 51-delta planning has a narrower pre-promotion exception. A slug may pass the runtime candidate pool even when the refreshed audit still carries full-publication blockers such as `index_state_not_indexed_like`, `runtime_publish_state_not_published`, or `truth_state_not_published`, but only when the supplied planning artifacts prove all of the following:

- the ledger member, projection rows, and truth rows are candidate-aware overlay rows with `overlay_source=candidate_prep_apply_overlay`;
- the ledger evidence shows the candidate prep apply was `write_verified=true`;
- the index evidence shows `latest_index_state=promotion_candidate`;
- the projection/truth runtime state is `published_candidate` for every required locale;
- the slug is evaluated as a delta pre-promotion candidate and not as an already-published baseline slug.

This exception is only for rollout dry-run planning. It does not make the slug published, does not authorize apply, and does not satisfy final 80-total live acceptance.

Progressive 300/800/2786 planning uses the same candidate-aware runtime gate but
changes the candidate source. When `--delta-slugs` or `--readiness-plan` is
present, the planner evaluates exactly that explicit delta set. This avoids
reusing the old 80 near-eligible source pool after a larger cohort has already
passed readiness and candidate preparation. In explicit progressive mode, stale
audit/index evidence is not allowed to veto a slug that has verified
`candidate_prep_apply_overlay` projection, truth, and ledger rows; missing
projection/truth/ledger rows, blocked runtime state, already-published state,
and unexpected route/API exposure still block selection.

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
