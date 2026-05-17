# Career 2786 full public resolution retrospective

This document is the project-level retrospective for the Career 2786 full public
resolution program. It summarizes the completed release evidence, the cohort
path, the authority decisions, the blockers encountered, and the guard patterns
that should be preserved for future large career publication programs.

## Scope

The program resolved the full 2786 career source set through progressive,
guarded publication and final partition-aware accounting.

Final status:

- Program status: complete.
- Final closeout decision: `CAREER_2786_FINAL_CLOSEOUT_COMPLETE`.
- Completed cohorts: 80, 300, 800, 2786.
- Final target public accounting total: 2786.
- Final public shortfall: 0.
- Production revision used for the final canonical rollout apply:
  `8bfad4a523fa15385f2f343d3d6729b4f5d33a36`.

This retrospective does not replace the machine-readable closeout artifacts. It
is a human-readable summary of the evidence captured by those artifacts.

## Existing documentation scan

A scan found several related mechanism documents, but no existing project-level
final retrospective:

- `backend/docs/career/audits/2786_full_audit_artifact_report.md`
- `backend/docs/career/audits/2786_public_resolution_partition.md`
- `backend/docs/career/audits/progressive_closeout.md`
- `backend/docs/career/audits/progressive_live_acceptance.md`
- `backend/docs/career/audits/progressive_readiness_selection.md`
- `backend/docs/career/audits/progressive_rollout_manifest_gate.md`
- `backend/docs/career/audits/cn_proxy_public_owner.md`

The scan artifact was written to `/tmp/career_2786_retrospective_scan.json`.

## Final accounting model

The final 2786 outcome is partition-aware. Not every source row became a
canonical rollout page.

| Partition | Count | Locale rows | Resolution |
| --- | ---: | ---: | --- |
| Existing accepted baseline after 800 closeout | 800 | 1600 | Already accepted public cohort |
| Final canonical rollout delta | 322 | 644 | Published through guarded rollout |
| CN proxy public-owner partition | 1663 | 3326 | Reviewed noindex public-owner accounting, not canonical rollout |
| `software-developers` manual hold | 1 | 2 | Governed non-public manual hold |
| Total accounted | 2786 | 5572 | Complete |

Final closeout evidence:

- `target_public_total=2786`
- `baseline_count=800`
- `delta_count=1986`
- `total_slug_count=2786`
- `expected_locale_rows=5572`
- `canonical_public_slug_count=1122`
- `canonical_public_locale_rows=2244`
- `cn_proxy_public_owner_count=1663`
- `software_manual_hold_count=1`
- `final_public_accounted_total=2786`
- `final_public_shortfall=0`
- `blockers_count=0`
- `sidecars_count=0`

Post-release correction:

- The evidence above proves final public-resolution partition accounting, not 2786 visible public detail pages.
- A product-visible 2786 publication claim must additionally prove public career directory `member_count=2786`, career jobs item count `2786`, detail-ready / `public_detail_indexable_count=2786`, and `5572` published locale rows.
- Artifacts with `canonical_public_slug_count=1122` or `canonical_public_locale_rows=2244` must not be accepted as “2786 careers visible” evidence, even when `final_public_accounted_total=2786`.
- New acceptance artifacts expose `full_visible_publication_gate.product_claim`. Product-facing copy must use its `safe_claim_scope` and `claimable_counts`. When the scope is `partition_accounted_not_visible_detail`, the safe claim is that 2786 assets are accounted/resolved, not that 2786 career detail pages are visible.

## Cohort path

The program intentionally avoided a single all-2786 publication action. It used
progressive cohorts and explicit guards.

| Cohort | Start | Target | Delta | Expected rows | Result |
| --- | ---: | ---: | ---: | ---: | --- |
| 80 | 29 | 80 | 51 | 160 total | Passed live acceptance and closeout |
| 300 | 80 | 300 | 220 | 600 total | Passed live acceptance and closeout |
| 800 | 300 | 800 | 500 | 1600 total | Passed live acceptance and closeout |
| 2786 | 800 | 2786 | 1986 partitioned | 5572 total | Passed full live acceptance and final closeout |

The first three cohorts used canonical rollout expansion. The final 2786 phase
used partition-aware readiness because the remaining source set contained CN
proxy policy assets and one software manual-hold slug that were not valid
canonical rollout candidates.

