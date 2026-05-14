# Career 80 Live Surface Authority

## Purpose

The 80-total live acceptance path uses runtime projection, runtime truth, and release-gate evidence as the final backend authority for public Career job detail routes.

SCAN-20 found that the backend authority layer had already marked 160 locale rows as published and release-gate passing, but the live API surface still failed two ways:

- some runtime-published zh locale rows were downgraded to `locale_not_ready`, which emitted `noindex` and omitted the RIASEC CTA surface;
- a runtime-published occupation without a compiled/detail display asset returned 404.

## Policy

Runtime-published surface authority is allowed only when the locale-specific runtime projection item is explicit and all of these fields pass:

- `runtime_publish_state` / `runtime_state` / `projection_state` is `published`;
- `detail_route_enabled=true`;
- `robots_indexable=true`;
- `release_gate_pass=true`.

When the normal display surface is unavailable for that locale, the API may emit a restricted `display_surface_v1` navigation shell. The shell is not a strong claim surface:

- `allow_strong_claim=false`;
- salary, AI strategy, and transition recommendation claims remain blocked;
- provenance is marked as `runtime_publish_projection`;
- the CTA remains attributed to `career_job_detail` and the explicit job slug.

## Non-goals

- No DB mutation.
- No rollout apply.
- No rollback or quarantine.
- No fap-web change.
- No weakening of final live acceptance or published-state validation.
