# Fermat SEO Research / Content Planning

Status: draft internal operator skill.

This document defines the FermatMind SEO Agent skill for article topic selection, bilingual content opportunity discovery, competitor content analysis, keyword research, SERP interpretation, topic clustering, page opportunity ranking, and SEO content asset planning.

It is not an installed Codex `SKILL.md`, not runtime code, not a CMS content asset, and not authorization to scrape competitors, generate publishable copy, mutate CMS, publish content, enqueue Search Channel records, submit URLs, call live search APIs, deploy, or access private user data.

## 1. Purpose

Use this skill when the SEO Agent needs to decide:

- which article topics should be planned next;
- which Chinese and English content opportunities are worth pursuing;
- where FermatMind has keyword, SERP, entity, or content-depth gaps;
- which competitor patterns are useful as structural evidence;
- which page should own a query family;
- which content assets should be created, updated, merged, deferred, or retired;
- which opportunities should enter CMS dry-run planning after human review.

The output is a planning artifact, not publishable content.

## 2. Authority Rules

- Backend/CMS/URL Truth is authority for page identity, publication state, SEO fields, canonical URLs, indexability, and content ownership.
- GSC, Baidu, crawler observations, competitor pages, social platforms, and AI-answer outputs are evidence inputs, not authority.
- Competitor content may inform gap structure, user intent, SERP patterns, and feature parity. It must not be copied, paraphrased, translated, ranked dishonestly, or used as a source of claims.
- fap-web is the public runtime and renderer, not editorial authority.
- Search Channel queues require separate exact approval.
- CMS draft, write, publish, promotion, rollback, and import workflows require their own controlled gates.

## 3. Input Families

Allowed input families:

| Input family | Role | Boundary |
| --- | --- | --- |
| `gsc_performance` | Queries, pages, CTR, impressions, average position, country/device signals. | Must pass GSC data-quality gate; raw credentials and unsafe URLs forbidden. |
| `baidu_search_signal` | Baidu-side landing/query/indexing observations when available. | Observation only; no automatic push or submit. |
| `runtime_url_truth` | Public URL identity, page family, locale, indexability, canonical state. | Backend/CMS URL Truth wins over public observation drift. |
| `cms_inventory` | Existing articles, topics, test landings, content pages, personality profiles, career pages. | Draft/private records must not become public planning targets unless explicitly allowed. |
| `internal_link_graph` | Existing and missing internal links by entity, topic, page family, and locale. | Must not infer private result/order/share/take routes as targets. |
| `competitor_observation` | Operator-reviewed notes about competitor page families, headings, content depth, SERP modules, and product positioning. | No scraped copy, screenshots, reviews, ratings, prices, testimonials, or proprietary wording. |
| `serp_observation` | Manual or approved read-only SERP notes: result types, intent mix, dominant page family, snippets, PAA-like questions, video/local/news modules. | No automated search submit or provider mutation. |
| `social_context` | Public topic demand and phrasing from approved channels such as Zhihu, Xiaohongshu, Reddit, Quora, Medium, X, LinkedIn. | Advisory only; no fake UGC, no private account data, no mass posting. |
| `geo_prompt_observation` | AI answer selection and absorption notes from approved prompt panels. | No private result URLs or user data. |

Forbidden inputs:

- raw private result, order, payment, share, report, account, token, invite, recovery, checkout, or auth URLs;
- raw credentials, cookies, service-account JSON, private keys, tokens, emails, phone numbers, payment IDs, order IDs, or attempt IDs;
- copied competitor body text, review text, rating claims, pricing claims, screenshots, or proprietary labels;
- unreviewed AI-generated search data presented as truth;
- production raw logs unless a separate approved log-review task exists.

## 4. Core Workflow

### Step 1: Define the research scope

Every run must lock:

- locale: `zh`, `en`, or `bilingual`;
- page family: article, topic, test landing, personality profile, career page, method/trust page, competitor/category alternative;
- assessment family: MBTI, Big Five, Enneagram, RIASEC, IQ, EQ, clinical/screening, career;
- business goal: test start, result view, article-to-test click, trust page support, GEO citation, internal-link repair, content gap closure;
- forbidden actions.

### Step 2: Build the query universe

Create a query universe from approved signals:

- GSC live query/page rows when data-quality gate passes;
- backend CMS inventory and existing page metadata;
- approved manual keyword notes;
- operator-reviewed competitor and SERP notes;
- user-scenario language from approved public channels;
- internal content gaps and entity graph gaps.

Classify every query or query family:

- `money_intent`: user wants to take a test now;
- `explainer_intent`: user asks what it is, whether it is accurate, how to read results;
- `scenario_intent`: user has a real problem such as major choice, career change, team communication, relationship reflection;
- `entity_intent`: user searches a type, trait, model, career, topic, or result term;
- `comparison_intent`: user compares models, tests, or providers;
- `trust_intent`: user asks privacy, method, reliability, official status, data use, or limitations.

### Step 3: Run competitor content analysis

Competitor analysis should answer:

