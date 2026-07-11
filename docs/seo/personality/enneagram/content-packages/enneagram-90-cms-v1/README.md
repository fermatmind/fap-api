# ENNEAGRAM-90-CMS-V1

Local, backend-authoritative draft package for 18 Enneagram wing combinations and 27 instinctual subtype combinations in `zh-CN` and `en`.

## Boundary

- 90 individual files under `assets/` are the only editorial source assets.
- Markdown under `previews/` is generated from those JSON files and is not a second content authority.
- This directory is a draft/import handoff, not runtime authority.
- Every asset is fail-closed: `draft`, `noindex,follow`, and ineligible for index, sitemap, llms, and search release.
- No file in this package authorizes CMS writes, publication, cache warming, deployment, sitemap expansion, llms inclusion, or search submission.

## Coverage

| Family | Combinations | Locales | Pages |
|---|---:|---:|---:|
| Wings | 18 | 2 | 36 |
| Instinctual subtypes | 27 | 2 | 54 |
| Total | 45 | 2 | 90 |

## Content contract

Each page contains 11 visible sections, 5–7 visible FAQs, three visible GEO answer blocks, a same-family comparison, concrete observation exercises, source IDs, public-safe internal links, and an explicit evidence boundary. Chinese pages target 2,500–4,000 Han characters; English pages target 1,600–2,500 words.

Wings and instinctual subtypes are presented as tradition-dependent interpretive hypotheses. They are not clinical diagnoses, fixed identities, independently proven personality categories, or predictors of careers, hiring, income, education, relationships, or health.

## Build and validation

`build-package.mjs` initializes and validates deterministic package metadata. It does not call a CMS or database.

```bash
node docs/seo/personality/enneagram/content-packages/enneagram-90-cms-v1/build-package.mjs validate-init
```

The compatible backend importer is dry-run by default. It may be invoked only during the final assembly task and without `--write`.

```bash
cd backend
php artisan personality-public-assets:import \
  --source=/Users/rainie/Desktop/GitHub/fap-api/docs/seo/personality/enneagram/content-packages/enneagram-90-cms-v1/cms-import-dry-run-package.json \
  --framework=enneagram
```

The final run validated 90/90 assets with zero validation errors and zero index, sitemap, or llms eligibility. See `cms-import-dry-run-report.json`. The bundled import file and all Markdown previews are mechanically derived; the 90 files under `assets/` remain the only editorial content authority.

## Inputs

- fap-web `enneagram-90-page-blueprint-2026-07-11.json` (read-only planning brief)
- fap-web `enneagram-90-page-source-ledger-2026-07-11.json` (read-only source inventory)
- fap-api `PersonalityPublicContentAssetContract` / `personality_public_asset.v1`
- Existing bilingual Enneagram hub, center, and core-type assets for product language and public-boundary context

## Status

All 12 local tasks are frozen. Nine type QA reports, global bilingual/SEO/GEO QA, duplicate analysis, assembly verification, and the existing-contract zero-write dry-run are PASS. The package remains draft-only and pending manual editorial review; no CMS write, publish, deployment, sitemap, llms, or search-release action has been authorized or executed.
