---
name: fermatmind-seo-research-content-planning
description: Use for fap-api SEO research, article topic selection, bilingual content opportunity discovery, competitor content analysis, SERP intent mapping, topic clustering, query owner mapping, page opportunity ranking, and SEO content asset planning.
---

## Purpose
Use this skill to turn approved SEO/GEO evidence into a research and content-planning packet for FermatMind.

This is the Codex-loadable successor to `backend/docs/seo/skills/fermat-seo-research-content-planning.md`. It preserves that draft's operating boundary and makes the research layer executable as an Agent Skill. The output is planning evidence, not publishable content, CMS data, search submission, sitemap/llms/schema activation, or deployment.

The strategic posture is the site-wide backend strategy in `backend/docs/seo/fermatmind-free-assessment-global-seo-geo-strategy-2026-07-04.md`: free professional assessments with free complete result pages, claim-safe public interpretation, and backend/CMS authority for public SEO assets.

## When to use
- Use for article topic selection.
- Use for Chinese and English content opportunity discovery.
- Use for keyword research from approved artifacts and gated read models.
- Use for GSC query/page analysis after the data-quality gate passes.
- Use for competitor keyword and content gap analysis that stays structural and source-ledger safe.
- Use for SERP intent analysis, topic clustering, query owner mapping, page owner mapping, and page opportunity ranking.
- Use for SEO content asset planning before CMS dry-run package work.
- Use for GEO/AEO visible answer block planning when the downstream content must be CMS-backed and claim-safe.
- Use before asking another workflow to create a CMS draft, publish content, change discoverability surfaces, or submit search URLs.

## When not to use
- Do not use to write CMS records, publish content, import content, or mutate production data.
- Do not use to enqueue Search Channel records or submit URLs to Google, Baidu, Bing, IndexNow, 360, Sogou, or any provider.
- Do not use to activate or modify sitemap, llms, llms-full, schema, FAQPage, canonical, robots, hreflang, queues, schedulers, env vars, deployment, migrations, routes, controllers, services, or frontend runtime.
- Do not use to create frontend fallback content or publishable copy in fap-web.
- Do not use to index private result, take, order, share, pay, checkout, auth, account, invite, token, recovery, or report-download URLs.
- Do not use for pSEO mass generation.
- Do not use to scrape competitors or reproduce competitor copy, pricing, ratings, reviews, screenshots, rankings, testimonials, or proprietary report structures.
- Do not use screenshots, browser UI observations, manually copied GSC numbers, or LLM guesses as source-of-truth metrics.
- Do not call live APIs unless a separate approved task explicitly authorizes that API access.

## Source Authority Hierarchy
Use the highest available authority source. Lower layers can classify or contextualize, but they cannot override higher authority.

1. Backend/CMS/URL Truth: page identity, canonical URL, locale, indexability, publication state, public SEO fields, content ownership, private/public boundaries, search queue eligibility, and controlled CMS operation status.
2. Backend business truth: current product posture, free test/free complete result availability, assessment families, funnel events, claim boundaries, and business priorities.
3. `seo_intel` / GSC read model after data-quality gate: query/page metrics only when the relevant gate passes and the artifact is not fixture, mock, stale, incomplete, or unsafe.
4. Opportunity Queue read-only artifacts: sanitized candidates from approved read-only opportunity workflows.
5. CMS TDK/FAQ gap scanner artifacts: sanitized read-only scanner outputs for public published CMS surfaces.
6. Runtime QA observations: public HTML, canonical, robots, H1, CTA, internal-link, schema, sitemap, llms, and response observations. These are observations, not authority.
7. Competitor source ledger: operator-reviewed source notes, allowed claims, forbidden claims, legal/claim/indexability review states, and first-party FermatMind facts.
8. Approved keyword/SERP research artifacts: manual or approved read-only research notes with date, locale, country/device, reviewer, and source notes.
9. Human review notes: product, legal, claim, content, SEO, and operations decisions.
10. LLM/Codex reasoning: classification, clustering, drafting of planning artifacts, and risk surfacing only. It is not factual metric authority.

