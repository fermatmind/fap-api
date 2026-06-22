# Big Five V2 Controlled Pilot Gate

This advisory package records the controlled pilot gate for Big Five V2 result-page assets. It does not enable runtime, rollout, import, production traffic, CMS, SEO runtime, or fap-web copy.

The only permitted pilot mode described here is allowlist-only. The gate requires readiness evidence, free full-report runtime QA evidence, analytics handoff evidence, SEO/GEO control evidence, explicit scoped allowlist dimensions, and rollback/kill-switch evidence before any separate runtime activation request.

Boundaries:

- `runtime_use=not_runtime`
- `production_use_allowed=false`
- `ready_for_controlled_pilot_review=true`
- `ready_for_pilot=false`
- `ready_for_runtime=false`
- `ready_for_production=false`
- no production rollout
- no production import
- no fap-web copy
- no CMS write
- no SEO runtime change
- no user-level identifiers, private links, PDF files, score data, or report bodies in artifacts

