# MBTI Content and Internal Link Wave 1 Dry-run Plan

Task: SEO-GROWTH-MBTI-02

Type: docs/generated/test only.

This contract defines the MBTI Content/Internal Link Wave 1 dry-run plan. It does not create content, does not mutate CMS, does not publish articles, does not create links, does not modify fap-web, and does not use crawler/search/referral/frontend/static signals as authority.

## Candidate Wave 1 Assets

- MBTI test page.
- MBTI research report.
- MBTI topic hub, deferred until backend topic authority is explicit.
- 2-3 MBTI explanatory articles, only if backend CMS rows exist.
- selected personality type pages, only after entity authority is confirmed.

## Internal Link Families

- article -> test.
- article -> topic.
- article -> research.
- article -> related article.
- topic -> test.
- topic -> article.
- topic -> personality/entity.
- research -> topic/test/article.
- test -> article/topic/research.
- personality page -> test/topic/article.

## Authority Rules

- backend/CMS entity graph owns link truth.
- fap-web static links are observation only.
- sitemap-derived links are observation only.
- crawler logs cannot create links.
- GSC/GA4/referral signals can suggest opportunities but cannot create links.
- title/slug similarity is migration-helper only.
- `entity_key` is preferred.
- `translation_group_uuid` is preferred when available.

## Required Dry-run Outputs

- source inventory.
- link family counts.
- missing entity key count.
- legacy_unpaired count.
- candidate opportunity count.
- unsafe fallback source count.
- warnings.

## Stop Conditions

Stop if a future implementation attempts CMS writes, link creation, article publish, fap-web changes, crawler-derived authority, search-response authority, frontend fallback authority, pSEO, or auto-link creation.

## Next Task

After this PR merges, continue with `SEO-GROWTH-MBTI-03A｜MBTI Claim Lint Gate`.
