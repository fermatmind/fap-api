# Question Analytics

## First-phase authoritative scope

- The page is fixed to `BIG5_OCEAN` in v1.
- `CLINICAL_COMBO_68` is excluded because it is rowless.
- `SDS_20` stays out of authoritative option distribution because answer rows are redacted.
- `MBTI`, `EQ_60`, and `IQ_RAVEN` are intentionally deferred until their row coverage and question-order reconstruction are verified separately.

## Read models

### `analytics_question_option_daily`

Authority table for question-level option distribution.

- Source axis: `attempt_answer_rows + attempts`
- Granularity: day + org + locale + region + scale + version bundle + question + option
- Main metrics:
  - `answered_rows_count`
  - `distinct_attempts_answered`

### `analytics_question_progress_daily`

Authority table for question-level progression, completion, and dropoff.

- Source axis: `attempts + attempt_drafts + attempt_answer_rows`
- Granularity: day + org + locale + region + scale + version bundle + question
- Main metrics:
  - `reached_attempts_count`
  - `answered_attempts_count`
  - `completed_attempts_count`
  - `dropoff_attempts_count`

## Hard metrics in v1

- total answered rows
- distinct attempts with answers
- per-question answer count
- option share
- reached / answered / completed / dropoff counts
- dropoff rate
- completion rate

## Reference-only / non-authoritative metrics

- duration metrics
- duration heatmaps
- slowest question rankings
- events-only question analytics
- raw answer payload exposure
- Clinical / SDS option-level comparisons
- mixed-version pooled conclusions
- tiny-locale conclusions

## Why duration is deferred

`attempt_answer_rows.duration_ms` is currently an attempt-level duration copied onto each row, not a trustworthy question-level duration fact. Because of that, Question Analytics v1 does not ship an authoritative duration panel or duration read model.

## Question order rule

- Question order is reconstructed from the BIG5 content pack.
- Frontend `question_index` is not used as the sole authority.
- If a scale cannot reconstruct stable question order from content definitions, it should not enter the authoritative Question Analytics scope.

## Next likely extensions

- enablement PRs for other safe row-capable scales after row coverage checks
- a reference-layer duration tab once question-level duration facts are reliable
- deeper research / psychometrics pages in later AIC phases
