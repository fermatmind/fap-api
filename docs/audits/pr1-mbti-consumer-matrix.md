# PR-1 MBTI Consumer Matrix

## Matrix Scope

- Backend public consumers:
  - `v0.3 result`
  - `v0.3 report`
  - `v0.3 share`
  - `v0.5 personality`
  - `v0.5 seo`
  - backend personality sitemap
- Frontend public consumers:
  - `fap-web /result/[id]`
  - `fap-web /share/[id]`
  - `fap-web /og/share/[id]`
  - `fap-web /personality/[type]`
  - `fap-web next-sitemap`

## Consumer Matrix

| Surface | Controller / route | Service / serializer / adapter | Frontend consumer | `type_code` status | `base_type_code` / `variant` status | Fallback present | Current attachment-structure capacity |
| --- | --- | --- | --- | --- | --- | --- | --- |
| `v0.3 result` | `AttemptReadController::result` `backend/app/Http/Controllers/API/V0_3/AttemptReadController.php:68-210` | raw `results.result_json` compat wrapper | `ResultClient` fallback path `fap-web/app/(localized)/[locale]/(app)/result/[id]/ResultClient.tsx:197-245` | keeps `5`-char `type_code` if result row has it | no explicit `base_type_code`; no explicit A/T field | Yes | `heroSummary` only, no `lettersIntro`, no structured `traitOverview/career/growth/relationships`, no premium teaser |
| `v0.3 report` | `AttemptReadController::report` `backend/app/Http/Controllers/API/V0_3/AttemptReadController.php:213-260` | `ReportGatekeeper -> ReportComposer -> ReportPayloadAssembler` | `RichResultReport` path via `ResultClient` `fap-web/app/(localized)/[locale]/(app)/result/[id]/ResultClient.tsx:317-319` | keeps `5`-char `type_code` | no explicit `base_type_code`; access `variant` means free/full, not A/T | Limited authored fallbacks only | Can carry rich narrative cards, identity layers, recommended reads; does not match attachment block structure one-to-one yet |
| `v0.3 share` | `ShareController::getShare/getShareView` `backend/app/Http/Controllers/API/V0_3/ShareController.php:40-58` | `ShareFlowService -> ShareService::buildShareSummary()` | `ShareClient` + `MbtiShareSummaryCard` | outward `type_code` keeps `5` chars | internal public-profile lookup strips to base type; no outward `base_type_code` or `variant` field | Yes | Only lightweight summary, tags, rarity, dimensions, CTA; cannot carry attachment sections |
| `v0.5 personality` | `PersonalityController::show` `backend/app/Http/Controllers/API/V0_5/Cms/PersonalityController.php:63-90` | `PersonalityProfileService` + section payload | `fap-web/app/(localized)/[locale]/personality/[type]/page.tsx:98-228` | only `4`-char base `type_code` today | no `base_type_code`; no A/T field | Page-level empty-state fallback if sections absent | Partial hero and section list only; no attachment-aligned canonical blocks |
| `v0.5 seo` | `PersonalityController::seo` `backend/app/Http/Controllers/API/V0_5/Cms/PersonalityController.php:92-114` | `PersonalityProfileSeoService` | Next metadata on personality page | no direct type identity fields in payload | N/A | Yes | SEO only; cannot carry narrative modules |
| Backend personality sitemap | internal service only | `SitemapGenerator::getPersonalityUrls()` `backend/app/Services/SEO/SitemapGenerator.php:213-260` | consumed by backend sitemap generation, not `fap-web` | none | N/A | No | URL enumeration only |
| `fap-web /result/[id]` | page route `fap-web/app/(localized)/[locale]/(app)/result/[id]/page.tsx:1-33` | `fetchAttemptReport` then `fetchAttemptResult` `fap-web/lib/api/v0_3.ts:1332-1373` | actual result page | prefers report `type_code`; fallback uses result `type_code` | no parsed `base_type_code` / `variant` | Yes | Rich report when available; otherwise collapses to simple result summary + dimensions |
| `fap-web /share/[id]` | page route `fap-web/app/(localized)/[locale]/share/[id]/page.tsx:29-66` | `getShareSummary` + local metadata copy builder | `ShareClient` and `MbtiShareSummaryCard` | uses share payload `type_code` if present | no explicit parsed `base_type_code`; no A/T field | Yes | Lightweight share only; no attachment modules |
| `fap-web /og/share/[id]` | OG route `fap-web/app/og/share/[id]/route.tsx:21-32` | `renderShareOgImage()` local formatter | social preview only | uses share payload `type_code` | no explicit `base_type_code` / `variant` | Yes | OG snapshot only |
| `fap-web /personality/[type]` | page route `fap-web/app/(localized)/[locale]/personality/[type]/page.tsx:42-228` | `getPersonalityProfileBySlugOrType` + `getPersonalitySeoBySlugOrType` + `normalizePersonalitySeoPayload` | personality detail page | base-type only | no `base_type_code`; no A/T field | Yes | CMS sections only; attachment modules cannot land without schema/renderer work |
| `fap-web next-sitemap` | `fap-web/next-sitemap.config.js:158-183` | local static path builder | sitemap build only | no MBTI type identity | N/A | N/A | personality/share/result are intentionally excluded |