## Inputs
Allowed input families:

- `backend_url_truth`: CMS/public API URL identity, canonical state, locale, indexability, page family, publication state, private/public status, and owner resource.
- `backend_business_truth`: assessment family, free complete result availability, funnel goal, claim scope, supported locale, and priority tier.
- `gsc_read_model`: gated `seo_intel` rows or sanitized artifacts with impressions, clicks, CTR, average position, query/page aliases, locale, and date.
- `opportunity_queue`: sanitized read-only candidates from opportunity queue or opportunity aggregator outputs.
- `cms_gap_scanners`: sanitized TDK/FAQ/schema/readiness gap artifacts.
- `cms_inventory`: published articles, topics, test landings, content pages, personality profiles, career pages, and relevant SEO fields.
- `internal_link_graph`: approved semantic graph or public QA observations of links, anchors, owner pages, orphan pages, and 200/redirect/error state.
- `competitor_source_ledger`: reviewed structural competitor notes and allowed/forbidden claim metadata.
- `serp_research_notes`: approved non-mutating SERP observations with country/device/date/context.
- `runtime_qa`: public page checks for title, meta description, H1, hero, CTA, canonical, robots, schema, sitemap/llms membership, hreflang, private URL absence, and claim safety.
- `human_review`: operator notes, product decisions, claim/legal review notes, and manually approved assumptions.

Forbidden inputs:

- Raw private result/order/payment/share/report/account/auth/token/invite/recovery/checkout/take URLs.
- Raw credentials, cookies, service-account JSON, tokens, private keys, emails, phone numbers, payment IDs, order IDs, attempt IDs, user identifiers, or raw Search Console payloads.
- Raw GSC query leakage that violates the read-model contract.
- Copied competitor copy, FAQs, headings, report sections, screenshots, reviews, ratings, testimonials, prices, ranking claims, or proprietary labels.
- Unreviewed AI-generated search data presented as truth.
- Production raw logs unless a separate approved log-review task exists.

## Non-goals
- No CMS write, CMS publish, content import, media upload, working revision promotion, or controlled publish execution.
- No Search Channel enqueue, approval, retry, submit, or provider call.
- No sitemap, llms, llms-full, schema, FAQPage, canonical, robots, hreflang, route, queue, scheduler, or deployment mutation.
- No frontend fallback content, static editorial files, local MDX/JSON content, or fap-web runtime changes.
- No final article body, final FAQ copy, final title/meta, final CTA copy, or final public copy unless a separate CMS content package task explicitly authorizes draft generation.
- No competitor scraping, competitor reproduction, superiority claims, or policy-gated competitor alternatives publication.
- No private result data exposure or private URL indexing.

## Core Workflow
1. Source intake and gate
   - Lock locale, page family, assessment family, business goal, time window, allowed artifacts, and forbidden actions.
   - Verify every input family against the source authority hierarchy.
   - Require GSC data-quality gate pass before using GSC metrics as formal evidence.
   - Treat screenshots, browser observations, manually copied metrics, and LLM notes as context only.
   - Record missing authority in `BLOCKED_OR_UNVERIFIED_ASSUMPTIONS.md`.

2. Noise filtering
   - Remove brand navigational noise unless brand protection is the declared scope.
   - Remove private-flow URLs, parameter-only variants, noindex/draft/private/hard-404/fallback-only pages, raw identifiers, and unsafe data.
   - Remove fixture/mock/stale artifacts.
   - Remove competitor facts that are not source-ledger approved.
   - Mark query families that require clinical, hiring, admission, salary, IQ-certification, official-affiliation, or guarantee claims as `needs_review` or `blocked`.

3. Query universe construction
   - Build query families from approved GSC read-model artifacts, CMS inventory, keyword/SERP notes, competitor structural notes, social/context notes when approved, and internal-link/entity gaps.
   - Classify each query family by locale, market, page family, assessment family, user job, lifecycle stage, and intent layer.
   - Default intent layers: `money`, `explainer`, `scenario`, `entity`, `comparison`, `trust`, and `result_interpretation`.
   - Keep Chinese and English intent separate when SERP shape or user language differs.

