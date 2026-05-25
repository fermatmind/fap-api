# MARKETINGSKILLS-FERMATMIND-FIT-SCAN-00 Report

## 1. Executive Summary

`coreyhaines31/marketingskills` is useful for FermatMind as a reference library and as source material for Fermat-specific internal marketing skills. It should not be installed directly into `.agents/skills` yet.

The repository is strongest for structured SEO audit thinking, AI SEO/GEO content extractability, schema discipline, site architecture, CRO, paywalls, analytics, email lifecycle, directory submissions, competitor research, and product marketing context. Those map well to FermatMind's SEO/GEO/CRO operations, but only after adaptation to FermatMind's backend/CMS authority, URL Truth, Search Channel Queue, claim-boundary, and no-auto-publish rules.

Final decision: `marketingskills_fit_scan_completed_ready_for_internal_skill_adaptation`.

## 2. External Repo Summary

External repository: `https://github.com/coreyhaines31/marketingskills`

Temporary checkout: `/private/tmp/marketingskills-scan`

License: MIT.

The repo is a collection of markdown Agent Skills for marketing workflows. The README positions `product-marketing` as the foundation skill that other skills should read first. The repo also includes installation instructions using `npx skills`, plugin installation, copying skills, or submodules; none of those install paths were executed for FermatMind.

## 3. Relevant Skills Inventory

Scanned skills:

- `product-marketing`
- `seo-audit`
- `ai-seo`
- `schema`
- `programmatic-seo`
- `site-architecture`
- `content-strategy`
- `cro`
- `paywalls`
- `analytics`
- `emails`
- `directory-submissions`
- `competitors`
- `competitor-profiling`
- `copywriting`
- `customer-research`
- `free-tools`

Directly useful with constraints:

- `seo-audit`
- `ai-seo`
- `schema`
- `site-architecture`
- `analytics`
- `cro`
- `paywalls`
- `emails`

Useful after adaptation:

- `product-marketing`
- `content-strategy`
- `directory-submissions`
- `competitors`
- `competitor-profiling`
- `copywriting`
- `customer-research`
- `free-tools`

Risky unless constrained:

- `programmatic-seo`
- `directory-submissions`
- `copywriting`
- `competitors`
- `ai-seo`
- `schema`

## 4. FermatMind Current SEO/GEO/Ops Fit

FermatMind already has a stronger governance layer than the upstream skills assume:

- Backend/CMS/URL Truth is authority.
- fap-web is deterministic runtime, not content authority.
- sitemap and `llms.txt` are discoverability surfaces, not truth.
- Search Channel Queue is gated and human-approved.
- EN/ZH parity and RESULT-EN-PARITY gates are already active.
- Claim-sensitive areas are bounded for clinical, career, IQ, RIASEC, Big Five, and MBTI surfaces.
- Current P0 issue is sitemap/llms hard-404 exposure and career job discoverability leakage.

The upstream skills can improve operator reasoning and prompt structure, but they must be wrapped in Fermat-specific gates before use.

## 5. Directly Useful Skills

`seo-audit` maps to existing URL Truth, sitemap/llms, canonical, hreflang, indexability, and CWV-style review. It should be adapted to treat backend URL Truth as source of truth and to forbid direct runtime edits.

`ai-seo` maps to FermatMind GEO, `llms.txt`, Research Hub, and extractable evidence containers. It needs strong limits because Google AI guidance does not require AI-specific files, and FermatMind must avoid AI-bait pages.

`schema` maps to JSON-LD and FAQ grounding gates. It is useful because it emphasizes schema matching visible page content.

`site-architecture` maps to internal link graph and topic/entity navigation, especially after P0 discoverability cleanup.

`analytics` maps to Search Intelligence, funnel event taxonomy, CTA attribution, and privacy boundaries.

`cro` and `paywalls` map to result/report conversion, paid report preview, unlock flow, My Results, and low-friction report recovery.

`emails` maps to result/report lifecycle, but only with PII guardrails.

## 6. Useful After Adaptation

`product-marketing` is useful as the base for a Fermat context file, but the upstream path `.agents/product-marketing.md` should not be added in this PR. The Fermat version should be generated from existing backend SEO docs, product authority rules, claim boundaries, and current growth loops.

`content-strategy` is useful for article counterpart batches and Research Hub planning, but must not produce publishable content directly.

`directory-submissions` is useful for Digital PR Wave 2 planning, but must be human-only, tracked, and claim-safe.

`competitors` and `competitor-profiling` are useful for 123test, Truity, 16Personalities, CareerExplorer, Psychology Today, and Chinese market scans. They must remain research artifacts, not automatic comparison-page generators.

`copywriting` is useful for review prompts and CTA drafts, not direct CMS mutation.

`customer-research` is useful for VOC and search-intent research, but must not invent personas from thin data.

`free-tools` maps well to FermatMind's core product-led SEO model because tests and comparison tools are the product assets.

