# PR-1 MBTI File Change Plan

## PR-1 Scope Lock

PR-1 only does this:

- authority收口骨架
- canonical schema scaffold
- type identity helper
- section key registry
- public payload builder interface
- red-flag test

PR-1 explicitly does not do this:

- full `32`-type import
- frontend wiring
- `share / seo / og / sitemap` traffic switch
- public API contract switch
- scoring change
- business-rule change
- other scale result-page work

## Minimum Implementable Change Surface

### New skeleton files to add

- `backend/app/Support/Mbti/MbtiTypeIdentity.php`
  - Purpose: normalize and validate `5`-char MBTI identity
  - Must expose:
    - `type_code`
    - derived `base_type_code`
    - derived `variant`
    - `isVariantType()`
    - no silent default
    - no silent downgrade
- `backend/app/Support/Mbti/MbtiCanonicalSectionRegistry.php`
  - Purpose: define canonical section keys for the future unified payload
  - Must include future block families:
    - top / hero
    - letters intro
    - trait overview
    - career
    - growth
    - relationships
    - premium teaser
    - seo/meta references
- `backend/app/Support/Mbti/MbtiCanonicalSchema.php`
  - Purpose: document canonical payload field ownership in code
  - Must separate:
    - `profile`
    - `sections`
    - `seo_meta`
    - `premium_teaser`
- `backend/app/Contracts/MbtiPublicPayloadBuilder.php`
  - Purpose: one interface for future public payload builders
  - Must not be wired to live endpoints yet
- `backend/app/Services/Mbti/MbtiPublicPayloadBuilderScaffold.php`
  - Purpose: non-live scaffold implementation for tests and later adapters
  - Should accept a typed identity object, not a raw string

### Tests to add in PR-1

- `backend/tests/Unit/Mbti/MbtiTypeIdentityTest.php`
  - Verifies:
    - `ENFJ-T` remains primary identity
    - `base_type_code` is derived only
    - blank or invalid types do not default
    - no helper method silently rewrites `ENFJ-T` to `ENFJ`
- `backend/tests/Unit/Mbti/MbtiCanonicalSectionRegistryTest.php`
  - Verifies:
    - canonical section keys exist
    - future attachment modules have a registry slot
    - current simple keys can be mapped intentionally, not implicitly
- `backend/tests/Unit/Mbti/MbtiCanonicalSchemaTest.php`
  - Verifies:
    - field ownership is frozen for `profile`, `sections`, `seo_meta`, `premium_teaser`
- `backend/tests/Unit/Mbti/MbtiPublicPayloadBuilderContractTest.php`
  - Verifies:
    - builder interface accepts a typed identity object
    - builder contract returns a canonical payload shell, not live legacy payloads
- `backend/tests/Feature/Mbti/MbtiAuthorityRedFlagTest.php`
  - Verifies new PR-1 helpers do not permit:
    - silent base-type fallback
    - default type injection
    - attachment-style `getProfile()` semantics

## Files PR-1 Should Not Change

### Backend files to leave untouched in PR-1

- `backend/routes/api.php`
- `backend/database/migrations/*`
- `backend/app/Http/Controllers/API/V0_3/AttemptReadController.php`
- `backend/app/Http/Controllers/API/V0_3/ShareController.php`
- `backend/app/Services/V0_3/ShareService.php`
- `backend/app/Http/Controllers/API/V0_5/Cms/PersonalityController.php`
- `backend/app/Services/Cms/PersonalityProfileSeoService.php`
- `backend/app/Services/SEO/SitemapGenerator.php`
- `backend/app/PersonalityCms/Baseline/PersonalityBaselineImporter.php`
- `backend/app/PersonalityCms/Baseline/PersonalityBaselineNormalizer.php`
- `backend/content_baselines/personality/*`
- `content_packages/default/**`

### Frontend files to leave untouched in PR-1

- `fap-web/app/**`
- `fap-web/components/**`
- `fap-web/lib/**`
- `fap-web/tests/**`
- `fap-web/next-sitemap.config.js`

## Files That Are Skeleton-Only in PR-1

These files are allowed to be added in PR-1, but they must not yet receive live route traffic:

- `backend/app/Support/Mbti/MbtiTypeIdentity.php`
- `backend/app/Support/Mbti/MbtiCanonicalSectionRegistry.php`
- `backend/app/Support/Mbti/MbtiCanonicalSchema.php`
- `backend/app/Contracts/MbtiPublicPayloadBuilder.php`
- `backend/app/Services/Mbti/MbtiPublicPayloadBuilderScaffold.php`

