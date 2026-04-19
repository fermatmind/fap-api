# Contributing

This repository follows the Night PR Train rules in `AGENTS.md`. Keep pull requests scoped, start from the latest `main`, run the manifest checks before push, and record PR train state transitions when a task is part of the train.

## Content Authority

FermatMind publishable content is CMS/backend-authoritative. Backend resources and APIs own operational content, SEO metadata, publishing state, and mutable media references.

Backend-owned content includes:

- Articles, article SEO, covers, categories, tags, related placement, and publication state.
- Homepage, tests hub, test category pages, career center modules, CTA text, ordering, featured items, and landing SEO through `landing_surfaces` / `page_blocks`.
- Help, policy, company, brand, careers, about, charter, foundation, privacy, terms, refund, support, and similar static pages through `content_pages`.
- Career guides, career jobs, career recommendations, personality profiles, topics, FAQ, sections, and SEO through CMS resources and public APIs.
- Mutable editorial, marketing, social, article, landing page, and SEO media through Media Library.

Do not introduce runtime content authority in frontend repositories or ad hoc local file sources. Public APIs should expose CMS/backend content and media metadata in a shape the frontend can render and cache.

## Baselines And Imports

`content_baselines` are allowed only for:

- New environment initialization.
- Recovery after DB clearing.
- Baseline content imports.
- Disaster recovery.
- Dry-run validation.

They must not be used as runtime page-rendering authority.

Large imports must validate schema and support dry-run before import, especially career DOCX conversion, slugs, sections, SEO fields, media references, and publication state.

## Media

Mutable media assets must flow through Media Library and generated variants. Public APIs must not emit historical Tencent/COS URLs or unmanaged raw external image URLs for CMS-backed surfaces.

Media metadata should include alt text, dimensions, variants, caption, credit, and SEO/social image references when required by the consuming surface.

## Runtime And Priority

Backend APIs should support a frontend fallback order of CMS/API content, stale last-known-good cache, then minimal shell. Do not require frontend hardcoded editorial copy for operational fallback.

Business priority is fixed:

- L1: MBTI.
- L2: Big Five.
- L3: SBTI, articles, topics, career recommendations, and non-core tests.

Throttle buckets, API resource isolation, cache refresh, and degradation behavior must preserve this priority.

## PR Requirements

Any PR that changes content ownership, CMS resources, public content APIs, media handling, SEO generation, sitemap or `llms.txt` enumeration, publishing workflow, importer behavior, or fallback behavior must include a `Repository rule impact` note in the PR body.

The note must state whether the changed surface is:

- CMS/backend-authoritative.
- Frontend product-code-only.
- Temporary migration fallback.
- Deprecated.

Temporary migration fallbacks must include an owner, removal condition, and target removal PR or issue.
