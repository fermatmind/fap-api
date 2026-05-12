# IQ Asset Inventory

## Scope

This audit was regenerated from the current repository source in PR0-R after the current worktree was found to be missing the prior `docs/audits/iq/` archive.

## Summary

| Item | Result |
|---|---|
| Public IQ slug | `/tests/iq-test-intelligence-quotient-assessment` |
| Public take path | `/zh/tests/iq-test-intelligence-quotient-assessment/take` |
| Current seeded runtime scale code | `IQ_RAVEN` |
| V2 alias target | `IQ_INTELLIGENCE_QUOTIENT` |
| Current IQ question count | `30` |
| Section split | `matrix 9 / odd 10 / series 11` |
| Answer key | `not found` |
| Frontend IQ page source | `not found` |
| Runtime SVG mode | inline SVG embedded in `questions.json` |
| IQ-specific report builder | `not found` |
| IQ-specific paid SKU | `not found` |

## A. IQ Related Routes

| URL path | Source file / controller | Type | Page file | Entry relation |
|---|---|---|---|---|
| `/tests/iq-test-intelligence-quotient-assessment` | `backend/app/Services/PublicSurface/PublicGatewaySurfaceService.php` and `backend/tests/Feature/LandingSurfaces/LandingSurfacePublicApiTest.php` | public landing | `not found` | linked from public home/tests gateway surfaces |
| `/zh/tests/iq-test-intelligence-quotient-assessment/take` | `backend/tests/Feature/LandingSurfaces/LandingSurfacePublicApiTest.php` | test page contract | `not found` | canonical take path asserted in landing surface contract |
| `/test/iq-raven-demo` | `content_packages/default/CN_MAINLAND/zh-CN/IQ_INTELLIGENCE_QUOTIENT-CN-v0.3.0-DEMO/meta/landing.json` | legacy landing metadata | `not found` | pack-local canonical path, conflicts with public slug |
| `/api/v0.3/scales/lookup?slug=iq-test-intelligence-quotient-assessment` | `backend/routes/api.php`, `backend/app/Http/Controllers/API/V0_3/ScalesLookupController.php` | scale lookup API | not applicable | resolves public slug to scale registry row |
| `/api/v0.3/scales/{scale_code}` | `backend/routes/api.php`, `backend/app/Http/Controllers/API/V0_3/ScalesController.php` | scale show API | not applicable | registry detail read |
| `/api/v0.3/scales/{scale_code}/questions` | `backend/routes/api.php`, `backend/app/Http/Controllers/API/V0_3/ScalesController.php` | questions API | not applicable | serves IQ question payload with inline SVG |
| `/api/v0.3/attempts/start` | `backend/routes/api.php`, `backend/app/Http/Controllers/API/V0_3/AttemptWriteController.php` | start API | not applicable | begins assessment attempt |
| `/api/v0.3/attempts/submit` | `backend/routes/api.php`, `backend/app/Http/Controllers/API/V0_3/AttemptWriteController.php` | submit API | not applicable | submits answers |
| `/api/v0.3/attempts/{id}/result` | `backend/routes/api.php`, `backend/app/Http/Controllers/API/V0_3/AttemptReadController.php` | result API | not applicable | returns generic result envelope |
| `/api/v0.3/attempts/{id}/report` | `backend/routes/api.php`, `backend/app/Http/Controllers/API/V0_3/AttemptReadController.php` | report API | not applicable | gated report read |
| `/api/v0.3/attempts/{id}/report-access` | `backend/routes/api.php`, `backend/app/Http/Controllers/API/V0_3/AttemptReadController.php` | report access API | not applicable | returns locked/unlocked access state |
| `/api/v0.3/attempts/{id}/report.pdf` | `backend/routes/api.php`, `backend/app/Http/Controllers/API/V0_3/AttemptReadController.php` | PDF API | not applicable | generic report PDF route exists |
| `/api/v0.3/orders/checkout` | `backend/routes/api.php`, `backend/app/Http/Controllers/API/V0_3/CommerceController.php` | payment/order API | not applicable | generic checkout entry |
| `/api/v0.3/orders` | `backend/routes/api.php`, `backend/app/Http/Controllers/API/V0_3/CommerceController.php` | order create API | not applicable | authenticated order creation |
| `/api/v0.3/orders/{provider}` | `backend/routes/api.php`, `backend/app/Http/Controllers/API/V0_3/CommerceController.php` | provider order API | not applicable | provider-specific order creation |
| `/api/v0.3/orders/{order_no}` | `backend/routes/api.php`, `backend/app/Http/Controllers/API/V0_3/CommerceController.php` | order read API | not applicable | generic order status read |
| `/api/v0.3/orders/lookup` | `backend/routes/api.php`, `backend/app/Http/Controllers/API/V0_3/CommerceController.php` | order lookup API | not applicable | generic recovery lookup |
| `/api/v0.3/webhooks/payment/{provider}` | `backend/routes/api.php`, `backend/app/Http/Controllers/API/V0_3/Webhooks/PaymentWebhookController.php` | webhook | not applicable | generic payment callback entry |

