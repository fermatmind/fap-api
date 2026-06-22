# Big Five V2 Allowlist Scope Approval

This package records the redacted allowlist scope approval shape for the Big Five V2 controlled pilot.

It approves only the dimensions and operator checklist required before live values are configured. It does not include live identifiers, private links, score rows, report bodies, PDF files, CMS records, SEO runtime state, or deployment evidence.

Boundaries:

- `runtime_use=not_runtime`
- `production_use_allowed=false`
- `scope_shape_approved=true`
- `live_allowlist_values_approved=false`
- `ready_for_activation=false`
- explicit values require separate operator authorization outside this PR
- production import, release snapshot activation, full rollout, CMS publish, SEO runtime, and deploy remain deferred

