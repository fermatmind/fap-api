# Big Five V2 public surface policy v0.1

This QA package records the public-pilot boundary for Big Five V2 secondary
surfaces after route-driven controlled pilot readiness.

Scope:
- Advisory QA policy only.
- Covers PDF, share card, history, and compare surfaces.
- Does not enable public pilot or production.
- Does not change runtime behavior.

Surface policy:
- PDF remains `disabled_or_pending` with rendered status `pending_surface`.
- Share card remains `disabled_or_pending` with rendered status `pending_surface`.
- History remains `disabled_or_pending` with rendered status `pending_surface`.
- Compare remains `disabled_or_pending` with rendered status `pending_surface`.

Runtime and production safety:
- `runtime_use` remains `not_runtime`.
- `production_use_allowed` remains `false`.
- `ready_for_pilot`, `ready_for_runtime`, and `ready_for_production` remain `false`.
- Public pilot remains `no_go` unless an explicit result-page-only scope excludes
  these secondary surfaces.