## B. IQ Related Components

| Component path | Purpose | Renders questions | Renders SVG | Renders result page | Handles payment unlock |
|---|---|---:|---:|---:|---:|
| `backend/app/Http/Controllers/API/V0_3/ScalesLookupController.php` | resolves public slug and catalog metadata | no | no | no | no |
| `backend/app/Http/Controllers/API/V0_3/ScalesController.php` | serves `questions` payload and scale detail | no | yes, serves inline SVG JSON payload | no | no |
| `backend/app/Http/Controllers/API/V0_3/AttemptWriteController.php` | starts and submits IQ attempts | no | no | no | no |
| `backend/app/Http/Controllers/API/V0_3/AttemptReadController.php` | serves result/report/report-access/report.pdf | no | no | yes, generic API contract | no |
| `backend/app/Services/Assessment/Drivers/IqTestDriver.php` | normalizes answers and quality flags; does not score | no | no | no | no |
| `backend/app/Services/Assessment/GenericReportBuilder.php` | generic fallback report payload | no | no | yes, generic summary only | no |
| `backend/app/Services/Report/ReportComposerRegistry.php` | selects report builder by scale | no | no | yes, falls back to generic for IQ | no |
| `backend/app/Services/Report/ReportGatekeeper.php` | applies report gate and unlock stage | no | no | yes | yes, generic gate only |
| `backend/app/Services/Report/Resolvers/AccessResolver.php` | resolves benefit-code-based access | no | no | no | yes, generic entitlement logic |
| `backend/scripts/iq/build_iq30_questions_from_prototype.php` | build-time conversion from external prototype zip to `questions.json` | no | yes, extracts SVG paths | no | no |
| `not found` | frontend IQ page component | `not found` | `not found` | `not found` | `not found` |

## C. IQ Related Assets

| Asset type | Path | Static path | Online used | Referenced by |
|---|---|---|---|---|
| JSON question bank | `content_packages/default/CN_MAINLAND/zh-CN/IQ-RAVEN-CN-v0.3.0-DEMO/questions.json` | pack root | yes, current seeded runtime | `ScalesController@questions`, `QuestionsService`, `ContentPackage` |
| JSON question bank mirror | `content_packages/default/CN_MAINLAND/zh-CN/IQ_INTELLIGENCE_QUOTIENT-CN-v0.3.0-DEMO/questions.json` | pack root | indirect via alias/mirror | alias maps and content-path migration |
| JSON scoring rules | `content_packages/default/CN_MAINLAND/zh-CN/IQ-RAVEN-CN-v0.3.0-DEMO/scoring_spec.json` | pack root | yes | `IqTestDriver`, assessment engine |
| JSON scoring rules mirror | `content_packages/default/CN_MAINLAND/zh-CN/IQ_INTELLIGENCE_QUOTIENT-CN-v0.3.0-DEMO/scoring_spec.json` | pack root | indirect | alias/mirror only |
| Pack manifest | `content_packages/default/CN_MAINLAND/zh-CN/IQ_INTELLIGENCE_QUOTIENT-CN-v0.3.0-DEMO/manifest.json` | pack root | yes, metadata scan | content pack resolution |
| Landing metadata | `content_packages/default/CN_MAINLAND/zh-CN/IQ_INTELLIGENCE_QUOTIENT-CN-v0.3.0-DEMO/meta/landing.json` | pack root | yes, metadata scan | public landing metadata |
| Version metadata | `content_packages/default/CN_MAINLAND/zh-CN/IQ_INTELLIGENCE_QUOTIENT-CN-v0.3.0-DEMO/version.json` | pack root | yes, metadata scan | content versioning |
| Compiled metadata | `content_packages/default/CN_MAINLAND/zh-CN/IQ_INTELLIGENCE_QUOTIENT-CN-v0.3.0-DEMO/compiled/*.json` | compiled subdir | direct runtime usage `not found` in current IQ route scan | compiled content packaging only |
| SVG files | `not found` | `not found` | no | inline SVG is embedded in `questions.json` instead |
| PNG/JPG/WebP files | `not found` | `not found` | no | `not found` |
| PDF/report templates | `not found` | `not found` | no | `not found` |

### Asset identity notes

| File | SHA-256 | Finding |
|---|---|---|
| `IQ-RAVEN-CN-v0.3.0-DEMO/questions.json` | `3da004dddfa7df46673efb9e1496cf142f32fcf15b55b0b978dded02fbcef51c` | current runtime source |
| `IQ_INTELLIGENCE_QUOTIENT-CN-v0.3.0-DEMO/questions.json` | `3da004dddfa7df46673efb9e1496cf142f32fcf15b55b0b978dded02fbcef51c` | byte-identical mirror |
| `IQ-RAVEN-CN-v0.3.0-DEMO/scoring_spec.json` | `39143e8f7c9d2c3a03c9a1252cfa9fa85e8f02db772d9ee7b53d24eec18fcf23` | current runtime rules |
| `IQ_INTELLIGENCE_QUOTIENT-CN-v0.3.0-DEMO/scoring_spec.json` | `39143e8f7c9d2c3a03c9a1252cfa9fa85e8f02db772d9ee7b53d24eec18fcf23` | byte-identical mirror |

