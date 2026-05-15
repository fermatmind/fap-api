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

Canonical progressive rollout readiness also excludes CN proxy rows. Slugs with
the `cn-` prefix and rows marked as `public_cn_proxy_page` or
`public_cn_proxy_page_candidate` are not eligible for the canonical US rollout
delta. They are reported as
`cn_proxy_excluded_from_canonical_rollout` and replaced by later source-ready
non-CN rows when enough candidates exist. This keeps readiness selection aligned
with the rollout executor, which rejects CN proxy promotion before any write.

The selector also excludes the protected `software-developers` slug from
progressive rollout readiness. That slug is governed by a manual-hold policy and
the rollout executor rejects it before any write. Readiness reports it as
`software_developers_manual_hold_excluded_from_canonical_rollout` and selects a
later eligible replacement when available.

For the final 800 -> 2786 step, the selector may consume
`--cn-proxy-public-owner-plan`. This artifact lets a reviewed, noindex CN proxy
public-owner partition count toward final 2786 public accounting without adding
those CN proxy rows to the canonical rollout delta. The plan is accepted only
when it is read-only, reviewed, disabled for public route exposure, noindex by
default, and absent from sitemap, llms, and llms-full outputs.

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
- Optional final-only CN proxy public-owner plan from
  `career:validate-cn-proxy-public-owner`.

## Output

The command emits stable JSON using:

```json
{
  "schema_version": "career_progressive_readiness_selection.v1",
  "status": "pass",
  "current_public_total": 80,
  "target_public_total": 300,
  "delta_slug_count": 220,
  "canonical_delta_slug_count": 220,
  "public_owner_delta_slug_count": 0,
  "expected_delta_locale_rows": 440,
  "expected_canonical_delta_locale_rows": 440,
  "expected_public_owner_locale_rows": 0,
  "selected_count": 220,
  "final_public_accounted_count": 300,
  "final_public_shortfall": 0,
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
  "cn_proxy_public_owner_plan": {
    "provided": false,
    "ready": false,
    "public_owner_count": 0
  },
  "excluded": {
    "excluded_by_reason": {
      "already_public_baseline": 80,
      "cn_proxy_excluded_from_canonical_rollout": 120,
      "software_developers_manual_hold_excluded_from_canonical_rollout": 1
    }
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

## CN Proxy Exclusion

CN proxy rows are governed by a separate CN authority policy. They must not enter
the canonical US rollout promotion path for 300, 800, or 2786 cohorts. The
selector therefore treats these rows as hard readiness exclusions when:

- the canonical slug starts with `cn-`;
- `canonical_public_type` / `public_resolution_type` is `public_cn_proxy_page`
  or `public_cn_proxy_page_candidate`;
- `recommended_resolution` is `public_cn_proxy_page` or
  `public_cn_proxy_page_candidate`; or
- the source state is `CN_proxy_hold` / `blocked_until_CN_authority_policy`.

This exclusion is not a rollout executor relaxation. The executor and rollback
gate must continue to reject CN proxy slugs if they appear in a manifest or
manual override artifact. The selector simply prevents those rows from being
chosen earlier in the progressive readiness pipeline.

## Software Developers Manual Hold

`software-developers` is a protected manual-hold slug. It is not eligible for
canonical progressive rollout selection for 300, 800, or 2786 cohorts, even when
it appears source-ready in a public-resolution plan. The selector excludes it
with `software_developers_manual_hold_excluded_from_canonical_rollout` so a
later source-ready slug can replace it before candidate preparation.

The rollout manifest gate also fails closed if `software-developers` appears in
an explicit delta manifest. This is an earlier planning guard only; it does not
weaken the existing rollout executor, rollback gate, or final live acceptance
checks.

## Final 2786 Public Owner Partition

`--cn-proxy-public-owner-plan` is scoped to target `2786`. It does not apply to
the 300 or 800 cohorts. When supplied and valid, the selector:

- keeps CN proxy rows excluded from `selected_slugs`,
  `delta_promotion_slugs`, and `canonical_rollout_slugs`;
- records `public_owner_delta_slug_count` and
  `expected_public_owner_locale_rows`;
- reduces `canonical_delta_slug_count` to the remaining canonical rollout
  shortfall after the public-owner partition; and
- reports `final_public_accounted_count` / `final_public_shortfall`.

The public-owner plan must be validated, read-only, write-free, reviewed, noindex
by default, route-disabled, and absent from sitemap, llms, and llms-full outputs.
Invalid public-owner evidence is reported as
`cn_proxy_public_owner_plan_invalid` and is not counted toward the final target.

This authority is accounting-only for final readiness. It does not publish CN
proxy rows, does not permit canonical rollout promotion for CN proxy rows, and
does not weaken final live acceptance.

## Non-Goals

- No DB mutation.
- No candidate prep apply.
- No rollout dry-run.
- No rollout apply.
- No deploy.
- No live crawl.
- No frontend changes.
