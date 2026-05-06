# Big Five V2 Norm Eligibility Policy v0.1

This package defines the policy-only eligibility and exclusion contract for future Big Five V2 norm observations.

It does not enable dynamic norms, public percentile display, runtime-attached norming, CMS, production rollout, scoring changes, or content body changes.

Default state:

- `norm_eligible`: false
- `norm_excluded`: true
- `runtime_use`: not_runtime
- `production_use_allowed`: false
- `ready_for_production`: false
- `production_rollout_enabled`: false
- `dynamic_norm_engine_attached`: false
- `public_percentile_display_enabled`: false

Future capture must be append-only, eligibility-aware, consent-aware, and fail closed.
