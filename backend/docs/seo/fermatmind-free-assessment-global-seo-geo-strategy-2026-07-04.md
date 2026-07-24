# FermatMind Free Assessment Global SEO/GEO Strategy

Date: 2026-07-04
Status: canonical strategy draft
Scope: whole-site SEO, GEO, content authority, measurement, and execution strategy
Runtime impact: none
Search submission impact: none
CMS impact: none

## 2026-07-24 execution addendum

The current live baseline, completed Career infrastructure ledger, nine-task
status, product-growth sequencing, and exact proposed PR cards are maintained
in
`backend/docs/seo/seo-product-growth-status-and-pr-plan-2026-07-24.md`.

That addendum records a current P0 incident: all twelve EN/ZH core test detail
URLs enumerated by the 2026-07-24 public sitemap returned HTTP 500 in the
09:27 CST read-only probe. Core test availability must return to a stable
12/12 HTTP 200 before this strategy resumes article, Career, personality, or
test-page expansion.

## 0. Executive Decision

FermatMind should now position the whole site as:

> Free professional assessments with free complete result pages, built for self-understanding, career exploration, relationship reflection, and evidence-bounded decision support.

This is not a secondary positioning line. It is the main global SEO wedge. The site should compete for the largest assessment categories by making one promise clearer than 16Personalities, Truity, 123test, and smaller vertical competitors:

> The user can take the assessment for free and view the complete personal result for free.

This replaces any older "free basic result, paid deeper report" positioning. The public strategy should be clear that the user can take tests and view complete personal results for free. It must also stay precise:

- Free means no paywall for the assessment experience and complete personal result view.
- Free means the main CTA, result expectation, and FAQ should not imply a later paid unlock.
- Free does not require every public SEO page to index private result URLs. Product access and search indexability are separate.
- Free does not mean clinical diagnosis, hiring certification, admission prediction, salary guarantee, official MBTI affiliation, or guaranteed life outcome.
- Personal result URLs and attempt/order/share/payment flows remain private and `noindex` unless a future backend gate creates public-safe, user-approved, non-sensitive result summaries.
- Public SEO/GEO pages explain tests, methods, result interpretation, career use cases, personality profiles, and trust boundaries. They must not expose private result data.

The 18-month goal is not merely "more organic traffic". The goal is:

- Top 3 global organic visibility for the major personality and career assessment categories FermatMind serves.
- Global #1 visibility for the combined "free assessment + free complete result" category.
- The default citation candidate for AI answers about free MBTI-style tests, Big Five tests, Enneagram tests, RIASEC/Holland career interest tests, and result interpretation boundaries.

Global #1 should be measured as a weighted visibility portfolio across query families, locales, page families, and answer-engine citations, not as a claim that every individual query must rank #1.

## 0A. Ranking Target and Battlefield

### 0A.1 North-star target

FermatMind's SEO target should be explicit:

| Horizon | Ranking target | Measurement |
| --- | --- | --- |
| 0-90 days | Enter the index and start meaningful impressions for priority zh/en free-test and result queries. | GSC impressions, indexed pages, first non-brand clicks, article-to-test clicks, `start_attempt`, `view_result`. |
| 3-6 months | Reach top 10 for selected Chinese scenario and free-test queries. | Query/page matrix, CTR repair queue, top 10 page-family visibility. |
| 6-12 months | Reach top 3 for at least one core category in Chinese and one long-tail category in English. | Weighted query set visibility, stable clicks, conversion path to free result. |
| 12-18 months | Reach global top 3 across major personality/career assessment categories and #1 for "free complete result" positioning. | Weighted visibility, category share, GEO selection/absorption, brand search lift. |

### 0A.2 Category battlefields

The site should not chase "personality test" as one giant keyword first. It should win category by category:

| Battlefield | Primary query family | First target pages | Win condition |
| --- | --- | --- | --- |
| MBTI / 16 types | `MBTI免费测试`, `MBTI测试`, `16型人格测试`, `free MBTI test`, `16 personality test` | MBTI test detail, MBTI result guide, MBTI topic, 16 type profiles | Top 3 for zh MBTI free-test family; top 10 then top 3 for English free/full-result variants. |
| Big Five / OCEAN | `大五人格测试`, `Big Five personality test`, `OCEAN personality test` | Big Five test detail, Big Five guide, MBTI vs Big Five | Top 3 for Chinese Big Five free-test family; top 10 English. |
| Enneagram | `九型人格测试`, `Enneagram test`, `Enneagram types` | Enneagram test detail, type guides, motivation/workplace articles | Top 3 Chinese; top 10 English. |
| RIASEC / Holland | `霍兰德职业兴趣测试`, `RIASEC test`, `career interest test`, `Holland Code test` | RIASEC test detail, gaokao/major cluster, career graph | Top 3 Chinese RIASEC and career-interest family; top 10 English. |
| Free personality test | `免费性格测试`, `free personality test`, `personality test free results` | tests hub, personality hub, six hub pages | Global #1 long-term for free complete result positioning. |
| Result interpretation | `MBTI结果怎么看`, `how to read MBTI result`, `what does my RIASEC result mean` | result interpretation hub, scale result guides, articles | Own answer blocks and AI citations, not only blue-link rankings. |
| Scenario clusters | gaokao, major choice, career change, workplace communication, relationship reflection | articles, career pages, topic pages | Chinese top 3 pockets where competitors are weak or generic. |

### 0A.3 Ranking principle

To reach global top 3, each category needs all four layers:

1. A crawlable money page with the free test/free result promise.
2. A result interpretation page that explains what the free result contains and how to use it.
3. A topic/profile/entity graph that makes the model understandable.
4. Scenario articles that prove real-life usefulness and feed users back into the test.

If any layer is missing, FermatMind may rank for isolated long-tail queries but will not become a category authority.

## 1. Why This Document Exists

The backend repository already contains many useful SEO/GEO assets, but they are mostly scoped to audits, operating contracts, individual page families, or infrastructure:

