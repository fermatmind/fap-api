# Article Internal Link Plan

Date: 2026-06-05

PR train item: ARTICLE-INTERNAL-LINK-PLAN-01

Scope: public-canonical internal link planning for the Window 6 SEO article cluster.

## Boundary

This plan does not write article copy, create CMS drafts, publish, submit search, inspect private URLs, or mutate runtime data. It defines link eligibility only.

## Allowed Target Registry

| Target type | Status | Allowed examples |
| --- | --- | --- |
| Published article canonical URLs | Allowed after public 200 and indexability checks | first bilingual canary URLs |
| RIASEC public test page | Allowed | `/zh/tests/holland-career-interest-test-riasec`, `/en/tests/holland-career-interest-test-riasec` |
| MBTI public test page | Allowed | `/zh/tests/mbti-personality-test-16-personality-types`, `/en/tests/mbti-personality-test-16-personality-types` |
| Big Five public test page | Allowed | `/zh/tests/big-five-personality-test-ocean-model`, `/en/tests/big-five-personality-test-ocean-model` |
| Career hub | Conditional | `/zh/career/jobs` or locale equivalent only after career hub strategy approval |
| Tier A career pages | Conditional | only after CAREER-TIER-A-LINK-ELIGIBILITY records approved public canonical targets |

## Blocked Target Registry

Always blocked:

- `/result/**`
- `/orders/**`
- `/share/**`
- `/pay/**`
- `/payment/**`
- `/history/**`
- private take/session/result/order/payment/share URLs
- tokenized URLs
- user-specific URLs
- noindex pages unless explicitly approved for non-SEO operational linking
- external competitor pages as article CTA targets

## Link Matrix

| Source topic | Allowed primary links | Allowed secondary links | Conditional links | Blocked links |
| --- | --- | --- | --- | --- |
| First canary | RIASEC public test page, MBTI public test page, Big Five public test page | future RIASEC explanation after publish | public career hub after approval | result/order/share/pay/payment/history/private URLs |
| RIASEC explanation | first canary, RIASEC public test page | MBTI and Big Five public test pages | career hub or Tier A pages after approval | result/order/share/pay/payment/history/private URLs |
| Career uncertainty | RIASEC explanation, RIASEC public test page | first canary, MBTI or Big Five public test page | public career hub or Tier A pages after approval | private result/report/order/payment/share/history URLs |
| Big Five career use | Big Five public test page, RIASEC explanation | first canary, RIASEC public test page | approved public career pages only after eligibility | job-performance, hiring, private result, or payment surfaces |
| Article/career internal-linking support | approved public articles, public test pages | approved public career hub or Tier A pages | career pages after eligibility sidecar closes | private result/order/share/pay/payment/history/tokenized URLs |

## Eligibility Rules

- Every target must be a public canonical route.
- Every article target must be published, public, and indexable before it is used as an SEO internal link.
- Every CTA target must pass ARTICLE-CTA-ROUTE-GATE-01.
- Career targets require either an approved hub strategy or Tier A eligibility evidence.
- If a route's privacy/indexability state is Unknown, do not link it as an SEO internal link.
- Do not store or link user-specific result, order, payment, report, share, history, token, or session URLs.

## Sidecar Dependencies

| Sidecar | Reason | Blocks this plan? |
| --- | --- | --- |
| CAREER-TIER-A-LINK-ELIGIBILITY | Needed before linking individual career pages | No, but blocks career-page expansion |
| TEST-LANDING-PROOF-SURFACE follow-up | Needed to improve proof surfaces on public test pages | No |
| ARTICLE-TO-TEST-CLICK-TRACKING-RECONCILE-01 | Needed to reconcile event naming for article CTA attribution | No, but must be resolved before analytics readout is treated as stable |
| PRIVATE-URL-LIVE-REVIEW follow-up | Needed for broader live private URL assurance | No, unless a private URL is found |

## Decision

GO for public article-to-test and article-to-article links inside the Window 6 cluster.

CONDITIONAL for career hub or career page links until career hub strategy or Tier A link eligibility is approved.

NO-GO for private, tokenized, result, order, share, pay, payment, or history links.

## Next Task

Proceed to ARTICLE-CTA-ROUTE-GATE-01 after this PR merges.
