# Career Runtime Candidate Preparation Plan

`career:plan-canonical-runtime-candidate-prep` is a read-only planner for explicit Career publication deltas. It consumes either the original Career 80 target-delta artifact or a later detail-ready publication scan artifact, then emits the `published_candidate` runtime rows that a later approval-gated preparation step would need.

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

Detail-ready 1048 planning uses the read-only publication scan from
`career:audit-detail-ready-1048-candidates` and keeps the 1048 product-visible
target separate from 2786 partition accounting:

```bash
php artisan career:plan-canonical-runtime-candidate-prep \
  --target-delta=/tmp/career-detail-ready-1048-scan.json \
  --target-total=1048 \
  --cohort=detail_ready_1048 \
  --chunk-size=250 \
  --json \
  --output=/tmp/career_detail_ready_1048_runtime_candidate_prep_plan.json
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
- `target_authority`: target guardrails when supplied by the detail-ready scan,
  including manual-hold and CN proxy policy boundaries.
- `chunked_slug_artifacts`: embedded explicit slug chunks for later reviewed
  dry-run/apply gates. These chunks are artifacts, not writes; each chunk has
  `writes_database=false` and `apply_allowed=false`.
- `context_summary`: counts for missing ledger/projection/truth and candidate state repair needs.
- `apply_allowed`: always `false`.
- `next_required_action`: `RUNTIME_CANDIDATE_PREP_DRY_RUN` when planned.

For `detail_ready_1048`, the planner requires:

- target total `1048`;
- ready-not-public delta `1018`;
- no `manual_hold`, `review_needed`, `family_handoff`, `blocked`, or CN proxy
  slugs inside the runtime candidate prep delta.

The planner does not unlock `software-developers`, does not publish 2786 raw
occupation assets, and does not weaken CN proxy noindex/noncanonical policy.

## Non-goals

- No DB mutation.
- No candidate preparation apply.
- No rollout dry-run.
- No rollout apply.
- No manifest generation.
- No deploy.
- No fap-web changes.