## 2786 final execution summary

The final 2786 canonical rollout was scoped to the 322 slugs that remained valid
for canonical promotion after partitioning.

Runtime candidate preparation:

- Plan artifact: `/tmp/career_2786_runtime_candidate_prep_plan.json`
- Dry-run artifact: `/tmp/career_2786_runtime_candidate_prep_dry_run.json`
- Apply artifact: `/tmp/career_2786_runtime_candidate_prep_apply.json`
- Apply result: `status=applied`
- `writes_database=true`
- `write_verified=true`
- `slug_count=322`
- `expected_locale_rows=644`
- `created_count=322`
- `verified_count=322`
- `failures_count=0`
- `blockers_count=0`

Final canonical rollout:

- Batch: `career_2786_delta_canonical_001`
- Rollout apply decision:
  `/tmp/career_2786_rollout_apply_decision.json`
- Decision: `TWENTY_SEVEN_EIGHTY_SIX_ROLLOUT_APPLY_PASS`
- `writes_database=true`
- `write_verified=true`
- `promoted_slug_count=322`
- `promoted_locale_rows=644`
- `release_gate_pass_count=644`
- `release_gate_blocked_count=0`
- `failures_count=0`
- Rollback required: false
- Quarantine required: false

Post-rollout runtime export:

- Decision artifact: `/tmp/career_2786_post_rollout_runtime_export_decision.json`
- Decision: `POST_2786_RUNTIME_EXPORT_PASS`
- `write_verified=true`
- `expected_locale_rows=644`
- Next action at that point: `2786-FULL-LIVE-ACCEPTANCE-RUN-1`

## Final live acceptance

Final live acceptance was read-only and partition-aware.

Primary artifacts:

- `/tmp/career_2786_full_live_acceptance.json`
- `/tmp/career_2786_full_live_acceptance_summary.json`
- `/tmp/career_2786_full_live_acceptance_decision.json`
- `/tmp/career_2786_canonical_live_acceptance.json`

Final acceptance result:

- Decision: `TWENTY_SEVEN_EIGHTY_SIX_FULL_LIVE_ACCEPTANCE_PASS`
- `accepted=true`
- `writes_database=false`
- `expected_total_locale_rows=5572`
- `canonical_public_slug_count=1122`
- `canonical_public_locale_rows=2244`
- `found_published=2244`
- `release_gate_pass_count=2244`
- `release_gate_blocked_count=0`
- `surface_equality=pass`
- `mismatch_count=0`
- `unexpected_exposure=0`
- `failures_count=0`
- `sidecars_count=0`
- `final_public_accounted_total=2786`
- `final_public_shortfall=0`

The live acceptance did not treat CN proxy public-owner rows or the manual-hold
slug as canonical public rollout pages. It accounted for them through their
separate reviewed authority evidence.

## Authority decisions that mattered

### Candidate-aware planning states

The early 80 rollout train established that `promotion_candidate` and
`published_candidate` states can be valid only for verified candidate-aware
planning. Final live acceptance remained strict and still required true
published/indexed/live evidence where intended.

This distinction was important for every later progressive cohort. Candidate
prep overlays could be used to plan rollout, but they could not be used to fake
final publication.

### Progressive readiness selection

The 300, 800, and 2786 phases required readiness selection that chose
source-ready slugs, not legacy 80 near-eligible rollout candidates. Additional
selectors excluded:

- missing occupation entities,
- CN proxy rows,
- `software-developers` manual hold,
- duplicate or already-public slugs,
- rows outside the 2786 source plan.

### CN proxy public-owner partition

CN proxy rows were not allowed into canonical rollout. A reviewed trust manifest
and public-owner plan accounted for 1663 rows as a separate noindex
public-owner partition.

The accepted plan did not enable canonical rollout, sitemap eligibility, llms
eligibility, llms-full eligibility, or public canonical job schema for those
rows.

### Software manual-hold partition

`software-developers` remained excluded from canonical rollout. A final policy
decision resolved it as a governed non-public manual hold. This counted one
source slug toward final accounting without publishing the slug or weakening the
rollout executor guard.

### Explicit artifact and batch authority