- Which page family ranks: test landing, article, topic, directory, type profile, tool, forum thread, video, PDF, or official page?
- What user job does the winning page satisfy?
- What information blocks appear repeatedly: definition, table, example, FAQ, result explanation, method, privacy, social proof, CTA?
- What does the competitor leave weak: local Chinese scenarios, free complete result clarity, claim boundaries, bilingual parity, career use cases, privacy, no-account flow, method page support?
- Which FermatMind asset could answer the same intent with more original value?

Allowed competitor notes:

- page type;
- approximate structure;
- observed intent;
- high-level content depth;
- visible claim categories;
- missing user questions;
- internal-link pattern;
- product/access model at a high level when operator-reviewed.

Forbidden competitor use:

- copying headings, examples, FAQ, descriptions, type labels, report sections, metadata, schema, screenshots, review claims, rating counts, prices, or testimonials;
- claiming FermatMind is better, more accurate, cheaper, or more official unless separately source-backed and legal/claim reviewed;
- building `best alternative to X` pages without the competitor alternative policy gate.

### Step 4: Interpret SERP shape

SERP analysis should record:

- dominant intent;
- dominant page family;
- freshness expectation;
- SERP modules observed;
- whether query is brand, non-brand, competitor, informational, transactional, navigational, or mixed;
- whether a new asset is needed or an existing page should be updated;
- whether the query has a safe claim path.

Do not treat one SERP sample as stable truth. Record locale, country, device, date, reviewer, and source notes.

### Step 5: Cluster topics

Cluster queries by:

- assessment family;
- user job;
- lifecycle stage: before test, during result interpretation, after result, career/application, trust/research;
- intent layer: money, explainer, scenario, entity, comparison, trust;
- locale and market;
- required claim boundary;
- owner page.

Each cluster must have:

- one primary owner page;
- supporting pages;
- internal-link plan;
- canonical and locale expectation;
- content asset type;
- claim risk;
- publication gate.

Do not create two owner pages for the same primary query family unless there is a clear locale, page-family, or intent split.

### Step 6: Rank page opportunities

Score each opportunity using explainable fields:

| Field | Meaning |
| --- | --- |
| `search_demand` | GSC impressions, manual keyword evidence, or SERP/social demand proxy. |
| `ranking_gap` | Current position, missing page, weak owner page, or no indexable asset. |
| `ctr_gap` | High impression + low CTR opportunity. |
| `conversion_fit` | Ability to drive `article_to_test_click`, `start_attempt`, `submit_attempt`, or `view_result`. |
| `free_result_fit` | Strength of free-test/free-complete-result value proposition. |
| `information_gain` | Ability to be more useful than current SERP competitors. |
| `entity_graph_value` | How much the asset strengthens tests, topics, profiles, career, and result interpretation links. |
| `geo_extractability` | Direct answer, definition, table, FAQ, boundary, and citation readiness. |
| `claim_risk` | Clinical, career, IQ, hiring, salary, official-affiliation, competitor, or guarantee risk. |
| `authority_readiness` | Whether backend/CMS fields and publication gates exist. |
| `production_cost` | Content, review, media, import, QA, and runtime effort. |

Default ranking:

1. P0: high demand, clear owner page, low claim risk, direct product path, authority ready.
2. P1: meaningful demand or strategic entity gap, moderate review needed, authority mostly ready.
3. P2: useful cluster support, higher production cost or claim review required.
4. P3: advisory, monitor-only, not ready for content package.
5. HOLD: missing authority, unsafe claim path, private-data risk, competitor/legal risk, or unclear owner.

### Step 7: Plan content assets

For each approved opportunity, generate a planning record only:

- `asset_id`
- `locale`
- `page_family`
- `owner_page`
- `query_family`
- `intent_layer`
- `target_user_problem`
- `proposed_asset_type`
- `primary_cta`
- `secondary_links`
- `required_answer_blocks`
- `required_tables_or_checklists`
- `required_boundaries`
- `competitor_gap_notes`
- `serp_notes`
- `source_evidence_refs`
- `claim_review_required`
- `legal_review_required`
- `cms_authority_source`
- `dry_run_candidate`
- `blocked_actions`

Planning records must not include final body copy, final FAQ copy, final title/meta, final CTA copy, or publishable article text unless a separate CMS content package task explicitly authorizes draft generation.

## 5. Article Topic Selection Rules

Article topics should be selected when they satisfy at least one of:

- they support a core test landing page;
- they explain how to read a free complete result;
- they answer a high-intent scenario that can naturally lead to a test;
- they fill an entity graph gap;
- they repair a GSC CTR/ranking opportunity;
- they support method/trust/claim safety for higher-risk pages;
- they strengthen Chinese or English parity for an already-authoritative content surface.

Avoid article topics that:

- duplicate an existing owner page;
- target a query better served by a test landing page, topic, profile, or method page;
- require unsupported clinical, career, salary, hiring, official, or accuracy claims;
- exist only because a competitor has a page;
- cannot naturally link to a FermatMind next step;
- would become generic psychology content without product relevance.

## 6. Bilingual Content Opportunity Mining