4. Query owner matrix
   - Assign one primary owner page for each primary query family.
   - Identify support pages, internal links, canonical expectation, locale expectation, publication gate, and claim gate.
   - Avoid creating two owner pages for the same primary query family unless locale, page family, or intent split is clear.
   - Do not assign private/take/result/order/share/pay URLs as owners.

5. Competitor gap analysis
   - Analyze competitors structurally: page family, query family, intent coverage, information blocks, internal-link patterns, product/access model, and missing FermatMind asset.
   - Identify FermatMind original value: free test with free complete results, local Chinese scenarios, bilingual parity, clearer boundaries, better result interpretation, stronger internal next steps, or method/privacy support.
   - Do not copy or paraphrase competitor copy, examples, FAQ, report sections, reviews, prices, ratings, screenshots, testimonials, rankings, or proprietary structures.
   - Do not make superiority claims such as "better than 16Personalities/Truity/123test".

6. SERP intent map
   - Record dominant intent, dominant page family, freshness expectation, SERP modules, locale/country/device/date/reviewer, brand/non-brand state, and public-safe claim path.
   - Decide whether the query needs a new asset, an existing-page update, internal-link repair, CTR repair, or hold.
   - Do not treat one SERP sample as stable truth.

7. Topic clustering
   - Cluster by assessment family, user job, lifecycle stage, intent layer, locale/market, required claim boundary, and owner page.
   - Each cluster must include one owner, support assets, internal-link plan, canonical/locale expectation, content asset type, claim risk, and publication gate.
   - Keep zh and en clusters separate when direct translation would miss local intent.

8. Page opportunity ranking
   - Score opportunities with the default scoring model below.
   - Prefer high-impression pages with average position 5-15, low CTR, clear owner, backend/CMS authority, indexability, internal-link repair path, and claim-safe copy.
   - Mark unknown factors as `unknown`; do not fill them with guesses.

9. Content asset planning
   - For each approved opportunity, produce a planning record only: asset id, locale, page family, owner page, query family, intent layer, target user problem, proposed asset type, primary CTA, secondary links, required answer blocks, required tables/checklists, required boundaries, competitor gap notes, SERP notes, source evidence refs, claim/legal review flags, CMS authority source, dry-run candidate state, and blocked actions.
   - Keep final public copy out of this skill unless a separate content package task explicitly authorizes drafting.

10. GEO/AEO answer block planning
   - Recommend visible CMS-backed answer/evidence blocks first: definitions, concise direct answers, method boundaries, result interpretation boundaries, tables/checklists, FAQs where visible and grounded, and source/evidence notes.
   - Treat schema, FAQPage, llms, sitemap, and other discoverability surfaces as downstream checks, not substitutes for missing visible content authority.
   - Do not recommend hidden structured data for missing content.

11. CMS dry-run handoff
   - Produce dry-run handoff artifacts only.
   - State required CMS resource type, owner, publication gate, claim/legal review status, required source evidence, QA checks, and blocked actions.
   - The handoff does not authorize CMS writes, imports, publication, Search Channel work, or deployment.

## Required Output Artifacts
Every formal run should create or update the following sanitized planning artifacts. If an artifact cannot be completed, create it with a clear blocked/unknown section instead of inventing data.

