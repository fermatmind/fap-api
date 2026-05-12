# Career Canonical Eligibility Audit Schema

## Purpose

AUDIT-1 defines the stable schema layer for the Career 2786 canonical eligibility audit train. It gives later AUDIT PRs a typed JSON contract for layer statuses, audit rows, report totals, sidecars, severity values, and train continuation decisions.

AUDIT-1 is schema-only. It does not inspect production data, read the 2786 planner artifact, query the database, or claim that the full 2786 public resolution is complete.

## Non-goals

- No `career:audit-canonical-eligibility` command.
- No 2786 public-resolution source resolver.
- No occupation DB inventory.
- No manifest train generator.
- No live HTML validation.
- No deployment, SSH, production mutation, backfill, rollback, quarantine, or Batch-001 apply action.

## Row Schema

Each row represents one slug/locale eligibility decision. The stable JSON key order is:

```json
{
  "slug": "actuaries",
  "locale": "en",
  "source_scope": "batch",
  "entity_status": {},
  "baseline_status": {},
  "index_status": {},
  "runtime_status": {},
  "seo_geo_status": {},
  "surface_status": {},
  "safety_status": {},
  "overall_status": "pass",
  "severity": "info",
  "reasons": [],
  "evidence": [],
  "sidecars": []
}
```

Layer status objects use:

```json
{
  "layer": "entity",
  "status": "pass",
  "reasons": [],
  "evidence": [],
  "source": "db"
}
```

`source_scope` values are `all`, `batch`, and `slugs`.

## Layers

- `entity`: the occupation entity exists and is addressable.
- `baseline`: baseline/display metadata needed by public surfaces exists.
- `index`: index-state authority allows publication or explains the hold.
- `runtime`: backend runtime projection/truth is eligible.
- `seo_geo`: sitemap, llms, canonical, and search metadata are eligible.
- `surface`: API and frontend consumption surfaces are ready.
- `safety`: policy, governance, and train-safety checks do not block continuation.

## Status And Severity

Status values are:

- `pass`
- `fail`
- `blocked`
- `warning`
- `unverified`

Severity values are:

- `info`
- `low`
- `medium`
- `high`
- `blocker_for_publication`
- `blocker_for_full_2786_claim`

## Sidecar Schema

Sidecars record blockers or external facts without mixing them into AUDIT-1 scope:

```json
{
  "sidecar_id": "AUDIT-1-EXTERNAL-FAP-WEB-LIVE-ACCEPTANCE",
  "title": "fap-web live HTML deploy pending",
  "owner_repo": "fap-web",
  "scope_relation": "external_to_current_pr",
  "introduced_by_current_pr": false,
  "affected_slugs": [],
  "affected_locales": [],
  "evidence": [
    "AUDIT-1 is schema-only and does not deploy fap-web."
  ],
  "severity": "blocker_for_full_2786_claim",
  "next_goal": "Complete frontend deployment and live acceptance outside AUDIT-1.",
  "may_continue_train": true
}
```

Allowed `owner_repo` values are `fap-api`, `fap-web`, and `external`. Allowed `scope_relation` values are `external_to_current_pr` and `inside_current_pr`.

Validation rules:

- `sidecar_id`, `title`, `owner_repo`, `scope_relation`, `severity`, and `next_goal` are required.
- `introduced_by_current_pr` and `may_continue_train` must be booleans.
- `affected_slugs`, `affected_locales`, and `evidence` must be lists.
- Non-`info` sidecars must include evidence.
- If `introduced_by_current_pr=true`, `may_continue_train` must be `false`.
- If `scope_relation=inside_current_pr` and severity is `high`, `blocker_for_publication`, or `blocker_for_full_2786_claim`, `may_continue_train` must be `false`.

## Report Schema

The top-level report contract is:

```json
{
  "status": "pass",
  "scope": "all",
  "expected_occupations": 2786,
  "audited_occupations": 2786,
  "eligible_count": 0,
  "blocked_count": 0,
  "by_reason": {},
  "rows": [],
  "sidecars": []
}
```

AUDIT-1 only defines the shape. AUDIT-2+ will populate this contract from resolved sources and later inventory checks.

## Continue And Stop Protocol

External blockers can continue the train only when all of these are true:

- They were not introduced by the current PR.
- They are outside AUDIT-1 scope.
- AUDIT-1 scope validation is green.
- AUDIT-1 local tests pass.
- Complete sidecar evidence exists.

Current PR blockers cannot continue the train. Inside-current-PR high or blocker severity sidecars cannot continue the train.

## Pending And Failed Checks

Pending checks are a wait/poll state:

- `CHECK_STATE=pending`
- `ACTION=wait_or_poll`
- `TRAIN_CONTINUE=WAITING_FOR_CHECKS`

Failed checks require inspection, not an immediate stop:

- `CHECK_STATE=failed`
- `ACTION=inspect_failure`
- If introduced by AUDIT-1, fix within AUDIT-1 scope, push, and wait again.
- If pre-existing or external, record a complete sidecar and continue only when scope validation is green.

## AUDIT-2+ Consumption

AUDIT-2 should resolve the 2786 public-resolution source and then feed resolved slug/locale candidates into this schema. Later PRs should add inventory and eligibility logic by producing `CareerCanonicalEligibilityAuditRow` objects and rolling them into `CareerCanonicalEligibilityReport`.

Later PRs must not change the JSON key order or value taxonomy without an explicit schema migration.

## Sidecar Examples

Example external blocker that may continue AUDIT-1:

- `sidecar_id`: `AUDIT-1-EXTERNAL-FAP-WEB-LIVE-ACCEPTANCE`
- `owner_repo`: `fap-web`
- `scope_relation`: `external_to_current_pr`
- `introduced_by_current_pr`: `false`
- `severity`: `blocker_for_full_2786_claim`
- `may_continue_train`: `true`

Example AUDIT-2-owned blocker:

- `sidecar_id`: `AUDIT-1-EXTERNAL-2786-PLANNER-SOURCE`
- `owner_repo`: `external`
- `scope_relation`: `external_to_current_pr`
- `introduced_by_current_pr`: `false`
- `severity`: `medium`
- `next_goal`: `AUDIT-2 2786 public-resolution source resolver`
- `may_continue_train`: `true`

## Warning

AUDIT-1 does not claim 2786 readiness. It only creates a stable schema, sidecar validation contract, continuation policy, and unit tests for future canonical eligibility audits.
