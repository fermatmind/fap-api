# Fermat AI SEO / GEO

Status: draft internal operator skill.

This document adapts upstream `ai-seo`, `schema`, and `site-architecture` thinking into FermatMind's AI-answer and GEO readiness process. It is not an installed Agent Skill, not runtime code, not a content asset, and not authorization to generate pages, publish content, mutate CMS, enqueue Search Channel items, submit URLs, call search APIs, or deploy.

## Definition

AI SEO / GEO for FermatMind means making backend-authoritative public assets understandable, extractable, citable, and claim-safe for search engines and AI answer systems. It is not a separate content factory and must not create AI-bait pages.

Correct flow:

Backend/CMS authority -> public runtime -> visible answer/evidence blocks -> grounded FAQ/JSON-LD -> eligible sitemap/llms exposure -> observation in Search Intelligence.

## Authority Rules

- Backend/CMS/URL Truth is authority.
- Public runtime is observation only.
- `fap-web` fallback is not authority.
- Sitemap and `llms.txt` are discoverability surfaces, not URL Truth.
- Search Channel action requires exact human approval.
- AI search exposure does not prove ranking, indexing, or truth.
- AI/GEO work must not generate pages or content without authority.

## Relationship to llms.txt and llms-full.txt

`llms.txt` and `llms-full.txt` may help AI agents discover eligible public assets, but they are not truth stores.

Rules:

- `llms-full` content must not expose draft, import, fallback-only, private, noindex, hard-404, or claim-unsafe pages.
- `llms.txt` must reflect backend-approved discoverability, not frontend route existence.
- `llms` exposure gaps are discoverability issues; do not fix them by creating placeholder pages.
- Remove or block exposure at the authority/discoverability layer when P0 leakage is found.

## Relationship to Answer Surface

Answer Surface is the visible, user-facing explanation layer that an AI or crawler can quote. It must be backed by CMS/backend content, report assets, Research assets, or approved public API data.

Answer Surface may include:

- concise definitions.
- visible FAQ.
- methodology blocks.
- result/report interpretation boundaries.
- evidence and citation blocks.
- next-step CTAs.

Do not treat hidden JSON-LD, sitemap entries, or frontend fallback copy as Answer Surface.

## FAQ Grounding

FAQPage must be backed by visible FAQ or backend-authoritative Answer Surface.

No-go:

- no FAQ JSON-LD without visible/authority-backed FAQ.
- no hidden FAQ invented only for schema.
- no FAQ copied from frontend fallback.
- no FAQ for draft/private/noindex pages.

## JSON-LD / Schema Grounding

JSON-LD must match visible or backend-authoritative content.

Allowed schema work:

- verify schema fields match visible page content.
- verify FAQPage mirrors visible FAQ.
- verify Article/Research schema uses approved metadata.
- verify SoftwareApplication/Test schema does not invent ratings, reviews, prices, or claims.

No-go:

- no schema for missing content.
- no fake reviews or aggregate ratings.
- no Product/Medical/Clinical schema that overstates the page.
- no schema to compensate for thin, missing, draft, or fallback-only content.

## Evidence Container

Evidence Container means the page has human-readable grounding, not just markup.

Readiness signals:

- clear definition.
- visible limitations or claim boundary.
- cited source or methodology where needed.
- date/review metadata for Research where available.
- entity relationship to test/topic/article/career/report preview where safe.

Evidence Container readiness is not the same as schema existence.

## Page Family Eligibility

Eligible candidates:

- Test pages.
- Topic pages.
- Article pages.
- Research Hub pages.
- Methodology pages.
- Claim-safe career guide pages.
- Public result/report preview surfaces where backend-approved.

Blocked candidates:

- draft/import packages.
- private result/report pages.
- noindex pages.
- hard-404 pages.
- fallback-only frontend pages.
- claim-unsafe Research, clinical, career, salary, turnover, or IQ pages.

## Research / Salary / Turnover Claim Safety

Research pages must remain:

- methodology-bound.
- aggregate-level.
- directional.
- sample/disclaimer aware.
- explicit about limitations.

No-go:

- no Research claim inflation.
- no individual salary prediction.
- no personal turnover prediction.
- no "MBTI predicts salary/turnover" framing.
- no clinical/career overclaim.
- no causality beyond the study design.

## Extractability Without AI-bait

Use normal people-first structure:

- one clear page purpose.
- concise answer block.
- sequential headings.
- tables only when useful to users.
- visible FAQ where real questions exist.
- citations and references where claims require evidence.
- internal links to relevant tests/topics/reports.

Do not create pages only to target AI snippets. Do not write separate AI-only copy. Do not expand pSEO while P0 discoverability cleanup is dirty.

## Evaluation Checklist

### Page Family Eligibility

- Is page type supported by backend URL Truth?
- Is the page public, published, indexable, canonical, and claim-safe?
- Is it not draft/private/noindex/hard-404/fallback-only?

### Canonical / Robots / Hreflang

- Canonical is backend-approved.
- Robots/indexability state is correct.
- Hreflang does not invent unpublished counterparts.
- Locale counterpart exists through authority, not slug assumption.

### Visible Answer Block

- Page has a clear answer or definition visible to users.
- Answer does not overclaim clinical/career/salary/turnover authority.
- Answer links to a next step where relevant.

### FAQ Grounding

- FAQ is visible or Answer Surface-backed.
- FAQ JSON-LD mirrors visible FAQ.
- FAQ does not include unsupported claims.

### JSON-LD Grounding

- JSON-LD matches visible or backend-authoritative content.
- Schema type matches page family.
- No fake rating/review/offer/medical claims.

### Evidence / Citation Readiness

- Claims have visible support.
- Research has methodology and limitations.
- Clinical/screening pages have non-diagnostic boundaries.

### llms Exposure Check

- Eligible page appears only if backend-approved.
- Draft/import/private/noindex/fallback-only pages are absent.
- Hard-404 exposure is treated as P0.

### Claim Boundary Check

- RIASEC, Big Five, MBTI, IQ, clinical, career, salary, turnover, and Research claims remain bounded.
- Any unsafe claim becomes NO-GO.

### Sitemap / llms P0 Blocker Check

- If sitemap/llms hard-404 exposure or career discoverability leakage affects this family, stop scaling.
- Do not proceed to pSEO or mass content.

## Final GO / NO-GO

GO only when URL Truth, visible content, FAQ, JSON-LD, evidence, claim boundary, and discoverability exposure are all consistent.

NO-GO for:

- no FAQ JSON-LD without visible/authority-backed FAQ.
- no schema for missing content.
- no Research claim inflation.
- no AI-bait pages.
- no pSEO generation.
- no Search Channel action.
- no unsupported salary, turnover, career, clinical, diagnosis, treatment, cure, income, or hiring claims.
