# SEO Article Topic Priority

Date: 2026-06-05

PR train item: SEO-ARTICLE-TOPIC-PRIORITY-01

Scope: rank next article topic directions without writing article content.

## Boundary

This document does not provide final titles, H1, meta copy, body copy, FAQ copy, CTA copy, ad copy, social copy, or publishable article text. It does not create CMS drafts, publish, submit search, inspect private URLs, or mutate runtime data.

## Decision

Recommended next request-card priority: RIASEC explanation.

Reason: it is the lowest-risk bridge from the first comparison canary into a durable test-explanation and career-guidance article cluster. It supports the public RIASEC test route, strengthens internal links from the canary, and avoids overexpanding comparison-style articles before the baseline and link plan are mature.

## Topic Matrix

| Priority | Topic direction | Search intent | User problem | Primary CTA | Secondary CTA | Internal links | Claim boundary | Publish risk | Reason |
| ---: | --- | --- | --- | --- | --- | --- | --- | --- | --- |
| 1 | RIASEC explanation | explanation / career exploration | User needs to understand what the RIASEC framework is for before taking or interpreting a career-interest test | `/zh/tests/holland-career-interest-test-riasec` and `/en/tests/holland-career-interest-test-riasec` | MBTI and Big Five public test routes where relevant | first canary, RIASEC test page, MBTI test page, Big Five test page | Interest exploration only; no diagnosis, hiring fit, guaranteed career outcome, or ability claim | Low-Medium | Directly fills the test-explanation asset gap and is easier to claim-bound than advice-heavy topics |
| 2 | Career uncertainty | career guidance | User feels stuck choosing majors, jobs, or career direction | RIASEC public test route | MBTI or Big Five public test route based on request card | first canary, future RIASEC explanation, approved public career hub if available | Exploration support only; no deterministic career advice, counseling replacement, or guaranteed outcome | Medium | High user pain and search potential, but higher claim risk and needs stronger link-plan boundaries |
| 3 | Big Five career use | personality-to-work-style explanation | User wants to understand how Big Five traits can inform work-style reflection | Big Five public test route | RIASEC public test route | first canary, RIASEC explanation, Big Five test page | Work-style tendency only; no job performance, hiring, competency, or clinical claim | Medium | Clear request-card gap and useful cluster expansion after RIASEC explanation |
| 4 | MBTI vs RIASEC vs Big Five | comparison / framework selection | User wants to choose which assessment to use for career exploration | RIASEC public test route unless request card says otherwise | MBTI and Big Five public test routes | first canary, RIASEC explanation, Big Five career use | Each framework has limited use cases; no framework superiority or outcome guarantee | Medium | Useful later, but too close to the first canary if done immediately |
| 5 | Result/career-library internal-link article | navigation / next-step guidance | User needs safe next steps after public article/test discovery | Public canonical article/test/career routes only | Unknown until career Tier A/hub eligibility is approved | Approved public articles, tests, and career pages only | No private result URL, personalized report URL, order/payment/share/history route, or career certainty claim | Medium-High | Needs ARTICLE-INTERNAL-LINK-PLAN-01 and career eligibility decisions before content package intake |

## Topic Recommendation

Proceed to request-card planning in this order:

1. RIASEC explanation.
2. Career uncertainty.
3. Big Five career use.
4. Article/career internal linking request.

Do not prioritize another comparison article until the explanation and guidance assets exist.

## Gate Dependencies

- SEO-ARTICLE-PERFORMANCE-BASELINE-TEMPLATE-01 must remain the review template for published articles.
- SEO-ARTICLE-REQUEST-CARDS-02 must define request cards before GPT content packages.
- ARTICLE-INTERNAL-LINK-PLAN-01 must decide which career routes are eligible for public canonical links.
- ARTICLE-CTA-ROUTE-GATE-01 must settle the article CTA route and tracking expectation before draft/publish planning.
- ARTICLE-FAQ-SCHEMA-SMOKE-01 must define the visible CMS/backend FAQ schema rule before publish.

## Next Task

Proceed to SEO-ARTICLE-REQUEST-CARDS-02 after this PR merges. Do not write article copy or create CMS drafts.
