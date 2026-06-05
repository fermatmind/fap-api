# SEO Article Request Cards 02

Date: 2026-06-05

PR train item: SEO-ARTICLE-REQUEST-CARDS-02

Scope: next-batch request cards only. These cards are routing, measurement, CMS-field, and boundary inputs for later GPT content packages.

## Boundary

No final title, H1, meta copy, outline, body copy, FAQ copy, CTA copy, ad copy, social copy, or publishable article text is included. No CMS draft is created. No publish, search submission, GSC URL Inspection, Baidu push, IndexNow call, deploy, or private URL access is performed.

## Shared Forbidden Routes

All request cards forbid:

- `/result/**`
- `/orders/**`
- `/share/**`
- `/pay/**`
- `/payment/**`
- `/history/**`
- private take/session/result/order/payment/share URLs
- tokenized URLs
- user-specific URLs

## Shared Required Measurement Events

- `article_to_test_click`, or an explicitly documented article attribution equivalent after ARTICLE-CTA-ROUTE-GATE-01.
- `start_test`.
- `complete_test`.
- `view_result`.

These events are observation signals only. Purchase truth remains backend-only.

## Request Card: RIASEC-EXPLANATION-ARTICLE-REQ-01

| Field | Value |
| --- | --- |
| request_id | RIASEC-EXPLANATION-ARTICLE-REQ-01 |
| topic_direction | RIASEC explanation |
| business_goal | Expand from the first canary into a test-explanation asset that supports career-interest discovery |
| target_user_problem | User wants to understand what RIASEC is for before taking or interpreting a career-interest assessment |
| primary_search_intent | explanation / career exploration |
| target_page placeholder | `/{locale}/articles/{gpt-proposed-slug-after-review}` |
| primary_cta_target | `/zh/tests/holland-career-interest-test-riasec` and `/en/tests/holland-career-interest-test-riasec` |
| secondary_cta_target | MBTI and Big Five public test routes when relevant |
| required_internal_links | first canary, RIASEC public test page, MBTI public test page, Big Five public test page |
| forbidden_routes | shared forbidden routes |
| required_claim_boundaries | interest exploration only; no diagnosis, no hiring fit, no guaranteed career outcome, no ability claim |
| required_measurement_events | shared required measurement events |
| CMS fields GPT must fill later | title, slug, locale, excerpt, body_markdown, seo_title, meta_description, FAQ items, CTA slot labels, CTA slot hrefs, claim boundary notes, references, category, tags, cover metadata |
| publish prerequisites | content package review, CMS draft authorization, draft noindex, sitemap/llms absence, CTA route gate, FAQ/schema smoke, baseline owner, controlled publish preflight, exact publish approval |
| 7-day metrics | in_sitemap, indexed_google, indexed_baidu, google_impressions, google_clicks, landing_pv, article_to_test_click, start_test, complete_test, view_result, private_url_seen |
| 14-day metrics | same as 7-day plus internal_link_clicks and content_update_decision |

## Request Card: CAREER-UNCERTAINTY-ARTICLE-REQ-02

| Field | Value |
| --- | --- |
| request_id | CAREER-UNCERTAINTY-ARTICLE-REQ-02 |
| topic_direction | Career uncertainty |
| business_goal | Support users who arrive with broad career-direction uncertainty and route them to public career-interest exploration |
| target_user_problem | User feels stuck choosing majors, jobs, or career direction |
| primary_search_intent | career guidance / exploration |
| target_page placeholder | `/{locale}/articles/{gpt-proposed-slug-after-review}` |
| primary_cta_target | `/zh/tests/holland-career-interest-test-riasec` and `/en/tests/holland-career-interest-test-riasec` |
| secondary_cta_target | MBTI or Big Five public test route if the approved package supports it |
| required_internal_links | first canary, future RIASEC explanation, public test pages, approved public career hub only after link-plan approval |
| forbidden_routes | shared forbidden routes |
| required_claim_boundaries | exploration support only; no deterministic advice, no counseling replacement, no guaranteed outcome |
| required_measurement_events | shared required measurement events |
| CMS fields GPT must fill later | title, slug, locale, excerpt, body_markdown, seo_title, meta_description, FAQ items, CTA slot labels, CTA slot hrefs, claim boundary notes, references, category, tags, cover metadata |
| publish prerequisites | content package review, CMS draft authorization, draft noindex, sitemap/llms absence, CTA route gate, FAQ/schema smoke, baseline owner, controlled publish preflight, exact publish approval |
| 7-day metrics | in_sitemap, indexed_google, indexed_baidu, google_impressions, google_clicks, landing_pv, article_to_test_click, start_test, complete_test, view_result, private_url_seen |
| 14-day metrics | same as 7-day plus internal_link_clicks and content_update_decision |

