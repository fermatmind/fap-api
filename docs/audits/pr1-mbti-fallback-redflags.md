# PR-1 MBTI Fallback Red Flags

## Red-Flag Standard

The following patterns are blocking for a production MBTI public-result launch:

- `5`-char `type_code` is not treated as the primary identity
- `base_type_code` silently replaces `type_code`
- share / seo / og / sitemap do not read from the same canonical payload
- frontend performs copy-authoring or fallback assembly
- attachment-style `getProfile()` fallback logic leaks into public authority design

## RF-01 `v0.3 share` silently strips `-A/-T` before CMS lookup

- File: `backend/app/Services/V0_3/ShareService.php`
- Code location: `330-362`
- Current behavior:
  - `resolvePublicProfile()` calls `baseTypeCode($typeCode)`
  - `baseTypeCode()` runs `preg_replace('/-[A-Z]$/', '', $typeCode)`
  - the share summary then queries CMS by base type only
- Why this blocks launch:
  - `ENFJ-T` and `ENFJ-A` can no longer attach to different authoritative public copy
  - share becomes a hybrid of `5`-type result data and `4`-type CMS copy

## RF-02 CMS personality model only authorizes `16` base types

- File: `backend/app/Models/PersonalityProfile.php`
- Code location: `19-36`
- Related file: `backend/app/Filament/Ops/Resources/PersonalityProfileResource.php`
- Code location: `422-427`
- Current behavior:
  - `PersonalityProfile::TYPE_CODES` enumerates only `INTJ ... ESFP`
  - Filament type dropdown is built directly from that same list
- Why this blocks launch:
  - public CMS cannot store `ENFJ-T`, `ENFJ-A`, `INFP-T`, `INTJ-T`, or any other variant row
  - `v0.5 personality`, `seo`, and personality sitemap cannot ever align with `v0.3 report`

## RF-03 Baseline import rejects variant types and future canonical sections

- File: `backend/app/PersonalityCms/Baseline/PersonalityBaselineNormalizer.php`
- Code location:
  - `116-126` invalid `type_code`
  - `181-239` section key and render-variant validation
  - `295-316` selected type validation
- Current behavior:
  - import accepts only `PersonalityProfile::TYPE_CODES`
  - sections must be inside the current simple list:
    - `core_snapshot`
    - `strengths`
    - `growth_edges`
    - `work_style`
    - `relationships`
    - `communication`
    - `stress_and_recovery`
    - `career_fit`
    - `faq`
    - `related_content`
- Why this blocks launch:
  - attachment structure cannot be migrated as-is
  - PR-2+ import cannot land until the schema scaffold exists

## RF-04 Current CMS source is an old frontend-derived simple version

- File: `backend/content_baselines/personality/mbti.zh-CN.json`
- Code location: `2-7`, sample profile `11-166`
- Current behavior:
  - `meta.source` is `fap-web careerRecommendationProfiles + personality.ts deterministic transforms`
  - sample rows contain only base types and simple sections
- Why this blocks launch:
  - current CMS body is not the authored narrative authority
  - current personality pages and SEO are structurally behind the attachment content

## RF-05 Section registry cannot carry attachment modules

- File: `backend/app/Models/PersonalityProfileSection.php`
- Code location: `15-36`
- Related file: `backend/app/Filament/Ops/Resources/PersonalityProfileResource/Support/PersonalityWorkspace.php`
- Code location: `25-108`
- Current behavior:
  - registry still models the simple section families
  - render variants are only `rich_text`, `bullets`, `cards`, `faq`, `links`, `callout`
- Why this blocks launch:
  - there is no canonical place for:
    - `lettersIntro`
    - `traitOverview.dimensions`
    - layered `career`
    - layered `growth`
    - layered `relationships`
    - premium teaser blocks

## RF-06 SEO is not built from the same authority as the report

- File: `backend/app/Services/Cms/PersonalityProfileSeoService.php`
- Code location: `15-52`
- Current behavior:
  - SEO text falls back through `seo_meta -> profile.title -> profile.excerpt -> profile.subtitle`
  - OG/Twitter text follows the same CMS-first fallback chain
- Why this blocks launch:
  - SEO/OG copy can diverge from report-authoritative narrative copy
  - even after content import, SEO can still lag unless canonical payload ownership is unified

## RF-07 Backend personality sitemap only publishes the simple CMS chain

