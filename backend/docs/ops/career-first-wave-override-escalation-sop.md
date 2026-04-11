# Career First-Wave Override Escalation SOP

## Purpose

This SOP defines the only supported escalation path for narrow source-code overrides in the Career first-wave release process.

It is intentionally exceptional, narrow, and auditable.

It exists to handle only the current supported override class:
- `blocked_override_eligible`
- supported field: `crosswalk_source_code`

It does not authorize:
- source-row invention
- aggregate-row proxying
- synthetic occupation composition
- broad manual truth patching

## Definitions

### `blocked_override_eligible`

A first-wave subject is blocked, but the blocker is explicitly marked as eligible for narrow source-code override in:
- `docs/career/first_wave_blocked_registry.json`

Current examples:
- `software-developers`
- `financial-analysts`

These entries are blocked because the exact dataset row exists but the needed source code is missing. Under the current system, only an explicit authority-owned source-code override may resolve that blocker.

### `blocked_not_safely_remediable`

A first-wave subject is blocked and must not be forced through override under current evidence.

Current examples:
- `marketing-managers`
- `elementary-school-teachers-except-special-education`

These subjects are not safely remediable because the required exact source row is missing. Nearby or aggregate rows must not be used as substitutes.

## Allowed mutation surface

The only allowed override file is:
- `docs/career/first_wave_authority_overrides.json`

The only currently supported override field is:
- `crosswalk_source_code`

No other field should be added, mutated, or inferred as part of this SOP.

## Required evidence

Before proposing an override, collect and retain:
- target `canonical_slug`
- target `occupation_uuid`
- blocker type from `docs/career/first_wave_blocked_registry.json`
- proof that the subject is marked `override_eligible: true`
- authoritative evidence for the exact `crosswalk_source_code` value being proposed
- source reference showing why the value is authoritative

Evidence is insufficient if it relies on:
- “closest match” row selection
- aggregate occupation groups
- editorial inference
- frontend rendering behavior
- unreviewed spreadsheet heuristics without exact subject alignment

## Required review and sign-off

Override use requires explicit human review.

Minimum review expectations:
- confirm the subject is listed as `blocked_override_eligible`
- confirm the evidence supports the exact `crosswalk_source_code`
- confirm the change is limited to the supported field
- confirm the operator understands the override is exceptional and auditable

If `review_required` is true in the blocked registry, do not bypass review.

## Explicitly forbidden actions

The following are forbidden:
- overriding any subject marked `blocked_not_safely_remediable`
- adding override entries for `marketing-managers`
- adding override entries for `elementary-school-teachers-except-special-education`
- inventing missing source rows
- proxying through nearby or aggregate rows
- using the override file as a general publish-force mechanism
- widening the override schema beyond `crosswalk_source_code`

## Execution steps

1. Confirm the target subject is listed in `docs/career/first_wave_blocked_registry.json` with:
   - `override_eligible: true`
   - `remediation_class: authority_override_possible`
2. Gather and retain authoritative evidence for the exact `crosswalk_source_code`.
3. Edit only `docs/career/first_wave_authority_overrides.json`.
4. Add the narrow override entry for the approved subject.
5. Re-run the first-wave validator:

```bash
php artisan career:validate-first-wave-publish-ready \
  --source=/absolute/path/to/authority_source.csv \
  --authority-overrides=/absolute/path/to/docs/career/first_wave_authority_overrides.json \
  --materialize-missing \
  --compile-missing \
  --repair-safe-partials \
  --json
```

6. Confirm the subject moves from blocked to publish-ready because `authority_override_supplied` is true.
7. Re-check the machine-readable readiness summary:

```bash
curl -sS http://127.0.0.1/api/v0.5/career/first-wave/readiness | python3 -m json.tool
```

8. Record evidence and approval notes before release sign-off.

## Validation after override

Validation must show all of the following:
- validator exits `0`
- target subject is no longer blocked
- `authority_override_supplied` is true for the target subject
- the change did not alter unrelated first-wave subjects
- readiness summary reflects the updated release picture

If the override does not produce a clean, narrow outcome, remove it and stop escalation.

## Rollback / removal steps

If an override is later found to be incorrect:
1. remove the relevant entry from `docs/career/first_wave_authority_overrides.json`
2. rerun the validator with the same source dataset
3. rerun the readiness summary check
4. confirm the subject returns to the expected blocked state
5. record the rollback in release evidence

Do not leave a bad override in place while “temporarily” relying on tribal knowledge.

## Audit note requirements

For every override escalation, retain:
- date/time
- operator/reviewer
- affected `canonical_slug`
- affected `occupation_uuid`
- blocker type
- authoritative evidence source
- exact override value added
- validator output snapshot
- readiness summary snapshot
- rollback record if later removed

The override file is a source-code artifact. Treat each change as auditable release evidence, not as an informal operator tweak.