## Request Card: BIG-FIVE-CAREER-ARTICLE-REQ-01

| Field | Value |
| --- | --- |
| request_id | BIG-FIVE-CAREER-ARTICLE-REQ-01 |
| topic_direction | Big Five career use |
| business_goal | Fill the Big Five career-use asset gap without making job-performance or hiring claims |
| target_user_problem | User wants to understand how Big Five traits can inform work-style reflection |
| primary_search_intent | personality-to-work-style explanation |
| target_page placeholder | `/{locale}/articles/{gpt-proposed-slug-after-review}` |
| primary_cta_target | `/zh/tests/big-five-personality-test-ocean-model` and `/en/tests/big-five-personality-test-ocean-model` |
| secondary_cta_target | RIASEC public test route |
| required_internal_links | first canary, future RIASEC explanation, Big Five public test page, RIASEC public test page |
| forbidden_routes | shared forbidden routes |
| required_claim_boundaries | work-style tendency only; no job performance prediction, no hiring fit, no competency claim, no clinical claim |
| required_measurement_events | shared required measurement events |
| CMS fields GPT must fill later | title, slug, locale, excerpt, body_markdown, seo_title, meta_description, FAQ items, CTA slot labels, CTA slot hrefs, claim boundary notes, references, category, tags, cover metadata |
| publish prerequisites | content package review, CMS draft authorization, draft noindex, sitemap/llms absence, CTA route gate, FAQ/schema smoke, baseline owner, controlled publish preflight, exact publish approval |
| 7-day metrics | in_sitemap, indexed_google, indexed_baidu, google_impressions, google_clicks, landing_pv, article_to_test_click, start_test, complete_test, view_result, private_url_seen |
| 14-day metrics | same as 7-day plus internal_link_clicks and content_update_decision |

## Request Card: ARTICLE-CAREER-INTERNAL-LINKING-REQ-01

| Field | Value |
| --- | --- |
| request_id | ARTICLE-CAREER-INTERNAL-LINKING-REQ-01 |
| topic_direction | Article and career internal-linking support |
| business_goal | Plan safe public-canonical article-to-test and article-to-career discovery without linking private result or payment surfaces |
| target_user_problem | User needs safe next steps after discovering an article or public test page |
| primary_search_intent | navigation / next-step guidance |
| target_page placeholder | `/{locale}/articles/{gpt-proposed-slug-after-review}` |
| primary_cta_target | public canonical test route selected by the approved package |
| secondary_cta_target | public canonical career route only after ARTICLE-INTERNAL-LINK-PLAN-01 approval |
| required_internal_links | approved public articles, approved public tests, career hub or Tier A career pages only after eligibility is recorded |
| forbidden_routes | shared forbidden routes |
| required_claim_boundaries | no private result reference, no personalized report URL, no career certainty, no guaranteed outcome |
| required_measurement_events | shared required measurement events |
| CMS fields GPT must fill later | title, slug, locale, excerpt, body_markdown, seo_title, meta_description, FAQ items if approved, CTA slot labels, CTA slot hrefs, claim boundary notes, references, category, tags, cover metadata |
| publish prerequisites | ARTICLE-INTERNAL-LINK-PLAN-01, CTA route gate, FAQ/schema smoke if FAQ is present, content package review, CMS draft authorization, draft noindex, sitemap/llms absence, baseline owner, controlled publish preflight, exact publish approval |
| 7-day metrics | in_sitemap, indexed_google, indexed_baidu, google_impressions, google_clicks, landing_pv, article_to_test_click, start_test, complete_test, view_result, private_url_seen |
| 14-day metrics | same as 7-day plus internal_link_clicks and content_update_decision |

## Next Task

Proceed to ARTICLE-INTERNAL-LINK-PLAN-01 after this PR merges.