| Artifact | Purpose | Required fields or sections | Allowed inputs | Forbidden data | Downstream consumer | No-write boundary |
| --- | --- | --- | --- | --- | --- | --- |
| `FERMAT_SEO_RESEARCH_CONTROL_PACKET.md` | Run control, source inventory, scope, gates, and final verdict. | Scope, source list, gate status, locale, page families, assessment families, forbidden actions, verdict, blockers. | All approved artifacts and human notes. | Credentials, raw payloads, private URLs, copied competitor content. | Codex reviewer, SEO operator. | Planning only; no CMS/search/deploy action. |
| `TOP_50_QUERY_OWNER_MATRIX.csv` | Map highest-value query families to owner pages. | `rank`, `query_family_alias`, `locale`, `intent_layer`, `owner_page_ref`, `owner_page_family`, `support_pages`, `source_refs`, `claim_state`, `action`. | Gated GSC, CMS inventory, URL Truth, approved research. | Raw queries if contract forbids them, private URLs, invented metrics. | Content planner, CMS dry-run reviewer. | No page creation or publication. |
| `TOP_20_PAGE_CTR_REPAIR_MATRIX.csv` | Identify CTR repair opportunities. | `rank`, `page_ref`, `query_family_alias`, `impressions`, `clicks`, `ctr`, `average_position`, `current_title_state`, `current_meta_state`, `repair_hypothesis`, `source_refs`, `gate_state`. | Gated GSC read model, TDK scanner, runtime QA. | Manual GSC screenshots as authority, raw payloads, private URLs. | SEO editor, TDK dry-run planner. | No title/meta write. |
| `COMPETITOR_KEYWORD_GAP_MATRIX.csv` | Structural competitor gap map. | `gap_id`, `locale`, `query_family`, `competitor_page_family`, `observed_intent`, `fermatmind_missing_asset`, `original_value_path`, `claim_risk`, `source_ledger_ref`, `status`. | Competitor source ledger, SERP notes, CMS inventory. | Copied copy, screenshots, rankings, prices, reviews, testimonials, superiority claims. | Claim reviewer, content planner. | No alternatives page or public claim. |
| `SERP_INTENT_MAP.csv` | SERP shape and intent classification. | `query_family_alias`, `locale`, `country`, `device`, `date`, `dominant_intent`, `dominant_page_family`, `serp_modules`, `freshness_need`, `safe_claim_path`, `reviewer`, `source_refs`. | Approved SERP notes and research artifacts. | Live API output without approval, private account/session data. | Topic cluster planner. | No search provider action. |
| `TOPIC_CLUSTER_PAGE_OWNER_MAP.md` | Cluster map for page ownership and internal links. | Clusters, owner page, support assets, internal links, canonical expectation, locale expectation, claim gate, publication gate. | Query owner matrix, CMS inventory, URL Truth, internal-link graph. | Private URLs, fallback-only pages, duplicate owner conflicts. | Content architecture reviewer. | No route/sitemap/llms mutation. |
| `PAGE_OPPORTUNITY_RANKING.csv` | Ranked opportunity queue for planning. | `rank`, `opportunity_id`, `page_ref`, `query_family`, scoring fields, `priority_score`, `confidence`, `recommended_action`, `blocked_reason`, `source_refs`. | All sanitized research artifacts. | Fabricated metrics, raw identifiers, private URLs. | SEO prioritization owner. | No execution queue mutation. |
| `NEXT_7_30_90_DAY_CONTENT_PLAN.md` | Time-boxed content and repair plan. | 7-day, 30-day, 90-day buckets; asset type; owner; dependency; claim/legal/CMS gates; acceptance checks. | Page ranking, clusters, business truth, human notes. | Publishable body copy, invented commitments. | Content ops, product/SEO review. | No CMS publish/import. |
| `CLAIM_SAFE_SNIPPET_REWRITE_MATRIX.csv` | Plan claim-safe copy improvements without publishing them. | `surface_ref`, `current_claim_risk`, `unsafe_pattern`, `preferred_language_pattern`, `required_review`, `source_refs`, `status`. | Runtime QA, TDK scanner, claim rules, human review. | Final public copy, unsupported accuracy/certification/superiority claims. | Claim reviewer, CMS dry-run planner. | No copy write. |
| `INTERNAL_LINK_GRAPH_REPAIR_PLAN.md` | Plan semantic link repair. | Orphan pages, missing links, anchor intent, source page, target page, 200 check state, priority, blocked links. | Internal-link graph, runtime QA, CMS inventory. | Private URLs, unsupported anchors, fallback-only targets. | CMS dry-run planner, frontend/API reviewer if later needed. | No link insertion. |
| `GEO_ANSWER_BLOCK_PLAN.md` | Plan visible answer/evidence blocks for AI answer readiness. | Target page, query family, answer block type, evidence block, visible FAQ need, table/checklist need, claim boundary, source refs, downstream schema/llms check. | CMS inventory, research notes, GEO docs, claim rules. | Hidden-only schema, AI-bait copy, unsupported claims. | GEO/content planner. | No schema/llms/sitemap mutation. |
| `CMS_DRY_RUN_BRIEF_HANDOFF.md` | Handoff from research to controlled CMS dry-run planning. | Candidate assets, CMS resource type, owner record, source evidence, required review, QA checks, blocked actions, exact non-authorizations. | All sanitized planning artifacts. | Credentials, raw private data, final publish copy. | CMS dry-run workflow. | Dry-run only; no CMS write/publish/import. |
| `BLOCKED_OR_UNVERIFIED_ASSUMPTIONS.md` | Preserve unresolved assumptions and blocked requests. | Assumption, missing authority, risk, affected artifact, required next evidence, stop reason. | Any source family. | Guesses presented as facts. | Human reviewer. | No automatic resolution. |

