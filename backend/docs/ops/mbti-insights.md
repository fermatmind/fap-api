# MBTI Insights

## First-phase authority sample

MBTI Insights v1 is fixed to MBTI-only and results-based daily aggregation.

Authority rows must satisfy all of the following:

- `results` is the primary fact axis
- `results.type_code` exists directly on the row
- the result can join back to `attempts`
- the result passes the current stable validity gate (`results.is_valid = true` when present)
- the result resolves to the MBTI canonical scale scope

The first-phase authority surface excludes:

- invalid results
- orphan results
- fallback-only payloads that do not have a direct `results.type_code`
- non-MBTI scales

## Read models

### `analytics_mbti_type_daily`

Purpose:

- daily 16-type distribution
- overview KPI rollups
- locale and version splits

Core dimensions:

- `day`
- `org_id`
- `locale`
- `region`
- `scale_code`
- `content_package_version`
- `scoring_spec_version`
- `norm_version`
- `type_code`

Core metrics:

- `results_count`
- `distinct_attempts_with_results`

### `analytics_axis_daily`

Purpose:

- daily axis distribution
- locale/version axis comparison
- A/T coverage detection

Core dimensions:

- `day`
- `org_id`
- `locale`
- `region`
- `scale_code`
- `content_package_version`
- `scoring_spec_version`
- `norm_version`
- `axis_code`
- `side_code`

Core metrics:

- `results_count`
- `distinct_attempts_with_results`

## Hard metrics vs reference metrics

First-phase hard metrics:

- total results
- distinct attempts with results
- top type
- 16-type count/share
- E/I, S/N, T/F, J/P axis count/share
- locale split
- content/scoring version split

Reference or explicitly non-authoritative metrics:

- paid subset
- unlocked subset
- share subset
- share-to-purchase
- channel attribution
- tiny locale comparisons
- cross-version pools without explicit filtering

## A/T handling

`axis_states` in the current MBTI result chain represents strength labels such as `clear` or `weak`. It is not the side winner itself.

For AIC-06 v1:

- axis side resolution uses `scores_pct` first
- `type_code` is used as the fallback for side reconstruction
- A/T is only shown in the main axis panel when the current filtered scope has full A/T coverage

If the filtered scope does not fully cover A/T, the page keeps A/T out of the first authority axis panel and labels that omission explicitly.

## Later extensions

Out of scope for this PR, but expected next:

- paid/unlocked/share reference slices
- deeper version drift and norms analysis
- richer drill-through on locale/version cohorts
