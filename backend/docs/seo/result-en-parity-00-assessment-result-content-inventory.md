# RESULT-EN-PARITY-00 Assessment Result/Report Content Inventory

Decision output: `result_en_parity_inventory_completed_ready_for_asset_catalog_fix`

This is a read-only inventory for assessment result, report, My Results, PDF, email, and share content assets. It does not translate content, modify scoring, mutate CMS, deploy, submit URLs, run production migrations, access production user data, or commit fap-web changes.

## Scope

Covered test families:

- MBTI
- Big Five / `BIG5_OCEAN`
- Enneagram
- EQ / `EQ_60`
- RIASEC / Holland Career Interest
- Depression Screening / `SDS_20`
- Depression + Anxiety Assessment / `CLINICAL_COMBO_68`
- IQ / `IQ_INTELLIGENCE_QUOTIENT`

Authority boundary:

- Backend scoring, CMS, and result/report asset catalogs are authority.
- fap-web fallback content is not authority.
- fap-web Chinese labels used only for presentation are recorded.
- fap-web report interpretation copy is flagged as an authority risk unless it is explicitly migration-only or backend-owned by repo convention.

Production observation was limited to a read-only Chrome visit to `https://fermatmind.com`. The public homepage loaded and exposed public test links plus `/zh/results/lookup` and `/en` navigation. Private result/report/My Results pages were not accessed without credentials, attempt IDs, or secrets.

## Routes And APIs

Backend result/report API inventory:

- `POST /api/v0.3/attempts/start`
- `POST /api/v0.3/attempts/submit`
- `POST /api/v0.3/attempts/{id}/email-bind`
- `GET /api/v0.3/attempts/{id}`
- `GET /api/v0.3/attempts/{id}/result`
- `GET /api/v0.3/attempts/{id}/report`
- `GET /api/v0.3/attempts/{id}/report-access`
- `GET /api/v0.3/attempts/{id}/report.pdf`
- `POST /api/v0.3/attempts/{id}/share`
- `GET /api/v0.3/attempts/{id}/share`
- `GET /api/v0.3/shares/{id}`
- `POST /api/v0.3/results/lookup-by-email`

fap-web route inventory, reference-only:

- `app/(localized)/[locale]/(app)/result/[id]/page.tsx`
- `app/(localized)/[locale]/attempts/[attemptId]/report/page.tsx`
- `app/(localized)/[locale]/results/lookup/page.tsx`
- `app/(localized)/[locale]/share/[id]/page.tsx`
- `app/og/share/[id]/route.tsx`
- `app/(localized)/[locale]/(app)/history/mbti/page.tsx`
- `app/(localized)/[locale]/(app)/history/big5/page.tsx`
- `app/(localized)/[locale]/(app)/history/big5/compare/page.tsx`
- `app/(localized)/[locale]/(app)/history/enneagram/page.tsx`
- `app/(localized)/[locale]/(app)/history/riasec/page.tsx`

## Backend Source Inventory

Common backend sources:

- `backend/app/Services/Report/ReportComposerRegistry.php`
- `backend/app/Http/Controllers/Api/V03/AttemptReadController.php`
- `backend/app/Services/Results/ResultEmailLookupService.php`
- `backend/app/Services/Share/ShareService.php`
- `backend/app/Services/Report/Pdf/ReportPdfDocumentService.php`
- `backend/app/Services/Report/Pdf/BigFivePdfDocumentService.php`
- `backend/resources/views/emails/en`
- `backend/resources/views/emails/zh`

Composer mapping:

- MBTI: `ReportComposer`, `ReportPayloadAssembler`, MBTI public projection builders, legacy compatibility builders.
- Big Five: `BigFiveReportComposer`, `BigFivePublicProjectionService`, `BigFivePackLoader`, ResultPageV2 assets.
- Enneagram: `EnneagramReportComposer`, `EnneagramPublicProjectionService`, `backend/content_packs/ENNEAGRAM/v2/registry/*`.
- EQ: `Eq60ReportComposer`, `Eq60PackLoader`, `backend/content_packs/EQ_60/v1/compiled/*`.
- RIASEC: `RiasecReportComposer`, `RiasecPublicProjectionService`, `RiasecLifecycleCopyService`, `backend/content_assets/riasec/*`.
- Depression screening: `Sds20ReportComposer`, `Sds20PackLoader`, `SDS_20` and `DEPRESSION_SCREENING_STANDARD` packs.
- Depression + Anxiety: `ClinicalCombo68ReportComposer`, `ClinicalComboPackLoader`, `ClinicalComboBlockSelector`.
- IQ: `IqReportBuilder`, `IqResultPayloadRedactor`.

