# AI Impact v5 Production Import Runbook

## Preconditions

1. User gives exact approval: `批准 AI Impact v5 1046 production import, using SHA f22e0266f9b8aa904b00466c9cf751efa72835aebcee41c959d454ffacf96a92`.
2. Production dry-run report remains `decision=pass`.
3. Staging approved rows remain `2092`.
4. Database backup is completed and restorable.
5. Rollback window owner is present.

## Backup Plan

- Take a production DB backup immediately before import.
- Record backup id/path in the PR 8A ops report.
- Do not proceed if backup cannot be verified.

## Production Import Command Shape

Run only after exact manual approval. The importer must require approved source state and must report `production_rows_touched=2092`.

```bash
php artisan career:ai-impact-assets-import-preview \
  --file=/tmp/career-ai-impact-v5-production-readiness/career_risk_future_ai_impact_1046_v5_final_repaired_assets.jsonl \
  --expected-sha256=f22e0266f9b8aa904b00466c9cf751efa72835aebcee41c959d454ffacf96a92 \
  --all-slugs-from-file \
  --force \
  --status=production_imported \
  --json \
  --output=/tmp/career-ai-impact-v5-production-readiness/production_import_report.json
```

If the current command does not support production import yet, PR 8A must implement the minimal ops gate before running the command.

## Rollback Plan

- Use import run id from the production import report.
- Revert rows by `career_job_slug`, `locale`, `asset_version`, and `import_run_id`.
- Restore previous status or delete inserted production rows according to the report.
- Run API smoke after rollback.

## Stop Conditions

- Missing exact approval.
- Asset SHA mismatch.
- Approval/editorial SHA mismatch.
- Row count not `2092`.
- Slug count not `1046`.
- Any authority/projection error.
- Backup not verified.
- Production import report indicates partial write.