- `backend/docs/seo/seo-ops-daily-runbook.md` defines daily SEO operations.
- `backend/docs/seo/seo-agent-run-control-packet.md`, `backend/docs/seo/seo-agent-run-orchestrator.md`, and adjacent SEO Agent docs define evidence, source classification, and controlled execution lanes.
- `backend/docs/seo/gsc-data-quality-gate.md`, `backend/docs/seo/gsc-live-readonly-adapter.md`, `backend/docs/seo/gsc-live-readmodel-consumption-contract.md`, and `backend/docs/seo/gsc-weekly-readonly-automation-plan.md` define GSC data-quality and read-model consumption.
- `backend/docs/seo/opportunity-queue-readonly.md` and `backend/docs/seo/seo-agent-opportunity-source-expansion.md` define the opportunity queue boundary.
- `backend/docs/seo/semantic-internal-link-graph-contract.md` defines semantic internal-link graph contracts.
- `backend/docs/seo/competitor-alternatives-source-ledger.md` defines competitor-source and alternative-page boundaries.
- `backend/docs/seo/content-publish-rehearsal-contract.md`, `backend/docs/seo/content-publish-rehearsal-dry-run.md`, and controlled CMS publish docs define publish gates.
- `backend/docs/seo/skills/fermat-seo-research-content-planning.md` defines article topic selection, bilingual content opportunity discovery, competitor content analysis, SERP interpretation, topic clustering, opportunity ranking, and content asset planning.
- `backend/docs/seo/personality/*` records MBTI/personality profile content packages, claim gates, QA, and import evidence.
- `backend/docs/seo/research-seo-geo-search-channel-contract.md` and `backend/docs/seo/research-url-truth-observation.md` define research, GEO, and URL-truth observation contracts.

The fap-web repository also contains rendering-side discoverability docs for canonical URLs, sitemap, `llms.txt`, structured data, metadata, and frontend noindex behavior. Those are supporting renderer contracts. This backend document is the source strategy because backend/CMS owns content, publication state, public SEO fields, URL Truth inputs, search-channel queues, and controlled CMS operations.

What was missing is one current, site-wide strategy that reconciles:

- the new all-free business posture,
- the free complete result promise,
- public SEO and private result privacy,
- CMS/backend authority,
- existing FermatMind assets,
- competitor lessons from 16Personalities, 123test, and Truity,
- GEO answer-engine readiness,
- GSC/analytics/SEO issue queue feedback loops,
- and a practical 18-month roadmap.

This document is that strategy layer. It does not authorize implementation by itself.

## 2. Current Asset Reading

### 2.1 Product and SEO assets already present

FermatMind already has enough product surface to build a durable search graph:

| Asset family | Current role | Strategic use |
| --- | --- | --- |
| Home | Brand entry | Explain "free professional assessment" and route users to tests, articles, personality, career, and trust pages. |
| Tests hub | Assessment directory | High-intent entry for users who know they want a test but not which one. |
| Test detail pages | Money-intent pages | Capture "free MBTI test", "RIASEC test", "Big Five test", "Enneagram test", "IQ test", "EQ test" intent. |
| Take pages | Product flow | Must remain private-flow oriented and not be treated as public SEO pages. |
| Result pages | Free personal result experience | Free to user, but private/noindex by default. Public SEO uses interpretation pages, not private result URLs. |
| Articles | Scenario and explainer content | Capture "what should I do", "difference between", "is it accurate", and career/personality long-tail. |
| Topics | Cluster hubs | Connect articles, tests, profiles, and career pages by entity and question type. |
| Personality pages | Entity authority | Build MBTI type and later Big Five/Enneagram/RIASEC profile authority with original content. |
| Career pages | Career graph | Connect RIASEC, jobs, major choice, career exploration, and public career entities. |
| Trust/method pages | E-E-A-T and claim boundary | Explain methodology, privacy, limits, data handling, and responsible use. |
| Ops/SEO middle platform docs | Operating system | Measure search, indexing, attribution, and issue queues without mutating content authority. |

### 2.2 Existing constraints that must remain

- Frontend is the renderer and deterministic public runtime, not editorial authority for CMS-backed surfaces.
- Backend/CMS/public APIs own publishable copy, landing surfaces, content pages, articles, topics, personality profiles, career jobs, SEO fields, and discoverability eligibility.
- Sitemap, `llms.txt`, metadata, structured data, and public URL truth must enumerate from backend/CMS/public APIs where those authority surfaces exist.
- Empty or missing CMS responses should render empty/error/minimal states, not frontend fallback editorial content.
- SEO Intelligence and issue queues observe and classify; they do not publish content, rewrite CMS, submit search URLs, or create pSEO pages.
- Private flows include result, order, take, share, pay, checkout, account, auth, token, invite, recovery, and report-download URLs. They must not become SEO URL entities.

## 3. Competitive Reference

This strategy uses competitors as structural benchmarks, not copy sources. FermatMind must not copy their wording, proprietary labels, screenshots, reviews, ratings, or report structure.

### 3.1 16Personalities

Observed strengths:

- One dominant personality-test entry connected to type descriptions, articles, career, team, and professional report products.
- Clear type library and resource navigation.
- Strong social proof, language footprint, and memorable brand voice.
- Public counters and testimonials create perceived scale.

What FermatMind should borrow:

- A recognizable product promise above the fold.
- Strong type/profile entity library.
- Public explanation pages that turn result terms into searchable entities.
- Language and type navigation that is easy for users and crawlers.

What FermatMind should not borrow:

- Over-confident "freakishly accurate" style language unless evidence and legal review allow it.
- Testimonial/review claims without verified permission and source authority.
- Heavy type determinism.