## Frontend Renderer Inventory

Reference-only fap-web renderers:

- `components/result/ResultClient.tsx`
- `components/result/RichResultReport.tsx`
- `components/result/mbti/MbtiResultShell.tsx`
- `components/result/big5/Big5ResultShell.tsx`
- `components/result/big5/Big5ResultPageV2Shell.tsx`
- `components/result/enneagram/EnneagramResultShell.tsx`
- `components/result/eq/EQResultV5.tsx`
- `components/result/riasec/RiasecResultShell.tsx`
- `components/result/iq/IqResultShell.tsx`
- `components/result/iq/IqReportModule.tsx`
- `components/clinical/report/ClinicalReportClient.tsx`
- `components/clinical/report/ReportSectionRenderer.tsx`
- `components/share/MbtiShareSummaryCard.tsx`
- `components/share/EnneagramShareSummaryCard.tsx`
- `components/support/ResultEmailLookupForm.tsx`

Important frontend risk: MBTI clone content and Big Five result page fixtures/assets include large Chinese interpretation-copy islands. They must not become result/report authority. Presentation labels can remain frontend product code, but result interpretation prose belongs in backend/CMS asset catalogs.

## Asset Matrix

Counts below are repo-visible backend result/report asset counts, not production CMS data and not private user data.

| Test family | Backend content architecture | zh assets | en assets | Missing English asset keys | Chinese leakage risk | PDF/email/share/My Results coverage |
|---|---:|---:|---:|---|---|---|
| MBTI | Structured result codes plus external content packages, legacy backend fallbacks, and frontend clone content | 0 repo-visible backend package exports | 0 repo-visible backend package exports | `backend_external_content_package_export_required`, `legacy_mbti_generated_fallback_copy.en`, `frontend_mbti_clone_content_base.en`, `frontend_mbti_clone_content_variants.en` | High | Generic metadata PDF only; shared email templates exist; MBTI share renderer exists; history route exists; lookup helper supports MBTI but public lookup requires verification |
| Big Five | v1 localized compiled rows plus v2 Chinese-heavy backend content assets | 436 v1 rows; 419 v2 repo-visible asset files | 436 v1 rows; 0 v2 counterparts | `result_page_v2.route_matrix.en`, `coupling_assets.en`, `scenario_action_assets.en`, `facet_assets.en`, `canonical_profiles.en`, `core_body.en`, `selector_ready_assets.en` | High for v2, medium for v1 | BigFive PDF service exists; shared email templates exist; history/compare routes exist; lookup helper supports BIG5 |
| Enneagram | Composer labels plus zh-CN registry catalogs | 9 registry groups | 0 registry groups | `type_registry.en`, `pair_registry.en`, `group_registry.en`, `observation_registry.en`, `technical_note_registry.en`, `method_registry.en`, `sample_report_registry.en`, `state_registry.en`, `scenario_registry.en`, `ui_copy_registry.en` | High | Generic metadata PDF only; shared email templates exist; share renderer exists; history route exists; lookup helper supports ENNEAGRAM |
| EQ | Localized compiled report packs | 130 | 130 | None found in current repo-visible packs | Low current assets, medium future fallback | Generic metadata PDF only; shared email templates exist; generic share path exists; lookup helper supports EQ_60 |
| RIASEC | Backend composer plus zh-CN-only lifecycle/content assets | 19 | 0 | `content_assets/riasec/*.en.json`, lifecycle `share_pdf_history`, `faq`, `technical_note_user_summary`, `professional_method_boundary` | High | Generic metadata PDF only; lifecycle share/pdf/history assets point to zh-CN; history route exists; lookup helper supports RIASEC |
| Depression Screening | Localized compiled report packs | 5 | 5 | None found in current repo-visible packs | Medium due sensitive fallback | Generic metadata PDF only; shared email templates exist; sensitive sharing should remain constrained; lookup helper excludes SDS_20 |
| Depression + Anxiety | Localized compiled packs with incomplete EN paid rows | 46 | 38 | `paid_action_anxiety_14d.en`, `paid_action_burnout.en`, `paid_action_depression_14d.en`, `paid_action_ocd_erp_start.en`, `paid_action_perfectionism_14d.en`, `paid_perf_cm_mistakes.en`, `paid_perf_da_doubts.en`, `paid_perf_org_order.en`, `paid_perf_pe_parental.en`, `paid_perf_ps_standards.en` | High for paid sections | Generic metadata PDF only; shared email templates exist; sensitive sharing should remain constrained; lookup helper excludes CLINICAL_COMBO_68 |
| IQ | Backend-generated report sections with Chinese dimension labels | 3 | 0 | `iq.dimensions.visual_spatial_insight.en`, `iq.dimensions.visual_spatial_pattern_reasoning.en`, `iq.dimensions.numeric_pattern_reasoning.en`, `iq_pro.pdf_payload.en`, `iq_pro.certificate_payload.en` | High | IQ pro PDF/certificate payload is contract-defined not implemented; shared email templates exist; generic share path exists; lookup helper supports IQ_RAVEN |