Production writes were guarded by explicit artifacts, SHA256 hashes, slug
counts, locale row counts, batch IDs, and rollback groups. The repairs around
batch authority, projection lookup, and ledger authority prevented stale or
wrong-scope artifacts from controlling verification.

## Blockers encountered and repairs

The program found several real semantic gaps. The important pattern was to stop,
repair policy or authority semantics, rerun dry-run/read-only validation, then
continue only after evidence passed.

| Area | Symptom | Resolution |
| --- | --- | --- |
| 51-delta candidate-aware planning | Candidate states were rejected globally | Accepted candidate states only for verified candidate-aware rollout planning |
| 300 readiness | Selector reused 80 rollout-candidate semantics | Added progressive readiness selection |
| 300 dry-run | CN proxy rows entered canonical rollout candidates | Added CN proxy exclusion and regenerated 300 artifacts |
| 300 live acceptance | Runtime projection lookup used stale materialized projection | Repaired latest projection selection and redeployed |
| 800 dry-run | Projection artifact shape mismatch blocked candidate-aware rollout | Repaired candidate-aware projection artifact shape |
| 800 dry-run | `software-developers` appeared in rollout manifest | Added manual-hold exclusion and regenerated 800 artifacts |
| 800 apply verification | Explicit batch ledger authority was insufficient | Repaired explicit rollout batch ledger authority |
| 2786 readiness | Full source set contained unresolved CN proxy and manual-hold partitions | Added partition-aware readiness and reviewed authority evidence |

## Evidence artifact index

| Evidence | Path |
| --- | --- |
| 2786 partition-aware readiness | `/tmp/career_2786_readiness_partition_aware_plan.json` |
| CN proxy public-owner plan | `/tmp/career_2786_cn_proxy_public_owner_plan.json` |
| Software manual-hold decision | `/tmp/career_2786_software_manual_hold_final_policy_decision.json` |
| Runtime candidate prep plan | `/tmp/career_2786_runtime_candidate_prep_plan.json` |
| Runtime candidate prep dry-run | `/tmp/career_2786_runtime_candidate_prep_dry_run.json` |
| Runtime candidate prep apply | `/tmp/career_2786_runtime_candidate_prep_apply.json` |
| Rollout apply decision | `/tmp/career_2786_rollout_apply_decision.json` |
| Post-rollout runtime export decision | `/tmp/career_2786_post_rollout_runtime_export_decision.json` |
| Full live acceptance | `/tmp/career_2786_full_live_acceptance.json` |
| Full live acceptance decision | `/tmp/career_2786_full_live_acceptance_decision.json` |
| Final closeout | `/tmp/career_2786_closeout.json` |
| Final closeout decision | `/tmp/career_2786_final_closeout_decision.json` |

## What worked

- The cohort model kept blast radius bounded. A blocker in 300 or 800 did not
  force an ambiguous all-2786 action.
- The dry-run before apply rule caught publication blockers before database
  writes.
- Explicit slug artifacts and rollback groups made each write auditable.
- Candidate-aware overlays enabled planning without weakening final acceptance.
- Partition-aware accounting let the program finish the full 2786 source set
  without incorrectly publishing policy-held rows.
- Separate closeout artifacts gave each cohort a stable baseline for the next
  cohort.

## What should be preserved

Future career publication programs should preserve these rules:

- Never use wildcard or implicit all-source selection before a phase explicitly
  owns that full scope.
- Keep readiness selection separate from rollout eligibility and final live
  acceptance.
- Require dry-run artifacts, artifact hashes, exact slug counts, exact locale
  row counts, and explicit batch IDs before every write.
- Treat candidate-aware states as planning evidence only within their scoped
  phase.
- Keep final live acceptance strict for canonical public pages.
- Resolve non-canonical partitions with explicit authority evidence instead of
  silently excluding or silently publishing them.
- Require closeout before using a cohort as the next baseline.

## Follow-up recommendations

No publication blocker remains in the final closeout evidence. The useful
follow-ups are operational hygiene:

- Archive the `/tmp/career_2786_*` evidence bundle in the team's permanent
  release evidence store.
- Keep this retrospective linked from any future Career release runbook.
- Prefer native partition-aware final closeout summaries for future large
  releases so the final partition counts are visible directly in the closeout
  artifact, not only in the final decision artifact.
- Reuse the same progressive cohort pattern for future SEO/GEO expansion waves.