## Scoring Model
Default score:

```text
priority_score =
  demand_signal
  + position_opportunity
  + CTR_repair_opportunity
  + business_fit
  + content_gap_severity
  + GEO_extractability_gap
  + funnel_value
  + feasibility
  - claim_risk
  - cannibalization_risk
  - authority_gap
```

Scoring rules:

- Do not fabricate impressions, clicks, CTR, position, search volume, KD, CPC, conversion, or competitor facts.
- If a factor lacks verified data, mark it `unknown`.
- Prefer pages with high impressions, average position 5-15, low CTR, clear owner, backend/CMS authority, indexability, and claim-safe copy.
- Do not prioritize private/take/result/order/share/pay URLs.
- Use integer or labeled component scores only when source evidence supports them; otherwise use `unknown` and explain the confidence limit.
- A high score cannot override a hard stop such as private URL exposure, missing authority, unsafe claim path, failed GSC gate, or unapproved competitor evidence.

Suggested component interpretation:

- `demand_signal`: verified GSC impressions or approved demand proxy.
- `position_opportunity`: ranking range where improvement is plausible; strongest around positions 5-15.
- `CTR_repair_opportunity`: high impressions plus low CTR with a clear title/meta/intent mismatch.
- `business_fit`: alignment with free tests, free complete results, core assessment families, and current product priority.
- `content_gap_severity`: missing owner page, weak answer block, thin explanation, missing locale parity, or missing result interpretation.
- `GEO_extractability_gap`: missing visible direct answer, evidence block, table, FAQ, or boundary.
- `funnel_value`: safe ability to lead toward test start, result view, article-to-test click, or trust support.
- `feasibility`: CMS authority, source availability, review availability, and implementation simplicity.
- `claim_risk`: clinical, IQ, hiring, admission, salary, official affiliation, competitor, accuracy, or guarantee risk.
- `cannibalization_risk`: duplicate owner or unclear page split.
- `authority_gap`: missing CMS/backend owner, missing URL Truth, missing publication gate, or fallback-only state.

## Claim Guardrails
Hard-block or mark `needs_review` if copy, metadata, topic framing, or planned answer blocks imply:

- clinical diagnosis;
- mental-health treatment;
- official IQ certification;
- official MBTI affiliation;
- hiring decision;
- admission prediction;
- salary prediction;
- guaranteed career outcome;
- guaranteed relationship outcome;
- "most accurate";
- "better than 16Personalities/Truity/123test";
- "no paywall" unless backend authority confirms free complete result;
- private result data exposure.

Preferred language:

- "free test with free complete results";
- "used for self-understanding, communication, career exploration, and reflection";
- "not a diagnosis, hiring decision, admission decision, or guarantee";
- "MBTI-style / 16 types" where official affiliation risk exists.

Competitor claim rules:

- Competitor analysis can say a competitor page family or structural pattern exists when a source ledger supports it.
- Competitor analysis cannot claim FermatMind is more accurate, better, cheaper, more official, more validated, or more trusted unless a separate claim/legal review and first-party evidence explicitly allow the claim.
- Named competitor alternative pages remain dry-run/policy-gated unless a separate legal/claim review approves them.

