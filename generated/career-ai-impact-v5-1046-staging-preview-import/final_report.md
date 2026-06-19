# AI Impact v5 1046 staging preview import

Final conclusion: `AI_IMPACT_V5_1046_STAGING_PREVIEW_IMPORT_PASS`

## Deployment

- fap-api staging revision: `c5de88c49e9a4b284a9156aa3010bd064243ff5b`
- current release: `/var/www/fap-api-staging/releases/27849271998-1`

## Import

- decision: `pass`
- total_jsonl_lines: `2092`
- target_slug_count: `1046`
- validated_preview_rows: `2092`
- written_count: `2092`
- created_count: `0`
- updated_count: `2092`
- staging_write_performed: `True`
- production_import_allowed: `False`

## API Smoke

- target_rows: `2092`
- ready_rows: `2092`
- failed_rows: `0`
- http_status_counts: `{'200': 2092}`
- final_conclusion: `AI_IMPACT_V5_1046_STAGING_API_SMOKE_PASS`

## Fail Closed

- target_checks: `3`
- ready_checks: `3`
- failed_checks: `0`
- final_conclusion: `AI_IMPACT_V5_FAIL_CLOSED_PASS`

## Boundaries

- No production import was performed.
- No content asset was edited.
- No page runtime, sitemap, llms, canonical, noindex, or JSON-LD behavior was changed.
