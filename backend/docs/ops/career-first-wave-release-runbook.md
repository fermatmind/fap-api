# Career First-Wave Release Runbook

## Scope and authority boundary

This runbook defines the backend-operated release procedure for the current Career first-wave set after B7-B15.

It is limited to:
- first-wave publish-readiness validation
- first-wave readiness summary interpretation
- first-wave override-aware release decisions
- Career attribution daily refresh sanity checks

It does not define:
- frontend smoke or rollback execution
- dashboard or analytics UI procedures
- content authoring workflow
- new source-of-truth generation

Authority stays backend-owned:
- Laravel validator output is the release authority for publish-ready vs blocked state.
- `docs/career/first_wave_manifest.json` is the frozen first-wave subject scope.
- `docs/career/first_wave_blocked_registry.json` is the blocked-governance registry.
- `docs/career/first_wave_authority_overrides.json` is the narrow source-code override surface.
- `GET /api/v0.5/career/first-wave/readiness` is the machine-readable release summary surface.

Do not treat `docs/codex/*` as production release truth.

## Source-of-truth files

Primary first-wave files:
- `docs/career/first_wave_manifest.json`
- `docs/career/first_wave_blocked_registry.json`
- `docs/career/first_wave_authority_overrides.json`

Supporting boundary/reference docs:
- `docs/career/career-gold-diff-rules.md`
- `docs/data/ingestion.md`
- `docs/ops/funnel-conversion.md`

Do not edit the three first-wave JSON files casually during release execution. Any intended override change must follow `docs/ops/career-first-wave-override-escalation-sop.md`.

## Preflight checks

Run these checks before making a release decision:

```bash
python3 -m json.tool docs/career/first_wave_blocked_registry.json >/dev/null
python3 -m json.tool docs/career/first_wave_authority_overrides.json >/dev/null
```

Then run the first-wave validator using the currently approved authority dataset:

```bash
php artisan career:validate-first-wave-publish-ready \
  --source=/absolute/path/to/authority_source.csv \
  --materialize-missing \
  --compile-missing \
  --repair-safe-partials \
  --json
```

Preflight expectations:
- validator exits `0`
- output is machine-readable JSON
- counts and occupation statuses reconcile with the committed first-wave scope
- no ad hoc local residue changes the release outcome between clean reruns

If the validator cannot be rerun cleanly, stop release work and resolve environment/process issues first.

## First-wave validator command and how to read it

Primary command:

```bash
php artisan career:validate-first-wave-publish-ready \
  --source=/absolute/path/to/authority_source.csv \
  --materialize-missing \
  --compile-missing \
  --repair-safe-partials \
  --json
```

Key output areas:
- `counts.publish_ready`
- `counts.partial`
- `counts.blocked`
- per-occupation:
  - `status`
  - `missing_requirements`
  - `blocked_governance_status`
  - `override_eligible`
  - `authority_override_supplied`
  - `remediation_class`

Interpretation rules:
- `publish_ready`
  - safe for first-wave release truth
- `partial`
  - not publish-ready
  - requires remediation before release
- `blocked` + `blocked_governance_status=blocked_override_eligible`
  - may be escalated through the narrow override SOP
- `blocked` + `blocked_governance_status=blocked_not_safely_remediable`
  - not releasable under current evidence
  - must not be forced through source-code override

Do not collapse `partial` and `blocked` into one bucket operationally. The validator is the authority for the difference.

## B14 readiness summary API and how to read it

Machine-readable summary endpoint:

```bash
curl -sS http://127.0.0.1/api/v0.5/career/first-wave/readiness | python3 -m json.tool
```

Public route:
- `GET /api/v0.5/career/first-wave/readiness`

Key fields:
- `summary_kind`
- `summary_version`
- `wave_name`
- `counts.total`
- `counts.publish_ready`
- `counts.blocked_override_eligible`
- `counts.blocked_not_safely_remediable`
- `counts.blocked_total`
- `occupations[*].status`
- `occupations[*].reason_codes`

Operational use:
- use the validator as the release authority
- use the readiness API as the release read-model confirmation surface
- counts should reconcile with the current committed first-wave state

If the validator output and readiness summary disagree, stop release work and resolve the drift before proceeding.

## Release decision rules

Release is acceptable only when all of the following hold:
- the committed first-wave scope is intact
- blocked registry and override JSON are valid
- validator runs cleanly from the approved source
- readiness summary reflects the same publish-ready/blocked picture
- any override-backed release decision is already documented and approved under the override SOP

Stop release if any of the following occur:
- validator output is non-deterministic across reruns
- a first-wave subject is blocked and not safely remediable
- an override is proposed for anything other than the supported narrow case
- a missing source row is being “solved” by proxy rows, aggregate rows, or editorial guesswork
- readiness summary drifts from validator truth

## B15 attribution refresh check

Career attribution daily read-model refresh command:

```bash
php artisan analytics:refresh-career-attribution-daily \
  --from=YYYY-MM-DD \
  --to=YYYY-MM-DD
```

Use `--dry-run` when you only need scope visibility:

```bash
php artisan analytics:refresh-career-attribution-daily \
  --from=YYYY-MM-DD \
  --to=YYYY-MM-DD \
  --dry-run
```

What to inspect:
- `from=...`
- `to=...`
- `org_scope=...`
- `attempted_rows=...`
- `deleted_rows=...`
- `upserted_rows=...`

Operational meaning:
- this is not a release gate for first-wave truth
- it is a release-adjacent sanity check that the Career attribution read model refreshes cleanly after B15

If the command errors, treat it as an ops issue to resolve before claiming full release-operability coverage.

## Evidence to retain for release sign-off

Retain all of the following:
- deployed/backend commit SHA
- validator stdout JSON
- readiness summary JSON snapshot
- current `first_wave_authority_overrides.json` state
- any override approval link or ticket reference
- attribution daily refresh command output

Recommended evidence bundle:
- release timestamp
- operator name
- source dataset reference
- exact command invocations
- pass/fail conclusion

## Explicit non-goals and forbidden actions

This runbook does not authorize:
- editing `docs/career/first_wave_manifest.json` during normal release execution
- source-row invention
- aggregate-row proxying
- replacing backend truth with frontend or CMS interpretation
- broad override filling as a routine workflow
- frontend smoke/rollback steps inside backend release truth docs

If the current system cannot support a proposed operational step directly, do not add it to the release procedure as if it already exists.
