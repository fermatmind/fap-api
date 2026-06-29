# IQ Owner 30 Beta Standard Score Notes

## Scope

This note documents `IQ-BETA-STANDARD-SCORE-1`, a backend-owned beta standard score for `IQ_OWNER_ORIGINAL_30`.

The field is not an IQ estimate. It is not production normed. It must not be presented as a population percentile, certified result, clinical/diagnostic conclusion, Mensa-style score, or official IQ claim.

## Random Baseline

The beta standard score uses the 500-run random-response baseline file referenced as:

- `source_ref`: `iq-owner-30-random-simulation-500-for-gpt.md`
- `source_kind`: `random_simulation_baseline`
- `beta_standard_score_source`: `IQ_OWNER_ORIGINAL_30_RANDOM_BASELINE_STANDARD_SCORE_V1`

Random baseline constants:

- Mean raw score: `5.096`
- Standard deviation: `2.034`
- Raw score range: `0..30`

## Formula

For a valid raw score `x`:

```text
z = (x - 5.096) / 2.034
beta_standard_score = round(100 + 15 * z)
beta_standard_score is clamped to 55..145
```

Invalid raw scores outside `0..30` emit `beta_standard_score = null` and `beta_standard_score_status = invalid_raw_score`.

## Mapping

| Raw score | z score | Beta standard score |
| ---: | ---: | ---: |
| 0 | -2.5054 | 62 |
| 1 | -2.0138 | 70 |
| 2 | -1.5221 | 77 |
| 3 | -1.0305 | 85 |
| 4 | -0.5388 | 92 |
| 5 | -0.0472 | 99 |
| 6 | 0.4444 | 107 |
| 7 | 0.9361 | 114 |
| 8 | 1.4277 | 121 |
| 9 | 1.9194 | 129 |
| 10 | 2.4110 | 136 |
| 11 | 2.9027 | 144 |
| 12 | 3.3943 | 145 |
| 13 | 3.8860 | 145 |
| 14 | 4.3776 | 145 |
| 15 | 4.8692 | 145 |
| 16 | 5.3609 | 145 |
| 17 | 5.8525 | 145 |
| 18 | 6.3441 | 145 |
| 19 | 6.8358 | 145 |
| 20 | 7.3274 | 145 |
| 21 | 7.8191 | 145 |
| 22 | 8.3107 | 145 |
| 23 | 8.8024 | 145 |
| 24 | 9.2940 | 145 |
| 25 | 9.7856 | 145 |
| 26 | 10.2773 | 145 |
| 27 | 10.7689 | 145 |
| 28 | 11.2606 | 145 |
| 29 | 11.7522 | 145 |
| 30 | 12.2439 | 145 |

## Public Payload Policy

The backend may emit the following fields for `IQ_OWNER_ORIGINAL_30` scored results and reports:

- `beta_standard_score`
- `beta_standard_score_status = simulation_calibrated_beta`
- `beta_standard_score_source = IQ_OWNER_ORIGINAL_30_RANDOM_BASELINE_STANDARD_SCORE_V1`
- `random_baseline_mean = 5.096`
- `random_baseline_sd = 2.034`
- `random_baseline_z`
- `above_random_baseline`
- `production_normed = false`
- `claim_eligible = false`
- `population_percentile_eligible = false`
- `percentile = null`
- `source_kind = random_simulation_baseline`
- `source_ref = iq-owner-30-random-simulation-500-for-gpt.md`

The beta fields do not change `raw_score`, `dimension_scores`, `quality`, `result_stability`, answer scoring, or norm authority behavior.

## Forbidden Claims

This beta mapping must not fill or override:

- `iq_estimate`
- `norms.iq_estimate`
- `norms.percentile`
- `norms.confidence_interval`
- `norms.claim_policy.claim_eligible`
- `score_claim_level = iq_estimate`

Only a claim-eligible production norm authority may populate those IQ claim fields.

## Frontend Display Guidance

Frontend surfaces may label this as a beta standard score or random-baseline beta score. They must not label it as an IQ estimate, official IQ, population rank, percentile rank, clinical score, diagnostic result, Mensa score, certified score, or production norm.

## Future Replacement

When a real human-sample production norm authority is available and claim eligible, the existing `iq_norm_authority` path remains the source of truth for `iq_estimate`, percentile, confidence interval, and public IQ claims. This beta standard score can then remain as a diagnostic/debug comparison or be hidden from public UI.
