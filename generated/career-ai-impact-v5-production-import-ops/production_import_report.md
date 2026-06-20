# AI Impact v5 1046 Production Import Ops Report

Final conclusion: `AI_IMPACT_1046_PRODUCTION_IMPORT_PASS`

## Authorization

- Approved asset SHA-256: `f22e0266f9b8aa904b00466c9cf751efa72835aebcee41c959d454ffacf96a92`
- Production import PR: https://github.com/fermatmind/fap-api/pull/2144
- Projection hotfix PR: https://github.com/fermatmind/fap-api/pull/2146

## Production Import

- Decision: `pass`
- Mode: `production_import`
- Production import performed: `True`
- Written rows: `2092`
- Production imported rows: `2092`
- Target slug count: `1046`
- Validated rows: `2092` / `2092`
- Duplicate key count: `0`
- Source SHA match: `True`

## Backup

- Backup path: `/tmp/fap-api-production-backups/fap_prod_before_ai_impact_v5_import_20260620T033606Z.sql.gz`
- Backup bytes: `929387490`
- Backup SHA-256: `686e07dc0e73c2521112a65fb1d59cd436cfeccaedbed8b340a795661b15780e`

## Projection Hotfix Deploy

- Workflow run: https://github.com/fermatmind/fap-api/actions/runs/27859710476
- Deploy conclusion: `success`
- Production release path: `/var/www/fap-api/current`
- Production release revision: `dca3f04260dbdcb1c6bee0a55d75ee8e4703781a`

## Production API Smoke

- Conclusion: `AI_IMPACT_1046_PRODUCTION_API_SMOKE_PASS`
- Target rows: `2092`
- Ready rows: `2092`
- Failed rows: `0`
- HTTP status counts: `{'200': 2092}`
- Status counts: `{'production_imported': 2092}`
- Preview counts: `{'False': 2092}`
- Locale counts: `{'en': 1046, 'zh-CN': 1046}`
- Error counts: `{}`

## Deferred

- No sitemap, llms.txt, canonical, noindex, or JSON-LD changes were made in this ops report.
- Post-import SEO safety audit is a separate fap-web follow-up.