## Architecture Findings

1. Result/report content is not one architecture. Some tests use compiled localized packs, some use backend hardcoded scaffolds, some use zh-only content asset catalogs, and MBTI relies on external packages plus legacy fallbacks.
2. Several loaders/composers can fall back to `zh-CN` when requested English content is missing. That makes missing English assets hard to detect and risks Chinese leakage on EN result/report pages.
3. Big Five v1 has balanced compiled localized rows, but Big Five ResultPageV2 assets do not have repo-visible English counterparts.
4. EQ and SDS currently have balanced repo-visible compiled packs, but sensitive or paid result surfaces still need fail-closed no-zh-fallback gates.
5. PDF services exist, but current scanned PDF coverage is metadata-style, not full report-prose bilingual parity.
6. Result email lookup public behavior currently requires verification and returns an empty list. Private helper coverage excludes sensitive SDS/clinical combo scales.

## Claim Boundary Coverage

Sensitive tests require stricter result/report wording gates:

- Depression and anxiety pages must remain self-assessment, non-diagnostic, and professional-help bounded.
- IQ must remain online estimate / confidence-interval language, not real IQ or clinical authority.
- RIASEC and Big Five must remain interest signal / workstyle explanation, not precise career recommendation or hiring suitability.
- MBTI career language must avoid career success prediction.

## Recommended Next PRs

Proposed follow-up PRs need explicit manifest/state authorization before implementation:

1. `RESULT-EN-PARITY-01` - Result/report asset catalog export and fail-closed locale parity gate. Scope: export authoritative backend result/report asset keys by family and fail when public EN depends on zh-CN fallback.
2. `RESULT-EN-PARITY-02` - RIASEC result/report English asset catalog. Scope: add English backend content asset counterparts for lifecycle/share/pdf/history/FAQ/method-boundary copy.
3. `RESULT-EN-PARITY-03` - IQ locale-safe report builder labels. Scope: move IQ dimensions into locale-aware backend catalog and add EN/ZH tests.
4. `RESULT-EN-PARITY-04` - Clinical combo paid section EN parity. Scope: add missing paid action/performance EN blocks and fail sensitive EN reports that fall back to zh-CN.
5. `RESULT-EN-PARITY-05` - MBTI backend content package export and frontend interpretation de-authoring. Scope: materialize/export authoritative MBTI content package keys and mark frontend clone content non-authoritative or migration-only.
6. `RESULT-EN-PARITY-06` - Big Five ResultPageV2 EN asset catalog parity. Scope: add English counterparts for route matrix, coupling, scenario action, facets, and canonical profiles.

## Validation Target

Generated JSON artifact:

- `backend/docs/seo/generated/result-en-parity-00-assessment-result-content-inventory.v1.json`

Focused test:

- `backend/tests/Feature/SeoIntel/ResultEnParity00AssessmentResultContentInventoryTest.php`

Repository rule impact: this PR changes no runtime ownership. It records that result/report interpretation content must remain backend/CMS authoritative and that fap-web fallback content is not authority.