Free result claim rules:

- "Free test with free complete results" is allowed only when backend business truth and current product behavior confirm the full flow is free.
- Do not imply private result URLs are indexable or public.
- Public SEO pages may explain what the free result contains; private result data remains excluded from indexing and artifacts.

## Runtime/Readback Checks For Future Downstream Work
This skill only plans these checks. It must not execute CMS/search/deploy work.

Any downstream execution packet should require verification of:

- title;
- meta description;
- H1 / hero;
- CTA;
- canonical;
- robots;
- indexability;
- sitemap membership;
- llms membership;
- schema state;
- hreflang state;
- private URL absence;
- claim safety;
- internal links return 200;
- no frontend fallback authority;
- no stale URL Truth.

Interpretation rules:

- Visible CMS-backed content authority comes before schema, FAQPage, llms, sitemap, or AI/GEO discoverability optimization.
- Sitemap and llms are discoverability outputs, not URL Truth.
- Public runtime observations can reveal drift, but backend/CMS/URL Truth decides the fix path.
- Empty or missing CMS authority must not be repaired with frontend fallback content.

## Standard Workflow
1. Read this skill and identify whether the request is research/planning only.
2. Re-read the relevant source docs for the requested surface:
   - `backend/docs/seo/fermatmind-free-assessment-global-seo-geo-strategy-2026-07-04.md`
   - `backend/docs/seo/seo-agent-opportunity-aggregator.md`
   - `backend/docs/seo/opportunity-queue-readonly.md`
   - `backend/docs/seo/seo-agent-gsc-opportunity-auto-draft.md`
   - `backend/docs/seo/seo-agent-cms-tdk-gap-readonly-scanner.md`
   - `backend/docs/seo/seo-agent-cms-faq-gap-readonly-scanner.md`
   - `backend/docs/seo/competitor-alternatives-source-ledger.md`
   - `backend/docs/seo/skills/fermat-seo-ops.md`
   - `backend/docs/seo/skills/fermat-ai-seo-geo.md`
3. Lock scope and source gates before analysis.
4. Build sanitized artifacts only.
5. Write blocked assumptions instead of inventing evidence.
6. Keep planning separate from CMS writes, publication, discoverability mutation, and deployment.
7. End with changed files, commands run, runtime/CMS/search/deploy impact, fap-web impact, and assumptions.

## Acceptance Commands
For docs-only changes to this skill:

```bash
git diff --check
git diff --cached --check
```

Do not run Laravel runtime checks unless runtime, API, migration, route, controller, service, queue, scheduler, env, or test code is modified.

For downstream research runs that produce artifacts, validate only the artifact formats and source gates requested by that run. Do not run CMS write, Search Channel, live API, provider submission, or deployment commands from this skill.

## Output Contract
Every response using this skill must report:

- Changed files list.
- Exact new file path or exact insertion/replacement position when editing docs.
- Acceptance commands run.
- Runtime impact.
- CMS impact.
- Search submission impact.
- Deployment impact.
- fap-web impact.
- Any assumptions, missing docs, blocked evidence, or source gates that prevented completion.

If a PR was created or updated, include the PR URL and branch.

## Stop Conditions
Stop and output or update `BLOCKED_OR_UNVERIFIED_ASSUMPTIONS.md` when any of these occurs:

- GSC data-quality gate fails.
- Source artifact is fixture, mock, stale, incomplete, unknown, or unsafe.
- Raw private identifier would be exposed.
- Raw query leakage violates the existing read-model contract.
- Backend/CMS authority is missing.
- Private result/take/order/share/pay/checkout/account/auth/token/invite/recovery/report URL is involved.
- Page is noindex, draft, private, hard-404, fallback-only, or has stale URL Truth.
- Competitor facts are not source-ledger approved.
- Claim risk cannot be resolved.
- Requested action would write CMS, publish, import content, submit search, mutate discoverability surfaces, call live APIs, change runtime code, change fap-web, or deploy.
- The user asks for pSEO mass generation, competitor scraping, copied competitor content, private result URL indexing, or unsupported superiority/accuracy/certification claims.
