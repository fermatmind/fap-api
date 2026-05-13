# AUDIT-10 Career 80-Cohort Readiness Plan

AUDIT-10 adds a read-only readiness planner for the first canonical 80-cohort expansion candidate set. It consumes AUDIT-1 canonical eligibility report rows and produces a stable readiness-only plan that later manifest generation can use.

## Purpose

- Select up to 80 eligible canonical slugs from a configured candidate set or from report row order.
- Require every selected slug to have passing canonical eligibility rows.
- Carry canonical eligibility sidecars into the readiness result.
- Keep `rollout_allowed=false` unless the target cohort is fully eligible and no blocking sidecar exists.

## Non-Goals

- No rollout apply.
- No publication or backfill.
- No DB mutation.
- No production commands.
- No manifest train generation.
- No 80/300/800/2786 expansion execution.
- No 2786 readiness claim.

## Input Contract

The planner accepts a `CareerCanonicalEligibilityReport` and optional candidate slug list:

- If candidate slugs are supplied, their order controls cohort order.
- If no candidate slugs are supplied, unique slugs are read from report rows in report order.
- The default target count is 80.
- Tests use synthetic eligibility reports; the real 2786 planner artifact is not required.

## Output Contract

The result serializes as:

```json
{
  "status": "pass|blocked",
  "target_count": 80,
  "candidate_count": 80,
  "planned_count": 80,
  "eligible_count": 80,
  "blocked_count": 0,
  "rollout_allowed": true,
  "candidate_slugs": [],
  "ready_slugs": [],
  "blocked_slugs": [],
  "by_reason": {},
  "rows": [],
  "issues": [],
  "sidecars": []
}
```

`rollout_allowed` is informational for future tooling. AUDIT-10 does not run rollout.

## Issue Reasons

- `cohort_size_not_met`: fewer than the target count can be selected.
- `eligibility_row_missing`: a configured candidate has no eligibility row.
- `eligibility_blocked`: a candidate has a non-pass eligibility row.
- `sidecar_blocks_train`: a sidecar from the eligibility report cannot continue the train.
- `duplicate_candidate_slug`: the configured candidate set repeats a slug.

## AUDIT-11 Consumption

AUDIT-11 should consume `ready_slugs` and `rollout_allowed`. It must not generate expansion manifests unless `status=pass`, `rollout_allowed=true`, and the expected cohort size is met.

## Candidate Selection Report

REPAIR-80-CANDIDATE-1 adds a planning-only selector before the readiness planner:

```php
(new CareerCanonical80CandidateSelector())->select($report);
```

The selector consumes a canonical eligibility report and emits `career_80_candidate_selection.v1`. It does not run 80 readiness, generate manifests, apply rollout, or mutate DB state.

The selector ranks slugs by:

- passing locale count
- blocked locale count
- reason count
- hard blocker count

Rows are classified as:

- `ready`: all audit rows pass and the slug can be selected for a future readiness run
- `near_eligible`: no configured hard blockers, but one or more locale rows still block
- `excluded_hard_blocker`: at least one hard blocker remains

Default hard blockers include entity gaps, baseline/title gaps, index gaps, runtime authority gaps, surface context gaps, and SEO/GEO metadata gaps. A future scan can override the hard blocker list for a narrower policy review, but the default stays conservative for Career 2786.

`readiness_can_run=true` only when at least 80 ready slugs are selected. If fewer than 80 qualify, the report remains `blocked` and explains the selected, near-eligible, and excluded slug sets.