That means PR-1 does not yet:

- rewire `ShareService`
- rewire `PersonalityController`
- rewire sitemap generation
- change `v0.3` or `v0.5` response bodies

## External APIs That Must Not Switch in PR-1

- `GET /api/v0.3/attempts/{id}/result`
- `GET /api/v0.3/attempts/{id}/report`
- `GET|POST /api/v0.3/attempts/{id}/share`
- `GET /api/v0.3/shares/{id}`
- `GET /api/v0.5/personality`
- `GET /api/v0.5/personality/{type}`
- `GET /api/v0.5/personality/{type}/seo`
- backend sitemap output
- frontend share OG route

## Why This Is The Minimum Safe PR-1

Because the current live system has three authorities at once:

- content-pack report authority
- CMS personality/SEO authority
- frontend local share/OG copy composition

If PR-1 jumps directly to import, frontend wiring, or route switching, it will either:

- preserve the current divergence under a bigger schema, or
- ship a partial chain where some surfaces still downgrade to base type

PR-1 has to create the shared vocabulary first.

## A. Changed Files List

### Added

- `backend/app/Support/Mbti/MbtiTypeIdentity.php`
- `backend/app/Support/Mbti/MbtiCanonicalSectionRegistry.php`
- `backend/app/Support/Mbti/MbtiCanonicalSchema.php`
- `backend/app/Contracts/MbtiPublicPayloadBuilder.php`
- `backend/app/Services/Mbti/MbtiPublicPayloadBuilderScaffold.php`
- `backend/tests/Unit/Mbti/MbtiTypeIdentityTest.php`
- `backend/tests/Unit/Mbti/MbtiCanonicalSectionRegistryTest.php`
- `backend/tests/Unit/Mbti/MbtiCanonicalSchemaTest.php`
- `backend/tests/Unit/Mbti/MbtiPublicPayloadBuilderContractTest.php`
- `backend/tests/Feature/Mbti/MbtiAuthorityRedFlagTest.php`

### Modified

- None required for PR-1 if the scaffold remains off the live path

## B. Copy-Paste Blocks

```text
[ADD FILE] backend/app/Support/Mbti/MbtiTypeIdentity.php
Insertion position: whole new file

[ADD FILE] backend/app/Support/Mbti/MbtiCanonicalSectionRegistry.php
Insertion position: whole new file

[ADD FILE] backend/app/Support/Mbti/MbtiCanonicalSchema.php
Insertion position: whole new file

[ADD FILE] backend/app/Contracts/MbtiPublicPayloadBuilder.php
Insertion position: whole new file

[ADD FILE] backend/app/Services/Mbti/MbtiPublicPayloadBuilderScaffold.php
Insertion position: whole new file

[ADD FILE] backend/tests/Unit/Mbti/MbtiTypeIdentityTest.php
Insertion position: whole new file

[ADD FILE] backend/tests/Unit/Mbti/MbtiCanonicalSectionRegistryTest.php
Insertion position: whole new file

[ADD FILE] backend/tests/Unit/Mbti/MbtiCanonicalSchemaTest.php
Insertion position: whole new file

[ADD FILE] backend/tests/Unit/Mbti/MbtiPublicPayloadBuilderContractTest.php
Insertion position: whole new file

[ADD FILE] backend/tests/Feature/Mbti/MbtiAuthorityRedFlagTest.php
Insertion position: whole new file
```

## C. Acceptance Commands

```bash
cd /Users/rainie/Desktop/GitHub/fap-api/backend && php artisan route:list

cd /Users/rainie/Desktop/GitHub/fap-api/backend && php artisan migrate

curl "http://127.0.0.1:8000/api/v0.3/attempts/$ATTEMPT_ID/result"
curl -H "Authorization: Bearer $FM_TOKEN" -H "X-Anon-Id: $ANON_ID" \
  "http://127.0.0.1:8000/api/v0.3/attempts/$ATTEMPT_ID/share"
curl "http://127.0.0.1:8000/api/v0.5/personality/enfj?locale=zh-CN"
curl "http://127.0.0.1:8000/api/v0.5/personality/enfj/seo?locale=zh-CN"

cd /Users/rainie/Desktop/GitHub/fap-api && bash backend/scripts/ci_verify_mbti.sh
```

## PR-1 Exit Criteria

PR-1 is complete only if all of the following are true:

- a single typed identity helper exists
- a canonical section registry exists
- a canonical payload-builder interface exists
- scaffold classes are covered by tests
- no public route is switched yet
- no frontend route is switched yet
- no import path is switched yet
