# Big Five V2 Analytics Handoff

This advisory package defines the Big Five V2 result-page growth measurement handoff. It is not a runtime source, does not emit events, does not read production data, and does not change dashboards.

The package covers full-report viewing, PDF actions, sharing, second-test movement, returning users, and D1/D7/D14/D28 retention windows. Smoke, test, QA, synthetic, fixture, Codex, generated, and operator-only artifacts must be excluded from growth reporting.

Boundaries:

- `runtime_use=not_runtime`
- `production_use_allowed=false`
- `ready_for_pilot=false`
- `ready_for_runtime=false`
- `ready_for_production=false`
- no fap-web copy
- no CMS write
- no SEO runtime change
- no production deploy
- no user-level identifiers or report bodies in analytics artifacts

