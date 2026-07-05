# Backend Authority Map

Target route: `/zh/tests/holland-career-interest-test-riasec`

## Authority Summary

Future FAQ, TDK, GEO answer-surface, CTA copy, and public SEO changes for this landing page should be treated as backend/CMS-authoritative work. The frontend should remain a renderer/consumer and must not add local editorial fallback content.

## Evidence Map

| Evidence | File / lines | Authority implication |
| --- | --- | --- |
| Scale registry primary slug | `backend/database/seeders/ScaleRegistrySeeder.php:332-359` | `RIASEC` owns primary slug `holland-career-interest-test-riasec`, forms `riasec_60` and `riasec_140`, and default form `riasec_60`. |
| Commercial state | `backend/database/seeders/ScaleRegistrySeeder.php:366-372` | RIASEC is `FREE` / `free_only`; no report unlock SKU is declared. |
| SEO/content i18n | `backend/database/seeders/ScaleRegistrySeeder.php:373-410` | Backend holds zh title/description/excerpt/SEO copy and the 60/140 scale explanation. |
| Sitemap fallback inclusion | `backend/app/Http/Controllers/API/V0_5/SEO/SitemapSourceController.php:31-45` | Backend sitemap source includes `/zh/tests/holland-career-interest-test-riasec`; private fallback paths are filtered separately. |
| GSC gate | `backend/docs/seo/generated/gsc-data-quality-gate.v1.json` | GSC opportunity use remains blocked until live quality gate passes. |
| Opportunity queue contract | `backend/docs/seo/generated/seo-opportunity-queue-readonly.v1.json` | Future seo_intel use must be read-only, private-ops only, and gated by GSC data quality. |
| Repository authority rules | `backend/AGENTS.md` | CMS/backend is the source of truth for publishable content, landing SEO, FAQ, public metadata, and public API resources. |

## fap-api vs fap-web Boundary

`fap-api` / backend authority:

- scale registry and public assessment metadata;
- CMS/landing surface/page block authority;
- FAQ, SEO metadata, public content fields, and publication state;
- sitemap source and URL/discoverability contracts;
- seo_intel/GSC quality-gated read models.

`fap-web` renderer/consumer:

- render public landing content returned by backend/public APIs;
- preserve canonical, JSON-LD, CTA, and display parity from backend data;
- avoid local editorial fallback content for CMS-backed public surfaces.

## Current Authority Decision

`BACKEND_AUTHORITY_CONFIRMED`

No authority ambiguity blocks this diagnostic handoff. Any future copy or metadata repair should be separately authorized in backend/CMS authority scope, not implemented as frontend fallback.
