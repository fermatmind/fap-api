# Career Runtime Candidate Preparation Plan

`career:plan-canonical-runtime-candidate-prep` is a read-only planner for the Career 80 delta path. It consumes the target-delta artifact that separates the 29 already-published baseline slugs from the 51 delta promotion slugs, then emits the `published_candidate` runtime rows that a later approval-gated preparation step would need.

The command does not write the database, run rollout, run rollout dry-run, publish occupations, or generate rollout manifests.

## Usage

```bash
php artisan career:plan-canonical-runtime-candidate-prep \
  --target-delta=/tmp/career_80_target_delta_plan.json \
  --projection=/tmp/career_2786_runtime_projection.json \
  --truth=/tmp/career_2786_runtime_truth.json \
  --ledger=/tmp/career_2786_full_release_ledger.json \
  --locales=en,zh \
  --json \
  --output=/tmp/career_80_delta_runtime_candidate_prep_plan.json
```

The projection, truth, and ledger inputs are optional for local planning, but supplying them gives the plan useful context counts for missing ledger members, missing projection rows, missing truth rows, and candidate state repairs.

## Output

The output schema is `career_runtime_candidate_prep_plan.v1`.

Key fields:

- `status`: `planned` when the target-delta input is valid, otherwise `blocked`.
- `read_only`: always `true`.
- `writes_database`: always `false`.
- `target`: `career_80_delta`.
- `delta_slug_count`: expected to be 51 for the first delta.
- `expected_locale_rows`: `delta_slug_count * locale_count`, expected to be 102 for 51 slugs and `en,zh`.
- `planned_candidate_rows`: the planned `published_candidate` rows.
- `context_summary`: counts for missing ledger/projection/truth and candidate state repair needs.
- `apply_allowed`: always `false`.
- `next_required_action`: `RUNTIME_CANDIDATE_PREP_DRY_RUN` when planned.

## Non-goals

- No DB mutation.
- No candidate preparation apply.
- No rollout dry-run.
- No rollout apply.
- No manifest generation.
- No deploy.
- No fap-web changes.
