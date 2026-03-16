# PR-1 MBTI Authority Special Scan

## Scope

- Scan mode: read-only
- Allowed changes in this round: audit markdown only
- Forbidden in this round: business code, tests, scripts, config, migrations, routes, frontend components
- Workspaces scanned:
  - `fap-api` at `0748c24798cbbfdbaf8a3d12b450ac42593fb912`
  - `fap-web` at `767b538ebea78f3e572f237db6291a0d69ac6aa2`

## Command Baseline

### `fap-api`

- `git checkout main`: already on `main`
- `git pull --ff-only origin main`: already up to date
- `git rev-parse HEAD`: `0748c24798cbbfdbaf8a3d12b450ac42593fb912`
- `git status --short --branch`: `## main...origin/main`
- Worktree note: existing unrelated modified compiled content-pack files and untracked docs directories were already present before this scan

### `fap-web`

- `git checkout main`: already on `main`
- `git pull --ff-only origin main`: already up to date
- `git rev-parse HEAD`: `767b538ebea78f3e572f237db6291a0d69ac6aa2`
- `git status --short --branch`: `## main...origin/main`

### Attachment

- `/Users/rainie/Desktop/微信小程序结果页文案.txt` contains `32` authored objects under `MBTI_PROFILES` and a `getProfile(rawType)` fallback that:
  - accepts `5`-char codes first
  - silently degrades `4`-char codes to `-T` or `-A`
  - hard-defaults to `ENFJ-T`
- Evidence: `/Users/rainie/Desktop/微信小程序结果页文案.txt:13648-13701`

## Current Authority Total Graph

```text
MBTI public surfaces today are split across three different source systems:

1. v0.3 result/report/share
   routes/api.php
   -> AttemptReadController / ShareController
   -> Result rows + ReportComposer + ShareService
   -> content_packages/default/.../MBTI-CN-v0.3/*

2. v0.5 personality/seo
   routes/api.php
   -> PersonalityController
   -> PersonalityProfileService / PersonalityProfileSeoService
   -> personality_profiles + personality_profile_sections + personality_profile_seo_meta
   -> imported from content_baselines/personality/mbti.*.json

3. frontend share/og/metadata presentation
   fap-web app routes
   -> getShareSummary / getPersonalityProfileBySlugOrType / getPersonalitySeoBySlugOrType
   -> local metadata and OG copy builders
   -> local render-time fallback/normalization
```

## Data Chain by Surface

### 1. `v0.3 result`

- Route: `backend/routes/api.php:163-165`
- Controller: `backend/app/Http/Controllers/API/V0_3/AttemptReadController.php:68-210`
- Source:
  - `results.result_json`
  - `results.type_code`
  - `results.scores_json`
  - `results.scores_pct`
- Identity behavior:
  - keeps `type_code` as stored on the result row
  - does not expose `base_type_code`
  - does not expose a dedicated `variant` field
- Narrative shape:
  - simple payload only
  - frontend fallback surface reads only `summary + dimensions`

### 2. `v0.3 report`

- Route: `backend/routes/api.php:166-168`
- Controller: `backend/app/Http/Controllers/API/V0_3/AttemptReadController.php:213-260`
- Gate/composer:
  - `backend/app/Services/Report/ReportComposer.php:25-82`
  - `backend/app/Services/Report/Composer/ReportPayloadAssemblerComposeBuildTrait.php:84-169`
  - `backend/app/Services/Report/Composer/ReportPayloadAssemblerComposeFinalizeTrait.php:50-91`
  - `backend/app/Services/Report/Composer/ReportPayloadAssemblerProfilesTrait.php:41-118`
- Source files:
  - `type_profiles.json`
  - `report_identity_cards.json`
  - `identity_layers.json`
  - `report_recommended_reads.json`
  - section cards generated from pack/store/rules
- Identity behavior:
  - `32`-type authored chain exists
  - `A/T` is preserved in `report.profile.type_code` and in identity layer lookups
- Proof:
  - `backend/tests/Feature/Report/MbtiReportContentEnhancementContractTest.php:23-32`
  - `backend/tests/Feature/Report/MbtiReportContentEnhancementContractTest.php:132-171`

### 3. `v0.3 share`

- Routes:
  - `backend/routes/api.php:173-174`
  - `backend/routes/api.php:220-228`
- Controller/service chain:
  - `backend/app/Http/Controllers/API/V0_3/ShareController.php:40-58`
  - `backend/app/Services/V0_3/ShareFlowService.php:45-76`
  - `backend/app/Services/V0_3/ShareService.php:193-347`
- Actual source mix inside `ShareService::buildShareSummary()`:
  - `results.result_json`
  - free report snapshot from `ReportComposer`
  - `identity_card`
  - `identity layer`
  - `personality_profiles` public profile fallback
- Identity behavior:
  - outward payload keeps `type_code` from the result row
  - internal public-profile lookup strips `-A/-T` with `baseTypeCode()`
  - no dedicated `base_type_code` or `variant` field is emitted
- Proof of downgrade path:
  - `backend/app/Services/V0_3/ShareService.php:330-362`

### 4. `v0.5 personality`

- Route: `backend/routes/api.php:347-349`
- Controller: `backend/app/Http/Controllers/API/V0_5/Cms/PersonalityController.php:63-90`
- Service/model chain:
  - `backend/app/Services/Cms/PersonalityProfileService.php:28-50`
  - `backend/app/Models/PersonalityProfile.php:17-36`
  - `backend/app/Models/PersonalityProfileSection.php:15-36`
- Source:
  - `personality_profiles`
  - `personality_profile_sections`
  - `personality_profile_seo_meta`
- Origin:
  - `backend/content_baselines/personality/mbti.zh-CN.json:2-7`
  - source metadata explicitly says it came from old frontend deterministic transforms
