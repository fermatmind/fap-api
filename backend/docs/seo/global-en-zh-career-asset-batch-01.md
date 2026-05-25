# GLOBAL-EN-ZH-PARITY-CAREER-ASSET-BATCH-01 Career Guides Jobs Parity Batch

## Executive Summary

This PR lands a backend-owned career asset parity batch report. It does not publish career content, create placeholder job pages, mutate production CMS, deploy, submit URLs, or use fap-web fallback content as authority.

Career guides are repo-backed and import-ready: the baseline has 36 English guide rows and 36 Chinese guide rows with complete `guide_code` parity. Career jobs are not parity-ready: the English baseline has 36 generic role profiles, while the Chinese baseline has 342 DOCX/BLS occupation rows, and the two sets currently share no `job_code` counterpart keys.

## Scope

- Build a generated career asset inventory for career guide, career job, and career recommendation surfaces.
- Classify career guides as import-ready with operator review required.
- Classify career job detail parity as deferred until translation group / counterpart mapping is designed.
- Preserve sitemap/llms exposure safety: no draft, fallback-only, placeholder, 404, or soft-404 career URL should be exposed.

## Authority Findings

### Career Guides

- Entity family: `career_guides`
- Counterpart key: `guide_code`
- English authority rows: 36
- Chinese authority rows: 36
- Missing English counterparts: 0
- Missing Chinese counterparts: 0
- State: repo-backed import-ready, but still requires controlled backend/CMS import and runtime exposure verification.

### Career Jobs

- Entity family: `career_jobs`
- Counterpart key candidate: `job_code`
- English authority rows: 36
- Chinese authority rows: 342
- Shared `job_code` counterparts: 0
- Missing English counterpart count against the ZH occupation baseline: 342
- Missing Chinese counterpart count against the EN generic role baseline: 36
- State: deferred human review. The current authority sets are different families and must not be merged by slug guessing or frontend fallback.

GLOBAL-EN-ZH-PARITY-SCAN-00 previously found 87 career job detail sitemap URLs and sampled slow hard 404s. This batch keeps job detail URL exposure gated until backend authority and runtime 200 eligibility are proven.

## Career Recommendation Boundary

Career recommendation surfaces remain decision-support snapshots only. This batch does not activate personalized recommendation pages in public sitemap/llms and does not introduce claims about precise recommendation, hiring fit, success prediction, salary guarantee, or diagnosis.

## Deferred Work

- Design explicit career job translation group mapping for the EN generic role profiles and ZH BLS/DOCX occupation rows.
- Re-check career job sitemap/llms exposure after P0 discoverability cleanup and only expose detail URLs that pass authority and runtime eligibility.
- Run controlled operator import/exposure verification for career guide details.

## Validation

- `php artisan test --filter=GlobalEnZhCareerAssetBatch01 --no-ansi`
- `php artisan route:list --no-ansi`
- `vendor/bin/pint --test`
- `composer validate --strict`
- `composer audit --locked --no-interaction --ignore-unreachable`
- `python3 -m json.tool backend/docs/seo/generated/global-en-zh-career-asset-batch-01.v1.json >/dev/null`
- JSON/YAML parse
- `git diff --check`

## Next Task

`GLOBAL-EN-ZH-PARITY-RESULT-MEDIA-ASSET-BATCH-01` should batch remaining result/report and media parity assets without activating draft content.