Chinese opportunity mining should prioritize:

- 高考志愿、专业选择、调剂、父母意见、转专业、就业方向;
- 大学生职业测评、第一份工作、转行前判断兴趣;
- MBTI / RIASEC / Big Five / Enneagram 在职场沟通、关系、内耗、压力中的解释;
- 免费测试、免费完整结果、结果怎么看;
- 本土用户表达，而不是英文直译。

English opportunity mining should prioritize:

- free personality test with complete results;
- free MBTI-style / 16 types test;
- Big Five and RIASEC comparison and interpretation;
- career interest test for students and career changers;
- type/trait-to-workstyle explanation;
- method, privacy, and non-diagnostic boundaries.

For bilingual opportunities:

- do not assume direct translation is the best strategy;
- identify locale-specific search intent;
- preserve equivalent claim boundaries;
- keep canonical and hreflang decisions under backend/frontend discoverability contracts;
- create separate owner pages when zh and en SERPs show different intent.

## 7. Competitive Content Gap Analysis

A competitor gap exists only when all are true:

1. The competitor page satisfies a real user intent relevant to FermatMind.
2. FermatMind has or can create a backend-authoritative public asset for that intent.
3. FermatMind can add original value through free complete result access, local context, better boundaries, better explanation, or stronger internal next steps.
4. Claim/legal boundaries are safe.

Gap categories:

- `access_model_gap`: competitor has paid/limited report; FermatMind can safely explain free complete result.
- `scenario_gap`: competitor content is generic; FermatMind can answer a Chinese or bilingual real-world scenario.
- `method_boundary_gap`: competitor overclaims or under-explains boundaries; FermatMind can be clearer and safer.
- `entity_graph_gap`: competitor links types/topics/careers better; FermatMind needs stronger internal graph.
- `result_interpretation_gap`: competitor explains result usage better; FermatMind needs result guides.
- `trust_gap`: method/privacy/reliability/data pages are missing or weak.
- `format_gap`: SERP expects table/checklist/FAQ/video; FermatMind lacks that block.

No competitor gap may produce public copy until the content package, claim review, and CMS gates pass.

## 8. Output Artifacts

Recommended artifacts:

- `keyword_universe.csv`
- `query_owner_matrix.csv`
- `competitor_gap_matrix.csv`
- `serp_observation_notes.md`
- `topic_cluster_map.json`
- `page_opportunity_scorecard.csv`
- `content_asset_plan.md`
- `cms_dry_run_candidate_manifest.json`
- `claim_review_queue.csv`
- `next_7_day_execution_plan.md`

Artifacts must be sanitized and must not contain raw private URLs, credentials, competitor copied text, private user data, or publishable body copy.

## 9. Relationship to Existing SEO Agent Docs

This skill complements:

- `backend/docs/seo/seo-agent-run-control-packet.md`
- `backend/docs/seo/seo-agent-run-orchestrator.md`
- `backend/docs/seo/seo-agent-opportunity-aggregator.md`
- `backend/docs/seo/opportunity-queue-readonly.md`
- `backend/docs/seo/seo-agent-gsc-opportunity-auto-draft.md`
- `backend/docs/seo/competitor-alternatives-source-ledger.md`
- `backend/docs/seo/seo-article-topic-priority-2026-06-05.md`
- `backend/docs/seo/skills/fermat-seo-ops.md`
- `backend/docs/seo/skills/fermat-ai-seo-geo.md`
- `backend/docs/seo/skills/fermat-claim-boundary.md`

Those documents define opportunity queues, execution boundaries, claim rules, and AI/GEO readiness. This document defines the missing research and planning layer that comes before CMS dry-run package generation.

## 10. Stop Conditions

Stop and return a HOLD recommendation if:

- the task requires competitor scraping or copied competitor content;
- raw SERP data would require credentials, private sessions, or prohibited provider access;
- a proposed topic needs unsupported diagnosis, clinical, hiring, admission, salary, IQ-certification, official affiliation, or guarantee claims;
- no backend/CMS authority source exists for the proposed page family;
- the proposed owner page would conflict with an existing primary query owner;
- the opportunity depends on private result/order/share/payment/take URLs;
- the request asks to publish, write CMS, submit search URLs, or deploy from the planning task;
- source evidence is too thin to justify content planning.

## 11. Final Output Template

Each run should end with:

```text
Verdict: GO_TO_CONTENT_PLAN | GO_TO_CMS_DRY_RUN_CANDIDATE | DEFER | HOLD

Scope:
- locale:
- page families:
- assessment families:
- source families:

Top opportunities:
1. query_family / owner_page / asset_type / score / reason
2. ...

Cluster map:
- cluster:
- owner:
- support assets:
- internal links:

Competitor gap summary:
- competitor pattern:
- FermatMind original advantage:
- forbidden claims:

Required gates:
- claim review:
- legal review:
- CMS authority:
- runtime QA:
- search readiness:

Blocked actions:
- CMS write:
- publish:
- search submit:
- scrape/copy competitor content:

Next recommended action:
- ...
```