- File: `backend/app/Services/SEO/SitemapGenerator.php`
- Code location: `213-260`
- Current behavior:
  - personality URLs are built entirely from `personality_profiles`
  - only published public indexable CMS rows are emitted
- Why this blocks launch:
  - sitemap will stay `16`-type simple version while the report chain is already `32`-type
  - search engines cannot see the eventual canonical variant coverage from the current authority setup

## RF-08 `v0.3 /scales/sitemap-source` is not a personality authority source

- File: `backend/app/Http/Controllers/API/V0_3/ScalesSitemapSourceController.php`
- Code location: `18-66`
- Current behavior:
  - endpoint reads only `scales_registry`
  - returns scale slug/indexability metadata only
- Why this blocks launch:
  - there is no shared public sitemap payload for MBTI personality/result authority
  - public sitemap responsibilities are already split between unrelated systems

## RF-09 Share page metadata is locally recomposed on the frontend

- File: `fap-web/app/(localized)/[locale]/share/[id]/page.tsx`
- Code location: `36-55`
- Related file: `fap-web/lib/og/mbtiShare.tsx`
- Code location: `130-173`
- Current behavior:
  - metadata title/description are rebuilt from share summary fields locally
  - local helper truncates, filters, and reorders copy
- Why this blocks launch:
  - frontend remains a text-assembly layer for public MBTI copy
  - metadata can drift from the future backend canonical payload

## RF-10 Share OG is also locally authored at render time

- File: `fap-web/app/og/share/[id]/route.tsx`
- Code location: `10-32`
- Related file: `fap-web/lib/og/mbtiShare.tsx`
- Code location: `175-307`
- Current behavior:
  - the OG image uses share payload as input
  - but narrative, rarity, tag, dimension detail, CTA label, and truncation logic are local
- Why this blocks launch:
  - share OG is not a pure rendering of backend-authored copy
  - later share payload changes could still be reinterpreted differently by the frontend

## RF-11 Share card consumes multiple incompatible shapes and hides payload drift

- File: `fap-web/components/share/MbtiShareSummaryCard.tsx`
- Code location: `142-214`
- Current behavior:
  - consumer tries `root`, `result`, `profile`, `identity_card`, `report`, `summary_card`
  - it normalizes whichever fields happen to exist
- Why this blocks launch:
  - public consumer is not locked to a single canonical payload contract
  - backend divergence can survive unnoticed because the frontend will patch over it

## RF-12 Result page still has a simple-version fallback mode

- File: `fap-web/app/(localized)/[locale]/(app)/result/[id]/ResultClient.tsx`
- Code location:
  - `197-217`
  - `219-245`
  - `317-334`
- Contract proof: `fap-web/tests/contracts/result-client-view-state.contract.test.tsx:182-237`
- Current behavior:
  - if report is unavailable or not renderable, page falls back to `v0.3 result`
  - fallback UI renders only `ResultSummary` and `DimensionBars`
- Why this blocks launch:
  - public result page can still collapse to a simple version
  - attachment-style modular narrative is not guaranteed on the public result route

## RF-13 Attachment source itself contains forbidden authority fallback logic

- File: `/Users/rainie/Desktop/微信小程序结果页文案.txt`
- Code location: `13648-13701`
- Current behavior:
  - local `MBTI_PROFILES` constant holds all `32` authored records
  - `getProfile(rawType)` silently degrades `4`-char codes to `-T` then `-A`
  - final fallback hard-defaults to `ENFJ-T`
- Why this blocks launch:
  - this is acceptable only as an attachment source to be transformed
  - it cannot be copied into backend public authority semantics

## RF-14 `v0.5 personality` does not preserve `5`-char identity even at lookup time

- File: `backend/app/Services/Cms/PersonalityProfileService.php`
- Code location: `28-63`
- Current behavior:
  - lookup only lowercases the slug and uppercases the provided type
  - no variant-aware identity helper exists
  - because model rows are base-type only, `ENFJ-T` paths simply miss
- Why this blocks launch:
  - `5`-char type identity is not a first-class public key
  - canonical variant pages cannot be introduced safely without a shared helper

## Red-Flag Summary

The blocking pattern is consistent across the stack:

- report is `32`-type and authored
- personality/seo/sitemap are `16`-type and CMS-simple
- share sits between them and downgrades type identity internally
- frontend metadata/OG/share rendering still performs protective copy assembly

PR-1 must therefore target infrastructure, not content:

- canonical identity helper
- canonical section registry
- canonical payload-builder interface
- explicit red-flag tests that ban silent fallback in new code
