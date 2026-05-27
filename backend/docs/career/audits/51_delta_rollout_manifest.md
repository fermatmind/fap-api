# Career 51 Delta Rollout Manifest

`career:generate-canonical-delta-rollout-manifest` creates the read-only manifest for the Career 80 delta rollout path and can also emit the `detail_ready_1048` rollout manifest.

The manifest is intentionally delta-only:

- 29 already-published baseline slugs remain baseline accounting.
- 51 delta promotion slugs are the only rollout batch members.
- The public target remains 80 total pages after the later delta rollout.

## Command

```bash
php artisan career:generate-canonical-delta-rollout-manifest \
  --target-delta=/tmp/career_80_target_delta_plan.json \
  --candidate-prep-plan=/tmp/career_80_delta_runtime_candidate_prep_plan.json \
  --target-public-total=80 \
  --expect-delta-count=51 \
  --locales=en,zh \
  --json \
  --output=/tmp/career_80_delta_rollout_manifest.json
```

`--candidate-prep-plan` is optional for structural manifest generation, but supplying it lets the planner catch a delta-count mismatch before the later dry-run.

For the detail-ready 1048 target:

```bash
php artisan career:generate-canonical-delta-rollout-manifest \
  --target-delta=/tmp/career_detail_ready_1048_publication_scan.json \
  --candidate-prep-plan=/tmp/career_detail_ready_1048_runtime_candidate_prep_plan.json \
  --target=detail_ready_1048 \
  --target-public-total=1048 \
  --expect-delta-count=1018 \
  --batch-id=career_detail_ready_1048_canonical_001 \
  --locales=en,zh \
  --json \
  --output=/tmp/career_detail_ready_1048_rollout_manifest.json
```

This remains planner-only. It does not run candidate prep apply, rollout dry-run, rollout apply, export, deploy, or CMS mutation.

## Output Shape

```json
{
  "schema_version": "career_delta_rollout_manifest.v1",
  "status": "pass",
  "target": "career_80_delta",
  "target_public_total": 80,
  "published_baseline_count": 29,
  "delta_slug_count": 51,
  "expected_delta_locale_rows": 102,
  "batch_id": "career_80_delta_canonical_001",
  "slugs": [],
  "rollback_group": [],
  "read_only": true,
  "writes_database": false,
  "rollout_allowed": false,
  "dry_run_allowed": true,
  "apply_allowed": false,
  "next_required_action": "DELTA_ROLLOUT_DRY_RUN_51"
}
```

## Rollback Group

`rollback_group` is always the explicit 51-slug delta list. It must not be a batch id, and it must not include any of the 29 already-published baseline slugs.

For `detail_ready_1048`, `rollback_group` is the explicit 1018-slug ready-not-public delta list. The planner blocks manual-hold, CN proxy, review-needed, family-handoff, or blocked slugs if they appear in the delta evidence.

## Non-goals

- No rollout dry-run execution.
- No rollout apply.
- No candidate-prep apply.
- No DB mutation.
- No publication expansion.
- No fap-web change.
- No publication of 2289 excluded/raw assets or all 2786 occupations.

## Follow-up

The next code layer is `REPAIR-DELTA-ROLLOUT-GATE-1`, which makes the rollout dry-run gate understand delta-only promotion while validating the 80-total public target.

That gate should consume this manifest through:

```bash
php artisan career:plan-canonical-delta-rollout-gate \
  --manifest=/tmp/career_80_delta_rollout_manifest.json \
  --target-public-total=80 \
  --expect-delta-count=51 \
  --json \
  --output=/tmp/career_80_delta_rollout_gate.json
```
