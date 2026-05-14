# Career Runtime Artifact Refresh Plan

`career:plan-canonical-runtime-artifact-refresh` defines the read-only artifact refresh sequence required after a separately approved Career 80 delta runtime candidate preparation apply.

The plan exists because the 51 delta slugs must first be prepared as runtime `published_candidate` inventory, then projection, truth, and ledger artifacts must be refreshed before any 51-delta rollout dry-run is attempted.

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
