# Career 51-Delta Rollout Apply Batch Authority

This repair documents the authority boundary for explicit Career delta rollout apply verification.

The 51-delta rollout dry-run can use candidate-aware projection artifacts to prove that the selected
delta slugs are valid pre-promotion candidates. During apply, the executor writes indexed
`index_state` rows and then rebuilds runtime projection/truth from backend authority before committing.

For slugs already present in the full release ledger tracked set, the normal ledger cohort can still be
`review_needed` or `family_handoff`. That strict default remains correct outside an explicit rollout
batch. For a separately approved explicit rollout batch, the current batch slug list is also authority:
those slugs may override review/family handoff during post-write verification only when they are passed
as the explicit batch slug set.

Rules:

- No global review queue or family handoff bypass.
- No final live acceptance weakening.
- No frontend fallback authority.
- No DB write is authorized by this document.
- The override is limited to `CareerFullReleaseLedgerService::build($additionalSlugs)` callers that
  pass explicit rollout batch slugs.
- `indexed` batch slugs project as `published`.
- `promotion_candidate` batch slugs project as `published_candidate` for rollback/candidate verification.

After this repair, any failed apply must still stop. A fresh dry-run is required before retrying apply.

## Post-apply runtime authority

After a rollout apply succeeds, runtime projection/truth/ledger export must use the same explicit
batch authority that the apply verifier used. Verified rollout execution reports are eligible only
when they show:

- `status=promoted_success`
- `dry_run=false`
- `writes_database=true`
- `write_verified=true`
- `rollback_required=false`
- `quarantine_required=false`
- post-promotion validation `status=pass`
- release gate blocked count `0`
- persistence found every expected published row

Only those verified reports may contribute their `promoted_slugs` to the default full release ledger
projection authority. Dry-runs, failed applies, rollback/quarantine outputs, and unverifiable reports
are ignored.

This keeps the post-rollout runtime surface aligned with the write-verified production state without
turning candidate-aware planning artifacts into final published evidence. Final 80-total live
acceptance still requires published projection/truth rows and real live surface checks.