Reference: [16Personalities homepage](https://www.16personalities.com/), [16Personalities personality types](https://www.16personalities.com/personality-types)

### 3.2 123test

Observed strengths:

- Broad directory of tests across career, IQ, personality, competencies, work values, team roles, learning style, conflict management, and more.
- Home page makes "free tests" explicit and connects tests to privacy, reliability, articles, and company trust.
- Many articles and "all tests" pages create a wide internal-link graph.
- Multi-language footprint is prominent.

What FermatMind should borrow:

- Directory-first IA for all public assessments.
- Test-family hubs and articles that support direct test pages.
- Privacy and no-account/no-friction language where true.
- Usage-scale proof only when backend counters are authoritative.

What FermatMind should not borrow:

- Dense "test supermarket" navigation that weakens FermatMind's professional brand.
- Strong validity/reliability claims without first-party evidence and reviewer approval.
- Official or certification language for IQ, career, or psychological outcomes.

Reference: [123test homepage](https://www.123test.com/), [123test all tests](https://www.123test.com/all-tests/)

### 3.3 Truity

Observed strengths:

- Strong category segmentation: personality tests, career tests, workplace tests, business, and personality library.
- Test pages combine direct test UI, explanatory content, reviewer signals, FAQ, and links to personality/career entities.
- Explicit distinction between free overview and paid report is part of their older model.
- Strong support for coaches, teams, API, and workplace use.

What FermatMind should borrow:

- Category hubs by user job: personality, career, workplace/team, relationships, emotional intelligence.
- Deep test pages that answer "what is this", "how long", "is it free", "what will I see", "how should I use it".
- Reviewer/method proof only after the real evidence system exists.
- Business/team/API pages only if the backend/CMS owner has approved that product surface.

What FermatMind should not borrow:

- Free-overview/paid-full-report framing, because FermatMind's current strategy is all free.
- "Most accurate" or "validated" claims unless backed by public technical documentation.
- Competitor-name comparison pages without legal and claim review.

Reference: [Truity test directory](https://www.truity.com/page/personality-tests-and-career-quizzes), [Truity TypeFinder page](https://www.truity.com/test/type-finder-personality-test-new)

## 4. Strategic Thesis

FermatMind can compete globally if it wins a different position from incumbents:

| Incumbent pattern | FermatMind counter-position |
| --- | --- |
| Free test but limited/paid full report | Free test plus free complete result page. |
| Entertainment-first type identity | Professional self-understanding with explicit method and claim boundaries. |
| English-first global content | Chinese + English first, then multilingual expansion from proven clusters. |
| One dominant test brand | Multi-assessment graph: MBTI, Big Five, Enneagram, RIASEC, IQ, EQ, career, topics, personality, articles. |
| Static SEO pages | CMS-backed SEO/GEO surfaces measured by GSC, analytics, issue queues, and GEO monitor prompts. |
| FAQ-only answer optimization | Evidence containers with definitions, comparisons, numbers, examples, boundaries, and next steps. |

The durable formula should be:

```text
Indexable public pages
  x backend/CMS entity consistency
  x high information gain
  x extractable answer blocks
  x conservative claim boundaries
  x internal-link graph
  x external context and brand co-occurrence
  x GSC/analytics/GEO feedback loops
```

## 5. Source Authority Model

### 5.1 What each layer owns

| Layer | Owns | Must not own |
| --- | --- | --- |
| Backend/CMS | Landing copy, page blocks, articles, topics, content pages, profiles, career pages, SEO fields, publication state, discoverability gates. | Browser-only tracking state, frontend rendering details. |
| Backend business truth | Attempts, result availability, orders, payment history if any legacy records exist, benefit grants, test metrics, free/full-result entitlement. | Search ranking interpretation. |
| Frontend | Rendering, routing, caching, noindex/private guards, schema projection, safe CTA attribution, deterministic public display. | Publishable editorial copy or CMS fallback content. |
| SEO Intelligence | URL observation, GSC/Baidu/GA4 summaries, issue queues, sanitized attribution, search-readiness reports. | CMS publishing, search submission, content generation. |
| Human review | Claim review, method review, legal/trademark review, publication approval. | Direct mutation without authority workflow. |

### 5.2 Free result authority

The free complete result claim must be powered by backend/CMS/business truth, not hardcoded frontend copy. Required authority fields or equivalent backend-readable facts:

- `commercial_state=free` or equivalent.
- `paywall_mode=free_only` or equivalent.
- `free_full_report_mode=true` or equivalent.
- scale/form-level result sections available without payment.
- public landing copy fields that can state "free complete result" safely.
- deprecated paid/unlock copy removed or marked as legacy/non-public.

The target user-facing state is:

- "Start free" means the test starts without payment.
- "Complete free" means the user can finish the assessment without payment.
- "View result free" means the complete personal result page is accessible without payment.
- "Continue free" means related public interpretation pages, profile pages, and result guides do not steer the user into a paid unlock.

Until the authority fields are reconciled for every scale, public copy should say:

- "Free test and free result page" when confirmed.
- "Free complete result is available for this assessment" only when confirmed per scale.
- "Some historical UI or documentation may still reference paid/unlock states; those are not the current public product promise" only in internal docs, not public pages.

Deprecated product language should be removed from public surfaces after authority reconciliation:

- "basic result",
- "preview only",
- "unlock full report",
- "paid full report",
- "upgrade for complete report",
- "基础结果免费",
- "完整报告付费解锁".

If a legacy internal state still exists, the frontend should not invent a contradiction. The fix belongs in backend/CMS/product authority, then frontend rendering can faithfully show the free mode.

### 5.3 Private result boundary

Free complete result pages are a product promise, not a search-indexing promise.

Rules:

- `/result/*`, `/results/lookup`, `/orders/*`, `/share/*`, `/take`, payment, checkout, account, auth, token, invite, and recovery URLs remain private/noindex.
- Public SEO pages may explain what result sections include, but must not reveal real user result data.
- Future public-share result pages require backend approval, user consent, safe slug/id design, no sensitive identifiers, and a separate indexability policy.

## 6. Whole-Site Page Architecture

### 6.1 Page families and jobs

| Page family | Primary search job | GEO job | Authority | Priority |
| --- | --- | --- | --- | --- |
| Home | Brand and category entry | Define FermatMind in one extractable answer | CMS/content page + frontend renderer | P0 |
| Tests hub | "free personality test", "free career test", "online assessment" | Compare assessment families | `landing_surfaces` / catalog API | P0 |
| Test detail | "free MBTI test", "Big Five test", "RIASEC test" | Answer what the test measures, time, result, boundary | backend scale catalog + landing surface | P0 |
| Take page | Start/complete product flow | None; not a public answer target | backend form + frontend product flow | noindex |
| Personal result | Free complete personal result | None by default; private | backend result API | noindex |
| Result interpretation hub | "how to read MBTI result", "what does RIASEC result mean" | Explain result sections generically | CMS/content page | P1 |
| Articles | Scenario and explainer long-tail | Answer specific questions with evidence containers | CMS Article | P0/P1 |
| Topics | Cluster and entity hubs | Summarize a topic and route to assets | CMS Topic/public API | P1 |
| Personality profiles | Type/trait/entity long-tail | Provide extractable type summaries and comparisons | CMS/public personality API | P0/P1 |
| Career pages | RIASEC/career exploration | Describe job fit signals and boundaries | backend career API/CMS | P1 |
| Method/trust pages | Accuracy, privacy, method, limits | Preserve safe claim boundaries in AI answers | `content_pages` | P0/P1 |
| Competitor/category alternatives | Comparison intent | Explain fit without superiority claims | CMS + legal/claim approval | P2 |
| Business/team pages | Organization/team testing | Explain product fit if approved | backend/CMS product surface | P2 |

### 6.2 Six flagship assessment hubs

The six public assessment hubs should be treated as the first global SEO/GEO spine:

| Scale | User intent | Public promise | Boundary |
| --- | --- | --- | --- |
| MBTI / 16 types | personality type, communication, work style | Free MBTI-style personality test with free complete result | Not official MBTI, not diagnosis, not hiring guarantee. |
| Big Five / OCEAN | trait dimensions, personality science | Free Big Five test with trait result | Not clinical diagnosis, not deterministic career recommender. |
| Enneagram | motivation, interpersonal patterns | Free Enneagram test with type result | Avoid type certainty and destiny claims. |
| RIASEC / Holland | career interests, major/career exploration | Free career interest test with result | Not admission, job, salary, or guaranteed career outcome. |
| IQ / Raven-style | reasoning practice/context | Free reasoning/IQ-style assessment result | Not official IQ certification, diagnosis, or admission proof. |
| EQ | emotional-awareness reflection | Free EQ-style result | Not mental-health diagnosis or therapy substitute. |

## 7. Keyword and Content Strategy

### 7.1 Three-layer SEO stack

| Layer | Query type | Page families | Example intents |
| --- | --- | --- | --- |
| Money intent | User wants to take a test now | Test detail, tests hub | 免费MBTI测试, free personality test, RIASEC test, Big Five test, IQ test free |
| Explainer intent | User wants to understand a model or result | Test detail, topic, article, method, result interpretation | MBTI准吗, MBTI vs Big Five, what is RIASEC, how to read Big Five result |
| Scenario intent | User has a life/work/career problem | Article, topic, career, personality | 不知道适合什么职业, college major test, team communication styles, relationship compatibility |

### 7.2 Site-wide pillar map

| Pillar | Core pages | Supporting clusters |
| --- | --- | --- |
| Free tests | `/tests`, six test detail pages | all-tests directory, test categories, duration/result/free FAQs |
| Free complete results | generic result interpretation hub, scale result guide pages | "what result includes", "how to read", "why result changed", "result is not diagnosis" |
| Personality | `/personality`, MBTI 16 types, Big Five traits, Enneagram types | type careers, type relationships, type communication, MBTI vs Big Five |
| Career | RIASEC, career/jobs, career guides | gaokao/major choice, college students, career change, job fit, work values |
| Methods and trust | method boundaries, assessment science, privacy, data说明, reliability/validity when available | accuracy questions, official/non-official boundaries, clinical boundaries |
| Articles and topics | article hub, topic pages | decision guides, comparison pages, how-to pages, common mistakes |
| Business/team | approved B2B pages only | team assessments, coaches, API/research only after product authority exists |
| Competitor/category alternatives | category comparisons first | "free MBTI test alternatives" only after legal/claim gates |

### 7.2A Free-result moat

The free-result moat must be visible and repeated consistently, but not spammed:

| Surface | Required free message | Required boundary |
| --- | --- | --- |
| Test detail hero | Free test, free complete result, time/question count. | Not diagnosis, official certification, hiring/admission/salary guarantee. |
| Test detail FAQ | Is it free? What result will I see? Do I need to pay? Can I use it for career/relationship decisions? | Results support reflection; they do not decide outcomes. |
| Take page | Continue test without paid interruption. | No SEO indexing and no search-target copy needed. |
| Result page | Complete result is accessible for free. | Private/noindex; result is personal guidance, not official proof. |
| Result interpretation page | Explain result sections generically. | Do not expose private results or imply clinical/certification use. |
| Articles | Mention the relevant free test naturally only when it matches intent. | Avoid over-CTA and avoid false precision. |
| Social/external snippets | "Free test + free complete result" as the hook. | No attack on competitors, no "most accurate" claim. |

### 7.2B Priority query ownership matrix

Each priority query family needs one owner page. Do not let multiple pages compete for the same primary query.

| Query family | Owner page type | Supporting pages |
| --- | --- | --- |
| `免费MBTI测试` / `free MBTI test` | MBTI test detail | MBTI topic, MBTI result guide, 16 type profiles, MBTI articles |
| `16型人格测试` / `16 personality test` | MBTI test detail or personality hub by locale | type profiles, result guide, comparison articles |
| `免费性格测试` / `free personality test` | tests hub or personality hub | six flagship tests, category articles |
| `大五人格测试` / `Big Five personality test` | Big Five test detail | Big Five guide, MBTI vs Big Five, trait profiles |
| `九型人格测试` / `Enneagram test` | Enneagram test detail | type guides, workplace/motivation articles |
| `霍兰德职业兴趣测试` / `RIASEC test` | RIASEC test detail | career hub, gaokao/major articles, career graph |
| `职业兴趣测试` / `career interest test` | RIASEC test detail or career hub by locale | career guides, job pages, comparison articles |
| `结果怎么看` / `how to read result` | result interpretation hub or scale result guide | test detail, FAQ, model articles |
| `MBTI准吗` / `is MBTI accurate` | explainer article or method page | MBTI test detail, Big Five comparison, method boundary |

### 7.3 Chinese-first priority

The Chinese SEO wedge should use scenarios where FermatMind can produce higher local information gain than translated global competitors:

- 高考志愿和专业选择.
- 大学生职业测评.
- 转行和职业方向.
- MBTI 与霍兰德如何一起看.
- MBTI 与大五人格差异.
- 职场沟通、团队协作、上下级摩擦.
- 恋爱关系和相处方式，但避免匹配/命运承诺.
- 免费测试、免费完整结果、无需付费查看报告.

### 7.4 English priority

English should not begin by copying Chinese pages one-to-one. It should start from high-intent global categories:

- free personality test with full results.
- free MBTI-style / 16 types test.
- free Big Five personality test.
- free career interest test / Holland Code / RIASEC.
- MBTI vs Big Five.
- career test for students.
- personality type careers.
- what does my result mean.

### 7.5 External context and distribution

Ranking against 16Personalities, 123test, and Truity cannot rely only on on-site pages. The off-site strategy should create clean, non-spammy context around FermatMind as the free professional assessment brand.

| Tier | Channels | Cadence | Purpose | Rules |
| --- | --- | --- | --- | --- |
| S | 知乎, 微信公众号, 小红书, 百度百家号, 今日头条 | Pair with major Chinese SEO articles or daily priority content. | Chinese discovery, Baidu ecosystem coverage, scenario distribution. | No fake UGC, no copied article dumps, no competitor attacks. |
| A | X, LinkedIn, Medium, Quora, Reddit | 2-4 selected posts per week. | English trust, international context, professional audience. | Answer questions directly; link only when useful. |
| B | YouTube, Instagram, TikTok, B站, 抖音, 微博, Facebook | 2-3 per week or batch campaigns. | Video discovery, brand recall, secondary touch. | Short-form should point to stable public pages, not private results. |

Every distribution item should map to one canonical page, one user problem, and one safe claim. External posts should reinforce the free-test/free-complete-result promise, not manufacture social proof or repeat thin snippets.

## 8. GEO and Answer-Engine Strategy

GEO is not "add FAQ schema everywhere". FermatMind pages should be built so answer engines can cite and absorb accurate, bounded information.

### 8.1 Evidence container standard

Each indexable public page that is meant to win AI answers should include CMS-authored, visible, extractable blocks:

| Block | Purpose |
| --- | --- |
| Direct answer | 80-160 words that answer the query without hype. |
| Definition | What the test/model/entity means. |
| Use-case boundary | What it helps with and what it cannot decide. |
| Comparison table | When the query has alternatives or adjacent models. |
| How-to sequence | How to take the test, read the result, or apply it. |
| Result explanation | What sections the free complete result includes, without private data. |
| Claim caveat | Not diagnosis, not official certification, not job/admission/salary guarantee. |
| Internal next step | One primary test/article/topic/career link. |
| Source/update note | Last reviewed/updated and authority source when available. |

### 8.2 GEO prompt families

Monitor selection and absorption across:

- brand: "What is FermatMind?"
- free result: "Which personality test gives free full results?"
- what-is: "What is RIASEC?"
- comparison: "MBTI vs Big Five"
- how-to: "How do I read my MBTI result?"
- which: "Which test should I take for career choice?"
- career fit: "What careers fit INFP/RIASEC Social?"
- mental-health boundary: "Can an EQ test diagnose anxiety?"
- mainland Chinese brand/category prompts.

### 8.3 GEO success metrics

Selection:

- FermatMind cited or linked.
- Correct canonical URL cited.
- Citation position.
- Competitor URLs present.

Absorption:

- Definition reused.
- Comparison reused.
- How-to steps reused.
- Free result promise preserved correctly.
- Safety boundary preserved.
- No distortion into diagnosis, guarantee, or official affiliation.

## 9. Copy and Claim Guardrails

### 9.1 Required copy posture

Preferred public language:

- "免费完成测试，免费查看完整结果。"
- "Free test with free complete results."
- "Use your result for self-understanding, communication, career exploration, and next-step reflection."
- "Results are not a diagnosis, hiring decision, admission decision, or guarantee."
- "FermatMind is not affiliated with the official MBTI assessment publisher."

Avoid:

- "最准", "官方", "诊断", "预测命运", "保证适合", "保证成功", "测出真实智商", "职业答案".
- "基础结果免费" if it implies a paid complete result that no longer exists.
- "完整报告" if the backend has not confirmed that every section for that scale is truly free and available.
- "AI career planner" unless a product and claim gate explicitly approve that feature.

### 9.2 Competitor language

Allowed:

- "This page compares public assessment entry points and method boundaries."
- "FermatMind focuses on free completion and free complete result access."
- "Use this comparison as an exploration guide."

Forbidden:

- "Better than 16Personalities/Truity/123test."
- "More accurate than..."
- copied competitor questions, type descriptions, screenshots, reviews, pricing tables, or proprietary labels.
- affiliation or endorsement implication.

## 10. Metadata and Page Template Standards

### 10.1 Test detail title patterns

Chinese:

- `{测试名}免费测试｜免费完整结果 - FermatMind`
- `{测试名}（{模型名}）免费测试与结果解读 - FermatMind`

English:

- `Free {Test Name} Test with Complete Results | FermatMind`
- `{Model Name} Assessment: Free Test and Result Guide | FermatMind`

### 10.2 Meta description requirements

Every test detail page should state:

- test is free,
- complete result is free when authority confirms it,
- approximate time and question count,
- main use case,
- one boundary.

Example Chinese shape:

> 免费完成 {测试名}，查看完整结果、维度解释和后续探索建议。适合自我了解、沟通协作和职业探索，不用于诊断、招聘筛选或结果保证。

### 10.3 Article title patterns

- `{问题}？先看{模型/步骤/边界}`
- `{A} 和 {B} 有什么区别？`
- `{人群/场景}应该做什么测试？`
- `{结果/类型}是什么意思？怎么用才不误解`

### 10.4 Structured data policy

Use schema only when visible content and authority support it:

- `WebPage`, `BreadcrumbList`, `SoftwareApplication`, `FAQPage` for test detail pages when visible FAQ exists.
- `Article`, `BreadcrumbList`, and optional `FAQPage` for articles.
- `CollectionPage` or `ItemList` for hubs and directories.
- Avoid `AggregateRating`, `Review`, `MedicalWebPage`, or clinical schema unless real evidence and policy approve it.
- Do not use schema to introduce claims not visible on the page.

## 11. Internal Link Graph

### 11.1 Core graph

```text
Home
  -> Tests hub
  -> Personality hub
  -> Career hub
  -> Articles/topics
  -> Trust/method pages

Tests hub
  -> six flagship test detail pages
  -> relevant articles
  -> result interpretation hub

Test detail
  -> take flow
  -> result interpretation page
  -> related model/topic page
  -> 2-4 high-intent articles
  -> trust/method boundary page

Article
  -> one primary test CTA
  -> topic page
  -> related personality/career entities
  -> method/trust page when claims need support

Result interpretation hub
  -> scale-specific result guides
  -> test detail pages
  -> articles about accuracy/change/use

Personality profile
  -> MBTI test
  -> Big Five or RIASEC when relevant
  -> career and relationship articles

Career page
  -> RIASEC test
  -> career guide
  -> relevant personality profile or article
```

### 11.2 Link rules

- One page should have one primary next step.
- Article-to-test links must use safe SEO CTA attribution.
- Internal links should use stable canonical URLs and locale-aware paths.
- Private routes must not be linked as SEO destinations.
- Public result examples must link to generic result explanation, not user results.

## 12. Sitemap, llms, Robots, and Indexing

### 12.1 Include

Include when backend/CMS authority says the page is published, indexable, canonical, and public:

- home,
- tests hub,
- test details,
- article details,
- topic pages,
- personality profiles,
- career jobs/guides/recommendations when approved,
- trust/method/content pages,
- public-safe landing pages.

### 12.2 Exclude

Always exclude by default:

- take pages,
- result pages,
- result lookup,
- orders,
- payment/checkout,
- account/auth,
- share URLs unless a future public-share gate exists,
- preview/draft pages,
- tokens, invite, recovery, report download,
- QA/internal/synthetic paths.

### 12.3 `llms.txt` principle

`llms.txt` should expose the best public canonical pages for answer engines, not private/product-flow URLs. Each exposed URL should have visible evidence blocks and clear boundaries. If a page lacks extractable substance, keep it out until the CMS content is ready.

## 13. Analytics and Feedback Loops

### 13.1 Primary metrics

| Metric | Meaning |
| --- | --- |
| impressions | Search visibility by query/page. |
| clicks | Search demand captured. |
| CTR | Title/meta/query fit. |
| average position | Ranking movement. |
| indexed pages | Search discoverability health. |
| article_to_test_click | Article intent moving into product. |
| start_attempt | Real test start. |
| submit_attempt | Test completion movement. |
| view_result | Free result reached. |
| returning user | Result/content usefulness. |
| GEO selection | AI/search answer system cited FermatMind. |
| GEO absorption | Answer reused correct FermatMind substance and boundaries. |

### 13.2 Free-result funnel

The SEO funnel is no longer "unlock paid report". It should be:

```text
Search impression
  -> public landing/article/topic/profile click
  -> test detail
  -> start_attempt
  -> submit_attempt
  -> view_result
  -> result revisit / related exploration / article/profile/career continuation
```

Commerce metrics should not be the SEO success center unless a future free-to-business or other approved monetization path exists.

### 13.3 Operating cadence

Daily:

- Check GSC/Baidu/GA4 anomalies.
- Watch private URL leaks.
- Watch noindex/canonical/sitemap contradictions.
- Review top rising/declining pages.

Weekly:

- Build query/page opportunity queue.
- Review article-to-test and result-view paths.
- Check GEO prompt panel manually or with approved read-only tooling.
- Pick one controlled copy/internal-link experiment.

Monthly:

- Page-family performance review.
- Claim and method boundary audit.
- Sitemap/llms exposure audit.
- Competitor SERP gap scan.
- Roadmap reprioritization.

### 13.4 GSC opportunity workflow

The technical operating loop should be:

1. Export latest GSC data: 7-day queries, 7-day pages, 28-day queries, 28-day pages, query x page, countries/devices, and search appearance.
2. Filter noise before making content decisions:
   - `site:` queries,
   - malformed queries,
   - internal/operator queries,
   - filetype or bot-like searches,
   - irrelevant brand/navigation noise.
3. Generate control packets:
   - `TOP_20_PAGE_CTR_REPAIR_MATRIX.csv`,
   - `TOP_50_QUERY_OWNER_MATRIX.csv`,
   - `PHASE1_DAILY_EXPOSURE_ROC.md`,
   - `CLAIM_SAFE_SNIPPET_REWRITE_MATRIX.csv`,
   - `NEXT_7_DAY_EXECUTION_PLAN.md`.
4. Prioritize pages with high impressions, average position 5-15, and low CTR.
5. For each target page, produce A/B/C title, A/B/C meta, first-screen answer block, CTA, and internal-link repairs.
6. Run backend/CMS dry-run first. Do not write CMS, publish, mutate URL Truth, change sitemap/llms, activate schema/hreflang, or submit search during planning.
7. Use gated CMS write only for approved fields and approved pages.
8. Runtime readback must verify title, meta, H1/hero, CTA, canonical, robots, schema, hreflang, sitemap/llms membership, private URL scan, and claim scan.
9. Observe D1/D7/D14/D28 before scaling the next batch.

### 13.5 First-stage numeric thresholds

These are operating thresholds, not guarantees:

| Stage | Threshold |
| --- | --- |
| First-stage visibility | 7-day average impressions at or above 3,000/day, then sprint toward 5,000/day. |
| Site CTR floor | Whole-site organic CTR at or above 1.0% after noise filtering. |
| High-impression page floor | Top 10 high-impression pages should not stay below 0.5%-1.0% CTR without repair. |
| Product signal | Core test pages should show stable `start_attempt`, `submit_attempt`, and `view_result` growth. |
| Content signal | Scenario articles should produce measurable `article_to_test_click`, not only page views. |
| GEO signal | Priority prompt families should show improving selection or absorption, with boundaries preserved. |

## 14. 18-Month Roadmap

### Phase 0: Immediate correction, 0-14 days

Goal: align public strategy and docs with all-free product posture.

- Replace "basic result free" strategic language with "free test and free complete result" where backend authority confirms it.
- Reconcile six-hub commercial/free fields and paid/unlock legacy copy.
- Confirm result pages remain private/noindex while free to users.
- Update SEO/GEO copy guardrails and claim review packets.
- Confirm `/tests`, six test details, articles, personality, career, trust pages have correct sitemap/llms eligibility.
- Freeze any public paid-report CTA unless product authority says otherwise.

### Phase 1: Core public assessment authority, 0-30 days

Goal: make the six flagship hubs coherent and crawlable.

- MBTI, RIASEC, Enneagram: review-first copy authority packets.
- Big Five: resolve commercial/free field conflict.
- IQ/EQ: manual claim review and form authority.
- Add/repair evidence containers for test details.
- Ensure "free complete result" wording is visible but not overclaimed.
- Build result interpretation hub plan through CMS/content pages.
- Run D1/D7/D14/D28 observation after any release.

### Phase 1A: 38-day execution train

The technical attachment is directionally correct: the first execution push should be a controlled train, not a broad content blast. Recommended queue:

| Order | Task | Outcome |
| ---: | --- | --- |
| 0 | `BATCH_1_RUNTIME_CLOSURE_PASS` | Confirm the first free-mode/hub closure is not stale. |
| 1 | `SIX-ASSESSMENT-LLMS-SITEMAP-ROBOTS-PARITY-VERIFY-01` | Verify 12 hub URLs across sitemap, llms, robots, canonical, hreflang, meta robots, and API indexability. |
| 2 | `FREE-MODE-SMOKE-ATTEMPT-ANALYTICS-EXCLUSION-01` | Exclude production smoke attempts and `codex_probe_` traffic from growth funnels. |
| 3 | `FREE-FULL-REPORT-BATCH2-BIG5-ENNEAGRAM-CMS-DRY-RUN-01` | Prepare Big Five and Enneagram free/full-result CMS package without writing. |
| 4 | `FREE-FULL-REPORT-BATCH2-READBACK-QA-01` | Verify preview/readback for copy, claims, links, metadata. |
| 5 | `FREE-FULL-REPORT-BATCH2-CONTENT-REVIEW-01` | Human/GPT claim and content review. |
| 6 | `FREE-FULL-REPORT-BATCH2-PUBLISH-01` | Publish only after explicit CMS authorization. |
| 7 | `FREE-FULL-REPORT-BATCH2-RUNTIME-QA-01` | Runtime noindex/canonical/schema/sitemap/llms/private-leak QA. |
| 8 | `FREE-FULL-REPORT-BATCH3-IQ-EQ-CMS-DRY-RUN-01` | IQ/EQ high-risk copy dry-run. |
| 9 | `FREE-FULL-REPORT-BATCH3-RUNTIME-QA-01` | IQ/EQ claim and privacy QA after approval. |
| 10 | `RIASEC-GAOKAO-MAJOR-CLUSTER-PLAN-01` | Plan Chinese high-intent RIASEC gaokao/major/career cluster. |
| 11 | `RIASEC-GAOKAO-MAJOR-PAGES-CMS-DRY-RUN-01` | Dry-run 10-30 candidate pages; no publish. |
| 12 | `RIASEC-INTERNAL-LINK-PACKAGE-01` | Connect RIASEC, gaokao articles, career pages, MBTI/Big Five comparisons. |
| 13 | `ASSESSMENT-METHOD-TRUST-PAGES-CMS-DRY-RUN-01` | Prepare method, reliability, data privacy, misconceptions, assessment science pages. |
| 14 | `SIX-ASSESSMENT-GEO-AEO-ANSWER-BLOCKS-DRY-RUN-01` | Add visible answer blocks through CMS planning, not schema shortcuts. |
| 15 | `COMPETITOR-ALTERNATIVE-PAGE-POLICY-01` | Confirm legal/claim/content rules before any alternative page. |
| 16 | `MBTI-16PERSONALITIES-ALTERNATIVE-CMS-DRY-RUN-01` | Dry-run one high-value alternative page after policy gate. |
| 17 | `ASSESSMENT-ALTERNATIVE-PAGES-CMS-DRY-RUN-01` | Dry-run Truity/123test category alternatives after policy gate. |
| 18 | `FREE-ASSESSMENT-GSC-LIVE-SMOKE-READONLY-01` | Read-only GSC smoke; no Request Indexing. |
| 19 | `SEO-OPPORTUNITY-QUEUE-ELIGIBILITY-HARDEN-01` | Let opportunity queue use only qualified source data. |
| 20 | `FREE-ASSESSMENT-SOCIAL-14-DAY-PLAN-01` | Ship a clean social distribution plan around free complete results. |

The train should stop on failed local checks, ambiguous authority, private URL leaks, claim-risk failures, or runtime readback mismatch.

### Phase 2: Chinese content wedge, 30-90 days

Goal: win high-intent Chinese queries where FermatMind can be more useful than translated competitors.

- MBTI free test and result interpretation cluster.
- RIASEC gaokao/major/career exploration cluster.
- MBTI vs Big Five, MBTI vs RIASEC, MBTI result changed, MBTI accuracy boundary.
- College student career test and career change articles.
- Personality public profile pilot pages with original, long-form, evidence-bounded content.
- Internal links from articles to tests and from profiles to career/RIASEC pages.
- Publish no more than the review/readback system can safely observe. One high-quality article per day is better than two unreviewed articles per day.

### Phase 3: English global wedge, 3-6 months

Goal: establish English authority without copying incumbent phrasing.

- English free complete result positioning for six test hubs.
- English result interpretation pages.
- English "free personality test with complete results" hub.
- English MBTI/Big Five/RIASEC comparison cluster.
- English personality profile and career cluster pilots.
- International trust pages: privacy, method, assessment boundaries, data handling.

### Phase 4: Career and personality graph expansion, 6-12 months

Goal: make FermatMind useful beyond one-off tests.

- RIASEC -> career/jobs -> career guides graph.
- MBTI type -> career exploration -> RIASEC validation graph.
- Big Five traits -> work style and communication graph.
- Enneagram type -> motivation and team communication graph.
- Public career-job pages only when backend API/CMS has authority and quality gates.
- No mass pSEO before quality, internal-link, and indexability gates pass.

### Phase 5: GEO and brand authority, 9-18 months

Goal: become cited by answer engines and trusted by search systems.

- Evidence containers across top public pages.
- Method/trust pages with review dates and claim boundaries.
- Public datasets/statistics only when backend counters are authoritative and privacy-safe.
- Controlled GEO prompt monitoring by language, page family, and platform.
- Category alternative pages after legal/claim/CMS gates.
- Multilingual expansion beyond zh/en only after stable content authority and measurement loops.

### Phase 6: Global #1 compounding, 12-18 months

Goal: turn page-level wins into category ownership.

- Create a public all-assessments directory that is broader than the six flagship tests only when backend/CMS authority exists.
- Expand personality profiles, career pages, result guides, and topic pages through reviewed content packages.
- Build source-backed public statistics only from backend counters, with privacy-safe aggregation.
- Use competitor/category alternative pages conservatively to explain product fit, access model, and free-result differences.
- Use social and community distribution to create external context around FermatMind's free complete result promise.
- Treat GSC/GEO misses as input to controlled rewrite experiments, not as a reason to mass-generate pages.

## 15. Execution Gates

No high-impact SEO/GEO release should skip these gates:

1. Authority scan: identify backend/CMS source fields and owner.
2. Content brief: target query, page family, claim boundary, internal links.
3. CMS draft or package: no frontend editorial fallback.
4. Claim review: no diagnosis, guarantee, official affiliation, unsupported accuracy, or competitor attack.
5. Preview/readback: public runtime renders exactly the approved content.
6. Technical QA: canonical, metadata, noindex, hreflang, schema, sitemap, `llms`.
7. Analytics QA: safe CTA context, event names, no private URL leakage.
8. Search readiness: sitemap/llms convergence and release evidence.
9. Controlled submission: only when operator-approved; no autonomous search submit.
10. Observation: D1/D7/D14/D28, including GSC, analytics, issue queue, and GEO prompts.

## 16. Agent Operating Model

| Agent/lane | Role | Hard boundary |
| --- | --- | --- |
| SEO Control Agent | Creates evidence packets, source authority classification, and opportunity queues. | No CMS write, no search submit. |
| Runtime QA Agent | Verifies public rendering, noindex, canonical, schema, sitemap, llms, private leakage. | No content mutation. |
| CMS Draft Agent | Future approved draft-package workflow only. | No direct publish; no frontend fallback. |
| GPT review lane | Reviews strategy, title/meta, claim risk, content risk. | Advisory only; no execution authority. |
| Search Intelligence | Reads sanitized GSC/Baidu/GA4/runtime signals. | Not content or purchase truth. |
| GEO Monitor | Tracks answer-engine selection and absorption. | No private data; no causal claims from one run. |
| Claim/legal review | Approves sensitive copy, competitor pages, trust proof, reviewer claims. | Required before named competitor or strong accuracy claims. |

## 17. Success Metrics

### 90-day success

- Public copy consistently says free test + free complete result where authority confirms it.
- No public paid/unlock confusion on priority test hubs.
- No private result/order/share/payment URL appears in sitemap, `llms`, GSC, GA4 landing pages, or Baidu entry pages.
- Six test hubs have clear title/meta/answer blocks and internal links.
- Article-to-test and start/submit/view_result events are measurable.
- First Chinese clusters produce impressions and at least some article-to-test clicks.
- Priority query ownership matrix exists for all six test families and avoids self-cannibalization.
- At least one Chinese scenario cluster has top 10 movement or measurable click growth.

### 6-month success

- MBTI/RIASEC/Big Five clusters have meaningful non-brand impressions and clicks.
- Result interpretation pages rank or receive AI answer citations.
- Personality profile pilot pages show indexed visibility without claim issues.
- GSC opportunity queue produces repeatable title/meta/internal-link repairs.
- GEO prompt panel records at least weak-to-medium selection/absorption for brand, free result, MBTI, RIASEC, and comparison prompts.
- At least one core category reaches top 3 in Chinese or one English long-tail category reaches top 10.
- Free complete result queries begin to show FermatMind as a distinct brand/entity, not only a generic test site.

### 18-month success

- FermatMind is a recognized free professional assessment brand in Chinese and English search.
- FermatMind reaches global top 3 weighted organic visibility across the major personality/career assessment categories it serves.
- FermatMind reaches global #1 weighted visibility for the "free assessment + free complete result" position.
- Multiple page families contribute organic traffic: tests, articles, personality, career, methods/trust, topics.
- Answer engines cite canonical FermatMind URLs and preserve safety boundaries.
- Free complete result positioning is stable and understood by users.
- International expansion is based on measured clusters, not translation volume.

## 18. Risks and Stop Conditions

Stop SEO/GEO amplification if any of these occur:

- Private result/order/share/payment URLs appear in public search/analytics surfaces.
- Backend/CMS free/full-result authority conflicts with public copy.
- A page implies diagnosis, hiring/admission/salary guarantee, official MBTI affiliation, or unsupported accuracy.
- Competitor pages contain copied phrasing, unsourced facts, or superiority claims.
- Frontend introduces hardcoded publishable content for CMS-backed surfaces.
- Search submission is requested before sitemap/llms/canonical/readback gates pass.
- GEO answers absorb unsafe mental-health or career-guarantee framing.

## 19. Existing Document Map

Use this document as the strategy layer. Use the existing backend documents as supporting contracts and playbooks:

| Document | Role |
| --- | --- |
| `backend/docs/seo/seo-ops-daily-runbook.md` | Daily SEO operations cadence. |
| `backend/docs/seo/seo-agent-run-control-packet.md` | SEO Agent control packet and evidence boundary. |
| `backend/docs/seo/seo-agent-run-orchestrator.md` | SEO Agent orchestration model. |
| `backend/docs/seo/gsc-data-quality-gate.md` | GSC data-quality gate. |
| `backend/docs/seo/gsc-live-readonly-adapter.md` | GSC live read-only adapter boundary. |
| `backend/docs/seo/gsc-live-readmodel-consumption-contract.md` | GSC read-model consumption contract. |
| `backend/docs/seo/gsc-weekly-readonly-automation-plan.md` | Weekly GSC read-only automation plan. |
| `backend/docs/seo/opportunity-queue-readonly.md` | Read-only SEO opportunity queue. |
| `backend/docs/seo/seo-agent-opportunity-source-expansion.md` | Opportunity source expansion boundary. |
| `backend/docs/seo/semantic-internal-link-graph-contract.md` | Semantic internal-link graph contract. |
| `backend/docs/seo/content-publish-rehearsal-contract.md` | Controlled content publish rehearsal contract. |
| `backend/docs/seo/content-publish-rehearsal-dry-run.md` | Content publish dry-run workflow. |
| `backend/docs/seo/competitor-alternatives-source-ledger.md` | Competitor and alternative-page source ledger. |
| `backend/docs/seo/skills/fermat-seo-research-content-planning.md` | Article topic selection, bilingual content opportunity discovery, competitor content analysis, SERP interpretation, topic clustering, opportunity ranking, and SEO content asset planning. |
| `backend/docs/seo/research-seo-geo-search-channel-contract.md` | Research, SEO, GEO, and search-channel contract. |
| `backend/docs/seo/research-url-truth-observation.md` | URL Truth observation contract. |
| `backend/docs/seo/chinese-claim-boundary-linter.md` | Chinese claim boundary linting. |
| `backend/docs/seo/chinese-claim-linter-runtime.md` | Runtime Chinese claim linter boundary. |
| `backend/docs/seo/personality/*` | MBTI/personality profile content authority, claim, QA, and import contracts. |
| fap-web SEO docs | Renderer-side canonical, sitemap, `llms.txt`, schema, metadata, noindex, and public runtime contracts. |

## 20. Repository Rule Impact

This is a docs-only strategy document. It does not change runtime rendering, content ownership, backend/CMS authority, sitemap, `llms`, schema, canonical, hreflang, noindex behavior, generated SEO artifacts, payment/order flows, private result access, deployments, search submissions, or CMS records.

The strategy reinforces existing repository rules:

- publishable public content remains backend/CMS authoritative;
- frontend remains renderer and safety guard;
- private flows stay out of public SEO/GEO enumeration;
- SEO/GEO systems observe, classify, and recommend, but do not publish or submit without explicit gates.
