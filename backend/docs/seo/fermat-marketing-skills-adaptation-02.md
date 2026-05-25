# FERMAT-MARKETING-SKILLS-ADAPTATION-02

## Source Material

External reference:

- `https://github.com/coreyhaines31/marketingskills`
- temporary checkout: `/private/tmp/marketingskills-scan`
- license: MIT

Referenced upstream skills:

- `ai-seo`
- `schema`
- `site-architecture`
- `cro`
- `paywalls`
- `analytics`
- `emails`
- `directory-submissions`
- `competitors`
- `customer-research`

FermatMind source material:

- `backend/docs/seo/fermat-marketing-skills-adaptation-01.md`
- `backend/docs/seo/skills/fermat-product-marketing-context.md`
- `backend/docs/seo/skills/fermat-seo-ops.md`
- `backend/docs/seo/skills/fermat-claim-boundary.md`
- SEO Ops, Search Channel, Research, EN-PARITY, RESULT-EN-PARITY, Digital PR, URL Truth, sitemap/llms, JSON-LD, FAQ, and claim boundary artifacts under `backend/docs/seo`.

## What Upstream marketingskills Contributed

The upstream repository contributed reusable operator patterns:

- AI-answer extractability and GEO thinking.
- schema accuracy and visible-content grounding.
- site architecture and internal-link review structure.
- CRO page review, paywall framing, and conversion friction checks.
- analytics event-separation thinking.
- lifecycle email review patterns.
- directory submission, target fit, competitor research, and customer research frameworks.

These patterns were adapted into Fermat-specific guardrails. Upstream skills were not installed, copied wholesale, made executable, or treated as authority.

## Why Upstream Was Not Installed

Direct installation remains inappropriate because FermatMind requires stricter controls:

- Backend/CMS/URL Truth is authority.
- `fap-web` fallback is not authority.
- sitemap and `llms.txt` are discoverability surfaces, not truth.
- Search Channel requires exact human approval.
- no auto-publish.
- no pSEO while P0 discoverability cleanup is dirty.
- no unsupported clinical, career, hiring, salary, diagnosis, treatment, cure, income, turnover, or career-success claims.
- no Digital PR send or directory submission without exact human approval.

## Created Internal Skill Drafts

Created draft/operator documents:

1. `backend/docs/seo/skills/fermat-ai-seo-geo.md`
2. `backend/docs/seo/skills/fermat-cro-result-report.md`
3. `backend/docs/seo/skills/fermat-digital-pr.md`

These are documentation drafts only. They are not installed under `.agents/skills`, not runtime code, and not content assets.

## Mapping to Fermat Workflows

### AI SEO / GEO

Maps to:

- `llms.txt` and `llms-full.txt` discoverability rules.
- Answer Surface grounding.
- FAQ grounding.
- JSON-LD/schema grounding.
- Evidence Container readiness.
- Research Hub, Topic, Article, Career, and Test page readiness.
- AI answer observation without treating AI exposure as truth.
- sitemap/llms P0 blocker review.

### CRO Result / Report

Maps to:

- result page funnel.
- report preview funnel.
- paid unlock funnel.
- checkout/order/recovery funnel.
- My Results lookup.
- PDF/email/share lifecycle.
- invite unlock boundaries.
- frontend observation vs backend truth event split.
- RESULT-EN-PARITY fail-closed no-zh-fallback rules.

### Digital PR

Maps to:

- Digital PR Wave 2 governance.
- HRZone follow-up review.
- HREC first-send review.
- Research Hub pitch boundary.
- target list review.
- one-follow-up limit.
- human-approved send rule.
- mention/referral/backlink observation.
- brand lift proxy.
- claim linter before pitch.

## How ADAPTATION-02 Complements ADAPTATION-01

ADAPTATION-01 established the base context, SEO Ops guardrails, and claim boundaries. ADAPTATION-02 adds operational drafts for three adjacent workflows:

- AI/GEO readiness without schema illusion or AI-bait.
- result/report CRO without confusing frontend observations with backend truth.
- Digital PR without bulk outreach, paid backlinks, or claim-unsafe pitches.

Together they form a safer operator layer for future SEO/GEO/CRO/Digital PR PR trains.

## What Remains Deferred

- No `.agents/skills` runtime integration.
- No content analytics skill draft.
- No pSEO guard skill draft.
- No prompt template library.
- No content generation.
- No Digital PR send.
- No Search Channel action.
- No CMS mutation.
- No fap-web changes.

## Why pSEO Remains Blocked

pSEO remains blocked because P0 discoverability cleanup is still the governing condition for scaled URL generation. FermatMind must not create or expose programmatic pages while sitemap/llms hard-404 exposure, career discoverability leakage, URL Truth drift, claim boundaries, and internal link readiness are not fully clean.

Future pSEO must use backend-authoritative entities, visible grounded content, claim-safe copy, canonical URL Truth, and human-approved Search Channel action.

## Next Recommended Adaptation Batch

Recommended next task: `FERMAT-MARKETING-SKILLS-ADAPTATION-03`.

Suggested future scope, subject to explicit user authorization:

- content analytics / attribution skill draft.
- pSEO guard skill draft.
- content asset batch guard skill draft.

No future task entry was added in this PR.

## Safety Statement

This PR performed docs/prompt adaptation only. It did not install upstream skills, modify runtime code, generate content assets, mutate CMS, deploy, enqueue Search Channel items, submit URLs, call external search APIs, send Digital PR, read raw logs, or access production user data.

Final decision: `fermat_marketing_skills_adaptation_02_completed_ready_for_content_analytics_pseo_guard_batch`.