## Attachment Module Capacity by Consumer

Legend:

- `Yes`: can already carry the module without schema change
- `Partial`: can carry an approximate equivalent only
- `No`: cannot carry without schema/serializer/render work

| Surface | `heroSummary` | `lettersIntro` | `traitOverview` | `career` | `growth` | `relationships` | `premium teaser` |
| --- | --- | --- | --- | --- | --- | --- | --- |
| `v0.3 result` | Partial | No | No | No | No | No | No |
| `v0.3 report` | Partial | Partial | Partial | Partial | Partial | Partial | Partial |
| `v0.3 share` | Partial | No | No | No | No | No | No |
| `v0.5 personality` | Partial | No | No | Partial | Partial | Partial | No |
| `v0.5 seo` | No | No | No | No | No | No | No |
| Backend personality sitemap | No | No | No | No | No | No | No |
| `fap-web /result/[id]` | Partial | No | No | No on fallback path, Partial on report path | No on fallback path, Partial on report path | No on fallback path, Partial on report path | No |
| `fap-web /share/[id]` | Partial | No | No | No | No | No | No |
| `fap-web /og/share/[id]` | Partial | No | No | No | No | No | No |
| `fap-web /personality/[type]` | Partial | No | No | Partial | Partial | Partial | No |

## Current Frontend Consumer Notes

### `fap-web /result/[id]`

- Entry: `fap-web/app/(localized)/[locale]/(app)/result/[id]/ResultClient.tsx:219-245`
- Behavior:
  - first tries `v0.3 report`
  - falls back to `v0.3 result`
  - fallback UI is explicitly simple: `ResultSummary + DimensionBars`
- Contract proof:
  - `fap-web/tests/contracts/result-client-view-state.contract.test.tsx:182-237`

### `fap-web /share/[id]`

- Page metadata comes from local helper:
  - `fap-web/app/(localized)/[locale]/share/[id]/page.tsx:36-55`
  - `fap-web/lib/og/mbtiShare.tsx:155-173`
- Share card accepts multiple incompatible upstream shapes:
  - `fap-web/components/share/MbtiShareSummaryCard.tsx:142-214`
- Contract proof:
  - `fap-web/tests/contracts/mbti-share-consumer.contract.test.tsx:134-157`
  - `fap-web/tests/contracts/mbti-share-consumer.contract.test.tsx:251-323`

### `fap-web /personality/[type]`

- Page reads CMS profile + CMS SEO separately:
  - `fap-web/app/(localized)/[locale]/personality/[type]/page.tsx:49-60`
  - `fap-web/app/(localized)/[locale]/personality/[type]/page.tsx:105-128`
- No local MBTI constant fallback remains in this repo:
  - `fap-web/tests/contracts/personality-cleanup.contract.test.ts:33-42`
- But the page is still locked to the base-type CMS chain

## Current Backend Narrative-Carrying Files

### Report narrative chain

- Controller: `backend/app/Http/Controllers/API/V0_3/AttemptReadController.php`
- Gatekeeper: `backend/app/Services/Report/ReportGatekeeper.php`
- Composer: `backend/app/Services/Report/ReportComposer.php`
- Assembler:
  - `backend/app/Services/Report/Composer/ReportPayloadAssembler.php`
  - `backend/app/Services/Report/Composer/ReportPayloadAssemblerProfilesTrait.php`
  - `backend/app/Services/Report/Composer/ReportPayloadAssemblerComposeBuildTrait.php`
  - `backend/app/Services/Report/Composer/ReportPayloadAssemblerComposeFinalizeTrait.php`

### CMS narrative chain

- Controller: `backend/app/Http/Controllers/API/V0_5/Cms/PersonalityController.php`
- Service: `backend/app/Services/Cms/PersonalityProfileService.php`
- SEO builder: `backend/app/Services/Cms/PersonalityProfileSeoService.php`
- Models:
  - `backend/app/Models/PersonalityProfile.php`
  - `backend/app/Models/PersonalityProfileSection.php`
  - `backend/app/Models/PersonalityProfileSeoMeta.php`
- Import normalizer:
  - `backend/app/PersonalityCms/Baseline/PersonalityBaselineNormalizer.php`

## Consumer Matrix Conclusion

Today there is no surface where:

- `5`-char `type_code` is the first-class identity
- `base_type_code` is only derived
- attachment blocks can render end-to-end
- `share / seo / og / sitemap` are all downstream consumers of the same canonical payload

PR-1 therefore needs to start below the public API level:

- canonical identity helper
- canonical section registry
- canonical payload-builder interface
- red-flag tests

and must not start with frontend wiring or content import.
