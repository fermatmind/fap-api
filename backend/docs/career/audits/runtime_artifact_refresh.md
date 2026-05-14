# Career Runtime Artifact Refresh Plan

`career:plan-canonical-runtime-artifact-refresh` defines the read-only artifact refresh sequence required after a separately approved Career runtime candidate preparation apply.

The plan exists because delta slugs must first be prepared as runtime `published_candidate` inventory, then projection, truth, and ledger artifacts must be refreshed before any rollout dry-run is attempted. It supports the 51 Career 80 delta path and progressive cohort deltas such as 220, 500, and 1986 slugs.

## Usage

Pre-apply informational plan:

```bash
php artisan career:plan-canonical-runtime-artifact-refresh \
  --target=career_80_delta \
  --delta-plan=/tmp/career_80_target_delta_plan.json \
  --candidate-prep-plan=/tmp/career_80_delta_runtime_candidate_prep_plan.json \
  --json \
  --output=/tmp/career_80_delta_runtime_artifact_refresh_plan.json
```

Post-apply refresh-ready plan, only after candidate preparation apply is explicitly approved and write-verified:

```bash
php artisan career:plan-canonical-runtime-artifact-refresh \
  --target=career_80_delta \
  --delta-plan=/tmp/career_80_target_delta_plan.json \
  --candidate-prep-plan=/tmp/career_80_delta_runtime_candidate_prep_plan.json \
  --candidate-prep-apply=/tmp/career_80_delta_runtime_candidate_prep_apply.json \
  --json \
  --output=/tmp/career_80_delta_runtime_artifact_refresh_plan_after_apply.json
```

Candidate-aware read-only artifact refresh after `write_verified=true`:

```bash
php artisan career:plan-canonical-runtime-artifact-refresh \
  --candidate-prep-apply=/tmp/career_80_delta_runtime_candidate_prep_apply.json \
  --projection=/tmp/career_80_delta_runtime_projection_after_candidate_prep.json \
  --truth=/tmp/career_80_delta_runtime_truth_after_candidate_prep.json \
  --ledger=/tmp/career_80_delta_full_release_ledger_after_candidate_prep.json \
  --candidate-aware \
  --projection-output=/tmp/career_80_delta_runtime_projection_candidate_aware.json \
  --truth-output=/tmp/career_80_delta_runtime_truth_candidate_aware.json \
  --ledger-output=/tmp/career_80_delta_full_release_ledger_candidate_aware.json \
  --json \
  --output=/tmp/career_80_delta_runtime_artifact_refresh_candidate_aware.json
```

Progressive candidate-aware refresh uses the same command with an explicit target, expected slug count, and cohort-specific outputs:

```bash
php artisan career:plan-canonical-runtime-artifact-refresh \
  --target=career_80_to_300_delta \
  --candidate-prep-apply=/tmp/career_300_runtime_candidate_prep_apply.json \
  --projection=/tmp/career_300_runtime_projection_after_candidate_prep.json \
  --truth=/tmp/career_300_runtime_truth_after_candidate_prep.json \
  --ledger=/tmp/career_300_full_release_ledger_after_candidate_prep.json \
  --candidate-aware \
  --expect-slug-count=220 \
  --projection-output=/tmp/career_300_runtime_projection_candidate_aware.json \
  --truth-output=/tmp/career_300_runtime_truth_candidate_aware.json \
  --ledger-output=/tmp/career_300_full_release_ledger_candidate_aware.json \
  --json \
  --output=/tmp/career_300_runtime_artifact_refresh_candidate_aware.json
```

## Output

The output schema is `career_runtime_artifact_refresh_plan.v1`.

Key fields:

- `status`: `planned` only when the supplied candidate-prep apply artifact has `write_verified=true`; otherwise `blocked`.
- `phase`: `pre_apply`, `blocked`, or `post_apply_ready`.
- `writes_database`: always `false`.
- `required_outputs`:
  - `/tmp/career_80_delta_runtime_projection_after_candidate_prep.json`
  - `/tmp/career_80_delta_runtime_truth_after_candidate_prep.json`
  - `/tmp/career_80_delta_full_release_ledger_after_candidate_prep.json`
  - `/tmp/career_80_delta_runtime_artifact_refresh_summary.json`
- `commands`: the read-only export sequence to run later, after candidate-prep apply has passed.
- `approval_gates`: the apply and read-only gates that must remain separate from rollout apply.

Candidate-aware refresh output schema is `career_runtime_candidate_aware_artifact_refresh.v1`.

The candidate-aware mode consumes the verified candidate-prep apply artifact and overlays its verified slugs into read-only projection, truth, and ledger artifacts as hidden pre-route `published_candidate` rows. Overlay rows equal `delta_slug_count * locale_count`, and ledger overlay members equal `delta_slug_count`. Overlay rows and members carry `candidate_prep_apply_overlay` provenance and explicitly set `canonical_ledger_authority_claimed=false`. This matters because the canonical full release ledger exporter alone may omit newly prepared candidate rows or keep previously tracked members in `review_needed` / `family_handoff`; candidate-aware artifacts are planning artifacts derived from write verification, not a replacement claim that those rows originated from canonical ledger authority.

Candidate-aware artifact paths:

- `/tmp/career_80_delta_runtime_projection_candidate_aware.json`
- `/tmp/career_80_delta_runtime_truth_candidate_aware.json`
- `/tmp/career_80_delta_full_release_ledger_candidate_aware.json`
- `/tmp/career_80_delta_runtime_artifact_refresh_candidate_aware.json`

The candidate-aware artifacts are intended for the next read-only rollout dry-run for the current delta cohort. They do not publish pages, do not run rollout, and do not mutate the database.

## Approval Gates

- `RUNTIME_CANDIDATE_PREP_APPLY_51`: creates or verifies the 51 delta `published_candidate` runtime inventory after explicit approval.
- `RUNTIME_ARTIFACT_REFRESH_READ_ONLY`: refreshes projection, truth, and ledger artifacts after candidate-prep write verification.
- `DELTA_ROLLOUT_DRY_RUN_51`: future dry-run that consumes refreshed artifacts and the 51-delta manifest.

## Non-goals

- No artifact export execution.
- No candidate preparation apply.
- No DB mutation.
- No rollout dry-run.
- No rollout apply.
- No backfill.
- No rollback or quarantine.
- No deploy.
- No fap-web change.
