# Career Index-State Minimum Remediation Gate

`REPAIR-INDEX-STATE-MINIMUM-APPLY-PLAN-2` adds a guarded command for the
minimum index-state remediation path identified after RUN-1F.

The command is:

```bash
php artisan career:remediate-canonical-index-state \
  --slug-artifact=/tmp/career_2786_minimum_index_state_slugs_for_80.json \
  --batch-id=career_2786_minimum_80_index_state \
  --reason=minimum_80_candidate_unlock \
  --expect-slug-count=51 \
  --dry-run \
  --json
```

## Purpose

The command accepts a reviewed explicit slug artifact and plans the exact
`index_states` rows that would be created for those slugs. It refuses broad or
implicit remediation. It does not infer all missing index states from the DB or
from the audit.

The command supports a guarded `--apply` path for a future approval-gated task,
but this PR does not run production apply.

## Slug Artifact

The reviewed artifact should contain a finite slug list:

```json
{
  "schema_version": "career_minimum_index_state_remediation.v1",
  "source": {
    "audit_artifact": "/tmp/career_2786_canonical_eligibility_audit_run1f.json",
    "purpose": "minimum_80_candidate_unlock"
  },
  "target": {
    "current_near_eligible_count": 29,
    "needed_additional_count": 51,
    "expected_near_eligible_after_plan": 80
  },
  "count": 51,
  "slugs": ["actors"]
}
```

Validation rules:

- artifact file is required
- JSON must parse
- slug list must be present and non-empty
- duplicate slugs are rejected
- wildcard and empty slugs are rejected
- declared slug count must match the actual list if present
- slug count must be at or below `--max-slugs`, default `100`
- there is no implicit all-2786 mode

## Dry-Run

`--dry-run` is non-mutating. It emits:

- `status=planned` or `status=blocked`
- `writes_database=false`
- artifact sha256
- explicit slug count and slug list
- missing occupations
- existing latest index states
- planned writes
- blockers
- future approval phrase template

Dry-run can be used against a reviewed artifact before requesting production DB
mutation approval.

## Apply Gate

`--apply` is intentionally strict. It requires:

- `--confirm-artifact-sha256` matching the artifact content
- `--expect-slug-count` matching the artifact slug count
- `--batch-id`
- `--reason`
- slug count at or below `--max-slugs`
- all target slugs have an existing `Occupation`

The apply path writes only new `index_states` rows for explicit slugs in the
artifact. It does not create occupations, backfill entities, promote runtime
truth, update sitemap/LLMS state, run rollout, or publish occupations.

Each written row includes reason code metadata:

- `career_2786_minimum_index_state_remediation`
- `minimum_80_candidate_unlock`
- `batch_id:<batch-id>`
- `reason:<reason>`
- `artifact_sha256:<sha>`
- `artifact_basename:<basename>`
- `target_state:<state>`

## Verification

After apply, the command verifies that the latest `index_states` row for each
target slug is indexed-like according to `IndexStateValue::isIndexedLike()` and
that the artifact hash metadata is present in `reason_codes`.

If verification fails, the command reports `blocked` and does not claim success.
Rollback or quarantine is not automatic and requires a separate approval.

## Future Approval Phrase

```text
I explicitly approve Career 2786 minimum index_state remediation apply for reviewed slug artifact <SLUG_ARTIFACT> with sha256 <SHA256> and <COUNT> slugs on <ENVIRONMENT>; no deploy, rollout, backfill, rollback, quarantine, or publication expansion is approved.
```

## Non-Goals

- no production apply in this PR
- no occupation backfill
- no entity field repair
- no rollout or publication expansion
- no 80 readiness run
- no manifest generation
- no deploy
- no fap-web change
