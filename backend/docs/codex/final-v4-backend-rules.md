# Final V4 Backend Rules

## Purpose

This document syncs the backend repository with the FermatMind Final V4 upgrade plan. It defines backend responsibilities for content authority, runtime fallback support, media ownership, import validation, and product priority.

## Backend Authority

CMS/backend is the source of truth for publishable content and mutable media metadata. Frontend applications render, cache, and interact with content, but they must not become the editorial source of truth.

Backend-owned surfaces include:

- Articles, article SEO, covers, categories/tags, related placement, and publication state.
- `landing_surfaces` / `page_blocks` for homepage, tests hub, test category pages, career center modules, CTA text, module ordering, featured items, and landing SEO.
- `content_pages` for help, policy, company, brand, careers, about, charter, foundation, privacy, terms, refund, support, and similar pages.
- Career guides, career jobs, career recommendations, personality profiles, topics, FAQ, sections, and SEO.
- Media Library metadata and variants for mutable editorial, marketing, social, article, landing page, and SEO images.

## Baseline Protocol

`content_baselines` may be retained only for:

- New environment initialization.
- DB clearing recovery.
- Baseline imports.
- Disaster recovery.
- Dry-run validation.

They must not be queried as runtime page-rendering authority. Runtime content should flow through CMS resources, public APIs, and cache layers.

## Import Validation Protocol

Large content imports must be schema-validated and dry-run before import.

Required validation areas:

- Career DOCX to JSON/DB conversion.
- Slugs.
- Sections.
- SEO fields.
- Media references.
- Publication state.

Importers should fail closed on schema drift and produce reviewer-readable diagnostics before mutating production data.

## Media Protocol

Mutable operational media must flow through Media Library and generated variants.

Public API media payloads should expose managed metadata, including alt, dimensions, variants, captions, credits, and SEO/social image references where relevant.

Public APIs must not emit historical Tencent/COS URLs or unmanaged raw external URLs for CMS-backed surfaces.

## Runtime Fallback Support

Backend APIs should support frontend fallback behavior in this order:

1. Fresh CMS/API content.
2. Stale last-known-good cached content.
3. Minimal shell or explicit empty/error state.

Backend changes should not require the frontend to carry full hardcoded editorial fallback copy.

## Priority And Resource Isolation

Business priority is fixed:

- L1: MBTI.
- L2: Big Five.
- L3: SBTI, articles, topics, career recommendations, and non-core tests.

Backend rate limits, cache refreshes, FPM/API resource isolation, and degradation behavior must protect this ordering.

Long-term resource isolation should separate:

- Lookup/questions read paths.
- Auth/start/submit/result write paths.
- Non-core CMS/API paths.

## Review Requirement

Any PR changing CMS resources, public content APIs, content ownership, media handling, import behavior, SEO generation, sitemap or `llms.txt` enumeration, publishing workflow, or runtime fallback support must include a `Repository rule impact` note.

The note must classify changed surfaces as CMS/backend-authoritative, frontend product-code-only, temporary migration fallback, or deprecated.
