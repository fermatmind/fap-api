# Career Progressive Readiness Selection

## Purpose

`career:plan-canonical-progressive-readiness-selection` builds the source-ready
target selection for the progressive Career publication path:

- 80 -> 300: 220 delta slugs, 440 locale rows.
- 300 -> 800: 500 delta slugs, 1000 locale rows.
- 800 -> 2786: 1986 delta slugs, 3972 locale rows.

The selector starts from an accepted closeout baseline and a 2786 public
resolution/source plan. It excludes the already-public baseline slugs and picks
the next deterministic source-ready delta slugs for the requested target.

For runtime candidate prep readiness, the command can also consume a
production-derived entity context with `--entity-context`. When supplied, delta
selection requires `occupation_exists=true`; source-ready rows whose canonical
occupation is absent from production are excluded with `occupation_missing` so
they do not block the later candidate-prep dry-run.

## Readiness Selection vs Rollout Validation

This step is intentionally earlier than rollout-candidate validation. It does
not require `published_candidate` runtime rows, because those are produced later
by candidate preparation. The output is the input to target-delta planning and
runtime candidate prep, not proof that rollout can run.

Rollout dry-run and rollout apply still require the later candidate-aware
projection/truth/ledger artifacts and explicit rollout gates.

## Inputs

- Accepted closeout artifact for the current cohort.
- Current public slug list referenced by the closeout.
- 2786 public-resolution/source plan.
- Current and target public totals.
- Locale list, normally `en,zh`.
- Optional production-derived entity context from
  `career:export-canonical-eligibility-db-context`.

## Output

The command emits stable JSON using:

```json
{
  "schema_version": "career_progressive_readiness_selection.v1",
  "status": "pass",
  "current_public_total": 80,
  "target_public_total": 300,
  "delta_slug_count": 220,
  "expected_delta_locale_rows": 440,
  "selected_count": 220,
  "selected_slugs": [],
  "selection": {
    "slugs": [],
    "delta_slugs": []
  },
  "entity_context": {
    "required_for_selection": true,
    "occupation_exists_count": 2469,
    "occupation_missing_excluded_count": 27
  },
  "writes_database": false,
  "apply_allowed": false,
  "next_required_action": "PROGRESSIVE_RUNTIME_CANDIDATE_PREP"
}
```

`selected_slugs` and `selection.delta_slugs` are the new delta. `selection.slugs`
is the full target cohort, baseline plus delta, so it can feed
`career:plan-canonical-progressive-cohort-delta`.

## Occupation-Aware Selection

`--entity-context` is read-only. It does not query the database. It reads an
existing JSON artifact and builds an allowlist from rows where
`occupation_exists=true`. This preserves deterministic source ordering while
skipping source-plan rows that cannot enter runtime candidate preparation yet.

This selection rule is only for the pre-candidate-prep readiness stage. It does
not publish occupations, does not create missing occupations, and does not
weaken rollout or live-acceptance validation. Missing production occupations
remain explicit exclusions until a later source/import repair chooses to add
them.

## Non-Goals

- No DB mutation.
- No candidate prep apply.
- No rollout dry-run.
- No rollout apply.
- No deploy.
- No live crawl.
- No frontend changes.
