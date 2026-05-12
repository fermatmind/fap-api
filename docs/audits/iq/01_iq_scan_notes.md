# IQ Scan Notes

## Regeneration note

The current worktree initially had no `docs/audits/iq/` directory. This PR0-R rebuilt the IQ audit package from the repository source instead of guessing prior outputs.

## What was scanned

- `backend/routes/api.php`
- `backend/app/Http/Controllers/API/V0_3/*`
- `backend/app/Services/Assessment/*`
- `backend/app/Services/Report/*`
- `backend/config/scale_identity.php`
- `backend/database/seeders/ScaleRegistrySeeder.php`
- `backend/scripts/iq/build_iq30_questions_from_prototype.php`
- `content_packages/default/CN_MAINLAND/zh-CN/IQ-RAVEN-CN-v0.3.0-DEMO/*`
- `content_packages/default/CN_MAINLAND/zh-CN/IQ_INTELLIGENCE_QUOTIENT-CN-v0.3.0-DEMO/*`
- `fap-web/app/*`

## Key findings

1. The seeded runtime scale code is still `IQ_RAVEN`, but the alias map already points to `IQ_INTELLIGENCE_QUOTIENT`.
2. The public slug already uses the productized path `iq-test-intelligence-quotient-assessment`.
3. The v2 mirror pack still contains legacy fields:
   - `scale_code = IQ_RAVEN`
   - `slug = iq-raven-demo`
   - `canonical_path = /test/iq-raven-demo`
4. The current IQ question bank contains 30 items:
   - `matrix = 9`
   - `odd = 10`
   - `series = 11`
5. All IQ items are served as inline SVG embedded directly inside `questions.json`.
6. No standalone IQ `.svg`, `.png`, `.jpg`, `.webp`, or PDF template files were found in the pack.
7. The build chain for the 30-item bank is traceable to `backend/scripts/iq/build_iq30_questions_from_prototype.php`, which hard-codes an external input zip path:
   - `/Users/rainie/Desktop/iq_ui_prototype_30_svg_grid.zip`
8. The scoring path is still unscored:
   - `IqTestDriver` returns `ANSWER_KEY_MISSING`
   - `scoring_mode = pending_answer_key`
9. No IQ-specific report builder was found. IQ falls back to `GenericReportBuilder`.
10. No IQ-specific paid unlock SKU, benefit code, or report unlock SKU was found.
11. Repo-local frontend IQ page source is `not found`. Only `fap-web/app/robots.ts` and `fap-web/app/sitemap.ts` exist.

## Checksum notes

- `IQ-RAVEN-CN-v0.3.0-DEMO/questions.json` and `IQ_INTELLIGENCE_QUOTIENT-CN-v0.3.0-DEMO/questions.json` are byte-identical.
- `IQ-RAVEN-CN-v0.3.0-DEMO/scoring_spec.json` and `IQ_INTELLIGENCE_QUOTIENT-CN-v0.3.0-DEMO/scoring_spec.json` are byte-identical.

## Audit posture

- This repo can fully audit backend/content/report/commerce contracts for IQ.
- This repo cannot confirm the real frontend IQ rendering implementation because the page source is missing.
- The 30-item bank is suitable for legacy audit and chain verification, but not yet suitable as a scored production bank.
