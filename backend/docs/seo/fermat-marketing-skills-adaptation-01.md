# FERMAT-MARKETING-SKILLS-ADAPTATION-01

## Source Material

External reference:

- `https://github.com/coreyhaines31/marketingskills`
- temporary checkout: `/private/tmp/marketingskills-scan`
- license: MIT

Referenced upstream skills:

- `product-marketing`
- `seo-audit`
- `ai-seo`
- `schema`
- `site-architecture`
- `analytics`
- `cro`
- `paywalls`
- `emails`
- `content-strategy`
- `copywriting`
- `programmatic-seo`
- `directory-submissions`
- `competitors`
- `customer-research`

FermatMind source material:

- `backend/docs/seo/marketingskills-fermatmind-fit-scan-00.md`
- `backend/docs/seo/seo-ops-daily-runbook.md`
- `backend/docs/seo/seo-ops-approval-gates-no-go-protocols.md`
- `backend/docs/seo/search-channel-queue-contract.md`
- `backend/docs/seo/chinese-claim-boundary-linter.md`
- EN-PARITY, RESULT-EN-PARITY, Search Channel, URL Truth, Research, and SEO Ops artifacts under `backend/docs/seo`.

## What Upstream marketingskills Contributed

The upstream repository contributed useful operator patterns:

- a product marketing context as the base layer for other marketing work.
- SEO audit structure for crawlability, indexation, on-page, schema, and architecture checks.
- AI SEO/GEO extractability patterns.
- schema accuracy and visible-content grounding.
- CRO, paywall, email, analytics, and directory submission thinking.
- customer and competitor research framing.

These patterns were adapted into FermatMind-specific guardrails. They were not installed, symlinked, copied wholesale, or made executable.

## Why Upstream Was Not Installed

Direct installation is not appropriate because FermatMind has stricter authority and safety rules than the upstream skills assume:

- Backend/CMS/URL Truth is authority.
- `fap-web` fallback is not authority.
- sitemap and `llms.txt` are discoverability surfaces, not truth.
- Search Channel action requires exact human approval.
- No auto-publish.
- No pSEO while P0 discoverability cleanup is dirty.
- No unsupported clinical, career, hiring, salary, diagnosis, treatment, cure, income, turnover, or career-success claims.

Installing upstream skills directly could encourage mass pSEO, broad copy generation, directory submissions, schema not grounded in visible content, or Search Channel actions without Fermat gates.

## Created Internal Skill Drafts

Created draft/operator documents:

1. `backend/docs/seo/skills/fermat-product-marketing-context.md`
2. `backend/docs/seo/skills/fermat-seo-ops.md`
3. `backend/docs/seo/skills/fermat-claim-boundary.md`

These are documentation drafts only. They are not installed under `.agents/skills`, not runtime code, and not content assets.

## Mapping to Fermat Workflows

### Product Marketing Context

Maps to:

- Fermat positioning.
- target users.
- product surfaces.
- assessment families.
- SEO/GEO growth loops.
- freemium result/report conversion.
- CMS/content authority.
- URL Truth and Search Channel boundaries.
- current active blockers and no-go conditions.

### SEO Ops

Maps to:

- daily SEO Ops checklist.
- URL Truth checks.
- sitemap/llms checks.
- canonical/hreflang checks.
- Search Channel Queue checks.
- live gate checks.
- staging containment checks.
- issue queue escalation.
- internal link readiness.
- crawler observation boundaries.
- first 7-day MBTI ops cadence.
- Research claim-sensitive page handling.

### Claim Boundary

Maps to:

- MBTI, Big Five, RIASEC, IQ, EQ.
- clinical/SDS/anxiety/depression surfaces.
- career recommendation and career guide copy.
- Research/salary/turnover claims.
- Digital PR.
- result/report/paywall copy.

It provides forbidden examples, safer bounded phrasing, and a review checklist. It does not perform automatic rewrites.

## What Remains Deferred

- No `.agents/skills` runtime integration.
- No `fermat-ai-seo-geo` draft.
- No `fermat-cro-result-report` draft.
- No `fermat-digital-pr` draft.
- No `fermat-analytics-attribution` draft.
- No prompt library.
- No pSEO guard implementation.
- No CMS/content asset generation.
- No Search Channel action.

## Why pSEO Remains Blocked

pSEO remains blocked because current active P0 work includes sitemap/llms hard-404 exposure and career job discoverability leakage. FermatMind must not scale URL generation until URL Truth, discoverability, claim boundaries, internal links, and Search Channel gates are clean.

Future pSEO must use backend-authoritative entities, visible grounded content, claim-safe language, canonical URL Truth, and human-approved Search Channel action.

## Next Recommended Adaptation Batch

Recommended next task: `FERMAT-MARKETING-SKILLS-ADAPTATION-02`.

Suggested scope for that future task, subject to explicit user authorization:

- `fermat-ai-seo-geo`
- `fermat-cro-result-report`
- `fermat-digital-pr`

No future task entry was added in this PR.

## Safety Statement

This PR performed docs/prompt adaptation only. It did not install upstream skills, modify runtime code, generate content assets, mutate CMS, deploy, enqueue Search Channel items, submit URLs, call external search APIs, read raw logs, or access production user data.

Final decision: `fermat_marketing_skills_adaptation_01_completed_ready_for_geo_cro_skill_batch`.