- Identity behavior:
  - only `16` base types exist
  - `ENFJ-T` style lookups do not resolve
  - `A/T` is not modeled

### 5. `v0.5 seo`

- Route: `backend/routes/api.php:348`
- Controller: `backend/app/Http/Controllers/API/V0_5/Cms/PersonalityController.php:92-114`
- Builder:
  - `backend/app/Services/Cms/PersonalityProfileSeoService.php:15-80`
- Source:
  - `personality_profile_seo_meta`
  - fallback to `profile.title`, `profile.excerpt`, `profile.subtitle`
- Identity behavior:
  - tied to `v0.5 personality`, therefore base-type only

### 6. OG

#### Share OG

- Frontend route: `fap-web/app/og/share/[id]/route.tsx:10-32`
- Source:
  - `getShareSummary()` from `v0.3 share`
  - local OG renderer `fap-web/lib/og/mbtiShare.tsx:130-307`
- Behavior:
  - same upstream share payload
  - but title, description, narrative, tag filtering, truncation, and dimension wording are recomposed on the frontend

#### Personality OG

- No dedicated OG image route was found for personality detail
- Current personality page OG comes from Next metadata in:
  - `fap-web/app/(localized)/[locale]/personality/[type]/page.tsx:42-95`
  - `fap-web/lib/cms/personality.ts:407-455`
- Source remains `v0.5 seo`

### 7. Sitemap source

#### `v0.3 /scales/sitemap-source`

- Route: `backend/routes/api.php:143`
- Controller: `backend/app/Http/Controllers/API/V0_3/ScalesSitemapSourceController.php:18-66`
- Source:
  - `scales_registry`
- This endpoint is not a personality narrative authority source

#### Backend personality sitemap

- Generator: `backend/app/Services/SEO/SitemapGenerator.php:213-260`
- Source:
  - `personality_profiles`
- Behavior:
  - emits only current CMS public rows
  - therefore emits only the `16` simple-version personality pages

#### Frontend sitemap

- `fap-web/next-sitemap.config.js:17-33`
- Frontend explicitly excludes:
  - `/share/*`
  - `/result/*`
  - `/compare/*`
  - `/history/*`
- `fap-web/tests/contracts/personality-cleanup.contract.test.ts:25-31` also locks that frontend no longer claims personality detail authority in its own sitemap generation

## Which Chain Preserves `32` Types and `A/T`

| Surface | Preserves `32` types | Preserves `A/T` | Notes |
| --- | --- | --- | --- |
| `v0.3 result` | Yes, if stored on `results.type_code` | Yes, inside `type_code` only | Simple payload |
| `v0.3 report` | Yes | Yes | Current strongest narrative authority |
| `v0.3 share` | Partial | Partial | Public profile fallback strips `-A/-T` internally |
| `v0.5 personality` | No | No | `16` base types only |
| `v0.5 seo` | No | No | Derived from base-type CMS rows |
| Share OG | Depends on `v0.3 share` | Depends on `v0.3 share` | Frontend recomposes copy |
| Backend personality sitemap | No | No | Enumerates `personality_profiles` only |

## Which Chain Loses `A/T`

| Chain | Loss point | Evidence |
| --- | --- | --- |
| `v0.3 share -> public profile lookup` | `baseTypeCode()` strips suffix before `getPublicProfileByType()` | `backend/app/Services/V0_3/ShareService.php:336-362` |
| `v0.5 personality` | Model/type registry has only base types | `backend/app/Models/PersonalityProfile.php:19-36` |
| `v0.5 baseline import` | Normalizer rejects `ENFJ-T` style types | `backend/app/PersonalityCms/Baseline/PersonalityBaselineNormalizer.php:116-126` |
| `backend sitemap personality` | Reads only base-type `personality_profiles` | `backend/app/Services/SEO/SitemapGenerator.php:220-237` |

## Evidence That A Unique Authority Does Not Exist Today

1. Public result/report narrative authority is the content pack chain, not CMS.
   - Evidence: `backend/app/Services/Report/Composer/ReportPayloadAssemblerProfilesTrait.php:41-118`
2. Public personality detail authority is CMS baseline content imported from an old frontend transform, not the report content pack.
   - Evidence: `backend/content_baselines/personality/mbti.zh-CN.json:2-7`
3. Public share is a hybrid object composed from result row, report snapshot, and base-type CMS fallback.
   - Evidence: `backend/app/Services/V0_3/ShareService.php:221-296`
4. Public SEO is generated from CMS `seo_meta` plus local fallback text rules, not from the report payload.
   - Evidence: `backend/app/Services/Cms/PersonalityProfileSeoService.php:15-52`
5. Share page metadata and share OG still do a second frontend-side copy composition pass.
   - Evidence:
     - `fap-web/app/(localized)/[locale]/share/[id]/page.tsx:36-55`
     - `fap-web/lib/og/mbtiShare.tsx:130-173`
6. Personality sitemap enumerates CMS rows only, while report authority lives elsewhere.
   - Evidence: `backend/app/Services/SEO/SitemapGenerator.php:213-260`

## PR-1 Conclusion

Current MBTI public result infrastructure is split like this:

- `v0.3 report` is the only live chain that already behaves like a `32`-type narrative authority.
- `v0.5 personality/seo/sitemap` is still the `16`-type simple-version CMS chain.
- `v0.3 share` is neither fully report-authoritative nor fully CMS-authoritative; it is a hybrid with a silent base-type downgrade.

That means PR-1 cannot be a content-import PR. It must first introduce a shared authority skeleton that can later power:

- `result`
- `report`
- `share`
- `personality`
- `seo`
- `og`
- `sitemap`

without letting `base_type_code` replace the `5`-character `type_code`.
