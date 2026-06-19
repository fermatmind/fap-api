# AI Impact v5 1046 Dry-Run Importer Report

## Result

- final conclusion: `AI_IMPACT_V5_1046_DRY_RUN_PASS`
- decision: `pass`
- total JSONL lines: `2092`
- target slugs: `1046`
- validated preview rows: `2092`
- expected preview rows: `2092`
- career job bundle authority: `1046 / 1046`
- reader projection safety: `2092 / 2092`
- error count: `0`
- production import allowed: `false`
- staging write performed: `false`

## Source Artifact

`/Users/rainie/Desktop/GitHub/fap-web/generated/career-ai-impact-v5-1046-final-repaired/career_risk_future_ai_impact_1046_v5_final_repaired_assets.jsonl`

- expected SHA-256: `f22e0266f9b8aa904b00466c9cf751efa72835aebcee41c959d454ffacf96a92`
- observed SHA-256: `f22e0266f9b8aa904b00466c9cf751efa72835aebcee41c959d454ffacf96a92`
- SHA match: `true`

## Command

The artifact was copied to staging `/tmp` and validated with the currently deployed `fap-api` staging release.

```bash
php artisan career:ai-impact-assets-import-preview \
  --file=/tmp/career_risk_future_ai_impact_1046_v5_final_repaired_assets.jsonl \
  --expected-sha256=f22e0266f9b8aa904b00466c9cf751efa72835aebcee41c959d454ffacf96a92 \
  --all-slugs-from-file \
  --dry-run \
  --output=/tmp/career-ai-impact-v5-1046-dry-run/dry_run_report.json
```

## Scope Boundary

This was a dry-run importer validation only.

- No staging rows were written.
- No production import was attempted.
- No AI Impact content asset was modified.
- `search_projection` remains outside the reader asset payload.
- Reader projection safety gate found no `evidence_id`, `row_hash`, `source_id`, `search_projection`, or blocked AI outcome framing leakage.

## Interpretation

The fap-api AI Impact importer can parse and validate the full 1046-career, 2092-locale-row v5 final repaired asset file. The authority gate confirms every slug has career job bundle authority, runtime publish projection, and zh-CN/en detail API readiness in staging. The reader-safe projection gate confirms all rows pass internal-lineage and blocked-outcome wording checks.