## 7. Risky / Do Not Adopt Now

Do not directly adopt `programmatic-seo`. FermatMind's pSEO is explicitly blocked until P0 discoverability cleanup, URL Truth, claim boundary, and internal link gates are clean.

Do not directly adopt upstream directory submission playbooks as operational commands. FermatMind requires human approval, no bulk outreach, no paid backlinks, no private email scraping, and no Search Channel side effects.

Do not use upstream copywriting to generate clinical, career, hiring, salary, treatment, or diagnostic claims.

Do not use schema guidance to add JSON-LD for content that is not visibly present or backend-authoritative.

Do not install upstream skills into `.agents/skills` without a Fermat-specific fork and guardrail review.

## 8. Fermat Internal Skill Recommendations

Recommended internal Fermat skills:

- `fermat-product-marketing-context`
- `fermat-seo-ops`
- `fermat-ai-seo-geo`
- `fermat-schema-jsonld`
- `fermat-content-asset-batch`
- `fermat-pseo-guard`
- `fermat-cro-result-report`
- `fermat-digital-pr`
- `fermat-analytics-attribution`
- `fermat-claim-boundary`

These should be Fermat-authored wrappers, not direct copies. Each skill should start with Fermat authority rules, current P0 no-go conditions, and validation requirements.

## 9. pSEO Guardrails

pSEO remains blocked now.

Future pSEO can be considered only when:

- sitemap/llms hard-404 exposure is fixed;
- career job discoverability leakage is fixed;
- URL Truth source authority is clean;
- claim linter is active for the page class;
- generated page has real backend entity data;
- visible content, FAQ, JSON-LD, internal links, and CTA are grounded;
- Search Channel Queue requires human approval;
- no career or clinical overclaim is possible.

## 10. CRO / Result / Report Funnel Opportunities

The `cro`, `paywalls`, `analytics`, and `emails` skills can support:

- result page next-step clarity;
- paid report preview framing;
- unlock CTA testing;
- My Results card clarity;
- PDF/email/share lifecycle copy review;
- article to test to result to paid report attribution;
- email lookup and report recovery flows with no PII in analytics.

The adaptation must use backend result/report assets as authority and must fail closed when English assets are missing.

## 11. Digital PR / Directory / External Authority Opportunities

Useful patterns from `directory-submissions`, `ai-seo`, and `competitor-profiling`:

- build Research Hub and linkable assets before outreach;
- track every outreach target manually;
- vary directory positioning by audience only after claim review;
- measure backlinks/referrals as observation signals, not truth;
- use competitor profiles to inform content gaps, not to auto-create pSEO pages.

This maps to MBTI Digital PR Wave 2, Research claim-sensitive preflight, and future authority-building.

## 12. Adoption Phases

Phase 0: read-only reference only.

Phase 1: create a Fermat product marketing context draft from existing Fermat docs.

Phase 2: fork/adapt selected skills into Fermat-specific internal skills.

Phase 3: add Codex prompt templates for SEO Ops, CRO, content batches, and Digital PR review.

Phase 4: integrate into PR train only with strict guardrails, focused tests, and human approval gates.

## 13. Validation

Required validation:

```bash
cd /Users/rainie/Desktop/GitHub/fap-api/backend
php artisan test --filter=MarketingSkillsFermatmindFitScan00 --no-ansi
php artisan route:list --no-ansi
vendor/bin/pint --test
composer validate --strict
composer audit --locked --no-interaction --ignore-unreachable

cd /Users/rainie/Desktop/GitHub/fap-api
python3 -m json.tool backend/docs/seo/generated/marketingskills-fermatmind-fit-scan-00.v1.json >/dev/null
python3 -m json.tool docs/codex/pr-train-state.json >/dev/null
python3 - <<'PY'
import yaml, pathlib
yaml.safe_load(pathlib.Path('docs/codex/pr-train.yaml').read_text())
print('yaml ok')
PY
git diff --check
git diff --cached --check
```

## 14. PR / Merge Result

Pending at report generation.

## 15. Sidecar Issues

- fap-web is currently on an active GLOBAL parity branch with local changes; this scan treated fap-web as reference-only and did not modify it.
- The upstream skills include install instructions; no install command was executed.
- The upstream skills sometimes assume marketing pages can be created directly; FermatMind requires backend/CMS authority and PR train gates.

## 16. What Was Not Done

No installation, no `.agents` or `.claude` changes, no fap-web commit, no runtime code modification, no CMS mutation, no deploy, no production data access, no raw log read, no Search Channel enqueue/submission, no external search API call, no pSEO generation, and no content asset generation were performed.

## 17. Final Decision

`marketingskills_fit_scan_completed_ready_for_internal_skill_adaptation`

## 18. Recommended Next Task

`FERMAT-MARKETING-SKILLS-ADAPTATION-01`