## D. SVG Generation Chain

| Stage | Static or dynamic | Location | Params / seed / template | viewBox / style tokens | Hash / version |
|---|---|---|---|---|---|
| Runtime IQ questions payload | static at runtime | `questions.json` inline `stem.svg` and `options[].svg` | per-item generator params `not found` | `view_box` exists on stem and options | per-item hash `not found` |
| Build-time extractor | dynamic at build time | `backend/scripts/iq/build_iq30_questions_from_prototype.php` | section config exists; seed/theme/template version `not found` | preserves extracted `viewBox`; style tokens `not found` | script versioning `not found` |
| Prototype source archive | external prototype zip | `INPUT_ZIP = /Users/rainie/Desktop/iq_ui_prototype_30_svg_grid.zip` in build script | zip path hard-coded; persisted provenance manifest `not found` | HTML-derived SVG extraction | item-level source archive hash `not found` |

## E. Scoring / Result / Report Logic

| Area | File | Finding |
|---|---|---|
| Total scoring | `backend/app/Services/Assessment/Drivers/IqTestDriver.php` | returns `status=unscored`, `reason_code=ANSWER_KEY_MISSING`, `scoring_mode=pending_answer_key` |
| Scoring contract | `content_packages/default/CN_MAINLAND/zh-CN/IQ_INTELLIGENCE_QUOTIENT-CN-v0.3.0-DEMO/scoring_spec.json` | `engine_version=v1_iq30_unscored`, `bank_id=IQ_RAVEN_30_SVG_CN_DEMO`, `item_count=30` |
| Quality rules | same `scoring_spec.json` | only `speeding_seconds_lt=30` and `straightlining_run_len_gte=8` |
| IQ-specific answer key | `not found` | no per-item `correct_answer` binding found in pack or driver |
| IQ-specific report builder | `not found` | `ReportComposerRegistry` falls back to `GenericReportBuilder` |
| Generic report payload | `backend/app/Services/Assessment/GenericReportBuilder.php` | returns only `summary`, `scores`, `generated_at` |
| Result access / gate | `backend/app/Services/Report/ReportGatekeeper.php`, `backend/app/Http/Controllers/API/V0_3/AttemptReadController.php` | generic lock/unlock/report state contract exists |
| Three formal dimensions | `not found` | no `visual_spatial_insight`, `visual_spatial_pattern_reasoning`, `numerical_pattern_reasoning` runtime contract found |

## F. Payment / Unlock Logic

| Area | File | Finding |
|---|---|---|
| IQ commercial seed | `backend/database/seeders/ScaleRegistrySeeder.php` | `price_tier=FREE` for IQ |
| IQ report benefit code | same seeder | `not found` |
| IQ report unlock SKU | same seeder | `not found` |
| Unlock resolver | `backend/app/Services/Report/Resolvers/AccessResolver.php` | full access requires `report_benefit_code` or fallback `credit_benefit_code` |
| Report gate | `backend/app/Services/Report/ReportGatekeeper.php` | IQ uses generic gate; no IQ-specific unlock stage |
| Order routes | `backend/routes/api.php`, `backend/app/Http/Controllers/API/V0_3/CommerceController.php` | generic order create / checkout / lookup routes exist |
| Order state | generic commerce tables/controllers | IQ-specific order state `not found`; generic order infrastructure exists |
| Payment callback | `backend/app/Http/Controllers/API/V0_3/Webhooks/PaymentWebhookController.php` | generic payment webhook exists; IQ-specific callback logic `not found` |

## G. Risk List

| Severity | Risk | Evidence |
|---|---|---|
| high | answer key missing for all 30 items | `IqTestDriver` and `scoring_spec.json` both mark IQ as unscored / pending answer key |
| high | identity split between `IQ_RAVEN` and `IQ_INTELLIGENCE_QUOTIENT` | scale registry seed uses `IQ_RAVEN`; alias maps and mirror pack use `IQ_INTELLIGENCE_QUOTIENT` |
| high | legacy pack metadata conflicts with public slug | mirror pack `meta/landing.json` still emits `/test/iq-raven-demo` |
| high | per-item SVG provenance incomplete | runtime uses inline SVG, but build chain depends on external prototype zip without persisted per-item hash/seed/version |
| high | ODD section lacks stable dimension binding | source only provides `section_code=odd`; no dimension metadata or solution rules |
| medium | frontend IQ page source missing in current repo | `fap-web/app` only contains `robots.ts` and `sitemap.ts` |
| medium | IQ-specific report/commercial contracts missing | no IQ-specific builder, SKU, benefit code, or PDF payload contract found |
