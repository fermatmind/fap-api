# Fermat Product Marketing Context

Status: draft internal operator skill.

This document adapts the upstream `product-marketing` skill pattern into a FermatMind-specific context draft. It is not an installed Agent Skill, not runtime code, not a CMS content asset, and not authorization to publish, mutate CMS, generate pages, create pSEO, enqueue Search Channel items, or deploy.

## Authority Rules

- Backend, CMS, and URL Truth are the authority layers.
- `fap-web` is the public runtime and renderer, not editorial or SEO truth.
- Frontend fallback is not authority.
- Sitemap and `llms.txt` are discoverability surfaces, not truth.
- Public runtime observation can reveal drift, but it must not become source-of-truth.
- Search Channel action requires exact scoped human approval.
- Draft/import packages are not publishable content until they pass CMS/backend gates and controlled publish.

## FermatMind Positioning

FermatMind is a bilingual assessment and decision-support platform. Its SEO/GEO growth system should move users from search intent into a real assessment loop:

Search landing page -> assessment -> signal -> result -> report preview -> optional paid report -> My Results / email recovery -> next decision support.

FermatMind should be presented as a testing, self-understanding, and decision-support system. It should not be presented as a clinical diagnosis product, a hiring suitability engine, a salary predictor, or a complete personalized career recommender.

## Target Users

- Individuals exploring personality, workstyle, career direction, interests, emotions, and ability signals.
- Students and early-career users looking for structured self-understanding.
- Professionals using assessment results as decision support for workstyle, role exploration, or communication.
- SEO users arriving from assessment, personality, career-interest, IQ/EQ, and research queries.
- Future institutional readers who need methodology, evidence, and claim-safe research assets.

## Product Surfaces

- Public test landing pages.
- Assessment taking flows.
- Result pages.
- Paid report previews and unlock flows.
- My Results and email recovery.
- PDF, email, and share surfaces.
- Topic hubs and articles.
- Personality, trait, interest, and career guide surfaces.
- Research Hub and linkable authority assets.
- URL Truth, Issue Queue, Search Channel Queue, sitemap, `llms.txt`, JSON-LD, and FAQ gates.

## Assessment Families

- MBTI / 16-type personality: high-intent SEO entry and bounded decision-support report surface.
- Big Five / OCEAN: trait-vector and workstyle explanation system.
- RIASEC / Holland Career Interest: real Holland interest assessment and six-dimensional interest vector.
- Enneagram: motivation and personality interpretation surface.
- IQ: online estimate and reasoning-pattern interpretation, not clinical IQ authority.
- EQ: self-assessment and communication/emotion signal surface.
- Clinical/SDS/anxiety/depression screening: self-assessment, non-diagnostic, professional-help bounded.
- Career guides: backend-authoritative career information and exploration surfaces, not full psychometric recommender truth.
- Research Hub: methodology, aggregate analysis, trend reports, and Digital PR/linkable assets.

## SEO/GEO Growth Loops

Primary loop:

1. Public search asset answers a real intent.
2. Runtime CTA sends the user to a relevant assessment.
3. Assessment creates a structured signal.
4. Result page explains the signal with claim boundaries.
5. Paid report preview offers deeper interpretation.
6. Email/My Results preserves recovery and revisit.
7. Search Intelligence attributes page, CTA, test, result, unlock, and purchase behavior.
8. CMS/backend issue queues guide content, claim, and technical fixes.

GEO loop:

1. Research or methodology page exposes clear definitions, evidence, references, and boundaries.
2. JSON-LD/FAQ are grounded in visible backend-authoritative content.
3. `llms.txt` enumerates only eligible public assets.
4. AI/search observations feed SEO Ops; they do not become truth.

## Freemium Result/Report Loop

Correct model:

- Free assessment start.
- Free basic result.
- Paid deeper report preview.
- Unlock CTA.
- Order/payment.
- Report access, PDF/history where supported.
- Email/My Results recovery.

The loop must be measurable by source page, CTA, test slug, result view, unlock click, order, purchase, and revenue. Email and other PII must not enter analytics URLs, public HTML, Search Channel payloads, or external ad conversion payloads.

## Content and CMS Boundaries

- Articles, topics, public content pages, page blocks, career surfaces, media metadata, SEO fields, and publish state belong to backend CMS/public APIs.
- Frontend product code may render, interact, score where appropriate, and adapt layout, but it must not add publishable editorial fallback content.
- Baseline/import fixtures may exist for recovery or dry-run validation; they must not become public runtime authority.

## URL Truth and Search Channel Boundaries

- URL eligibility starts from backend/CMS URL Truth.
- Search Channel Queue must exclude draft, private, noindex, non-canonical, unsupported, claim-unsafe, and fallback-sourced URLs.
- Sitemap/llms exposure defects are P0 discoverability issues, not reasons to create placeholder pages.
- No Search Channel enqueue, live submit, IndexNow, GSC, Baidu, Bing, 360, Sogou, or Shenma action is allowed without exact scoped approval.

## Current Active Blockers

- P0 discoverability cleanup is active for sitemap/llms hard-404 exposure and career job discoverability leakage.
- pSEO remains blocked while discoverability, URL Truth, claim boundaries, and internal link gates are not fully clean.
- Career recommendation semantics remain partial and must not be overclaimed.
- EN/ZH parity and RESULT-EN-PARITY assets are governed by backend authority and fail-closed behavior.

## Current No-go Conditions

- No mass content generation.
- No pSEO generation.
- No auto-publish.
- No CMS mutation.
- No Search Channel enqueue or live submission.
- No frontend fallback as authority.
- No claim override without human review.
- No production user data access.
- No raw production log reads.
- No deploy.

## What FermatMind Is Not

- Not a clinical diagnosis product.
- Not a treatment or cure provider.
- Not a hiring-fit or job-suitability engine.
- Not a salary, income, turnover, or career-success predictor.
- Not a complete personalized career recommender yet.
- Not a pSEO content factory.

## Required Semantic Boundaries

- FermatMind is not a complete personalized career recommender yet.
- RIASEC is a real Holland interest assessment and six-dimensional interest vector, but current runtime is not a completed active RIASEC-to-career recommender.
- Big Five is a real trait-vector system, but not a completed career recommender.
- Career Graph is real backend authority, but psychometric semantic recommendation is partial.
- MBTI career recommendations are bounded decision-support/snapshot surfaces, not guaranteed outcomes.

## Operator Checklist

Before using this context for any marketing, SEO, GEO, CRO, or content task:

- Identify the backend/CMS authority source.
- Identify whether the page is public, draft, private, noindex, or unsupported.
- Identify the assessment family and claim boundary.
- Identify the conversion loop and event attribution.
- Confirm no pSEO, CMS mutation, Search Channel action, deploy, or content generation is implied.
- If the task creates a future PR, require explicit manifest/state authorization.
