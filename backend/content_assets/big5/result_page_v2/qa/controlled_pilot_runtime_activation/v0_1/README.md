# Big Five V2 Controlled Pilot Runtime Activation

This package records the backend-only controlled pilot runtime activation boundary for Big Five V2 result-page assets.

It does not store live identifiers, private links, score rows, report bodies, PDF files, CMS records, SEO runtime state, or deployment evidence.

Boundaries:

- `runtime_use=staging_only`
- `production_use_allowed=false`
- `ready_for_controlled_pilot_runtime=true`
- `ready_for_pilot=false`
- `ready_for_runtime=false`
- `ready_for_production=false`
- only explicit allowlist matches can attach V2 in production pilot mode
- public percentage buckets remain blocked for production controlled pilot
- legacy report rendering remains available as fallback
- production import, release snapshot activation, full rollout, CMS publish, SEO runtime, and deploy are deferred

