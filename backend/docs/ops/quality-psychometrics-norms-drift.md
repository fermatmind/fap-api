# Quality / Psychometrics / Norms & Drift

## First-phase scope

- AIC-08 is an internal-only Assessment Insights page.
- The page keeps one shell with three tabs:
  - `Quality`
  - `Psychometrics`
  - `Norms & Drift`
- It does not change write-side behavior, frontend behavior, or scoring router behavior.

## Quality tab fact model

- Daily summary authority comes from `analytics_scale_quality_daily`.
- Per-record drill-through stays in existing `Attempts Explorer` and `Results Explorer`.
- `attempt_quality` remains legacy / supplemental only and is not the cross-scale authority table for this page.

## `analytics_scale_quality_daily`

- Purpose: add the missing locale / region / content / scoring / norm version dimensions on top of first-phase quality daily aggregation.
- Source spine:
  - started / completed attempts from `attempts`
  - result-rooted quality from `results.result_json`
  - fallback validity only when `quality.level` is missing
- Stable first-phase counters:
  - started / completed / results
  - valid / invalid
  - quality A / B / C / D
  - crisis alerts
  - longstring / straightlining / extreme / inconsistency / warnings only when these signals are already stable in current payloads

## Psychometrics tab boundary

- Reads existing psychometrics snapshot tables directly:
  - `big5_psychometrics_reports`
  - `eq60_psychometrics_reports`
  - `sds_psychometrics_reports`
- These rows are `internal reference` by default.
- Small-sample rows stay reference-only and should not be treated as hard product truth.
- First-phase display thresholds reuse existing command/config defaults where available.

## Norms & Drift tab boundary

- Hard coverage view:
  - `scale_norms_versions`
  - `scale_norm_stats`
- Rollout diagnostics:
  - `scoring_models`
  - `scoring_model_rollouts`
  - observation coverage from scoped `results.result_json.model_selection`
- Drift stays a latest-vs-previous compare reference only in v1.
- No dedicated drift materialized table is added in this phase.

## Sensitive-data boundary

- Do not expose by default:
  - raw answer payload
  - raw quality checks JSON
  - internal scoring traces
  - rollout internal payload dumps
- Crisis / clinical data stays aggregate-only in v1.
- Tiny sample psychometric summaries and tiny locale/version norm compares should not be presented as hard authority metrics.

## Likely next extensions

- tighter drill-through presets into Attempts / Results explorers
- command-linked drift comparison affordances when a tab row needs deeper offline verification
- broader psychometric comparison views after sample governance and threshold rules are hardened further
