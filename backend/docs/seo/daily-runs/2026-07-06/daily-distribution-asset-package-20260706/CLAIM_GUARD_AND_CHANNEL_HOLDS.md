# Claim Guard And Channel Holds

## Allowed Language

Allowed:

- RIASEC can help organize interests, activity preferences, and exploration directions.
- Course structure, school transfer rules, family constraints, and career direction should be checked separately.
- A checklist can help make the conversation calmer and more concrete.
- The article can suggest questions to ask, not final outcomes to believe.

## Forbidden Language

Forbidden:

- RIASEC decides the best major.
- RIASEC predicts admission, transfer success, employment, salary, or career success.
- A test result overrides school policy, family constraints, professional counseling, medical/legal/financial advice, or the student's lived context.
- A single checklist can guarantee the right decision.
- FermatMind has official school, ministry, counseling, or clinical endorsement without separate public evidence.

## Channel Holds

This package is held for all live channels:

| Channel | Status | Reason |
| --- | --- | --- |
| CMS draft/import | held | Requires separate content-package authorization. |
| Public publish | held | No full article body or operator approval in this PR. |
| WeChat/social posting | held | Assets are planning drafts only. |
| GSC Request Indexing | held | Search action is outside scope. |
| IndexNow/Baidu/360/Sogou/Shenma | held | Search submission is outside scope. |
| Sitemap/llms | held | Discoverability surfaces are outside scope. |
| Schema/hreflang | held | Structured data and localization gates are outside scope. |
| Deploy/revalidation | held | No runtime mutation exists in this PR. |

## Public URL Guard

Allowed public route references:

- `/zh/tests/holland-career-interest-test-riasec`
- `/zh/articles/riasec-holland-career-interest-test-explained`
- `/zh/articles/gaokao-score-major-shortlist-riasec-checklist`
- `/zh/articles/hot-major-fit-riasec-course-career-checklist`

Disallowed route references:

- private result/report/attempt/share/history URLs;
- order/payment/account/auth URLs;
- tokenized, preview-only, local, or admin URLs;
- fake Media Library URLs.

## Review Checklist Before Any Future Publication

- Confirm the final article body exists and passes claim review.
- Confirm all links are public and canonical.
- Confirm the CTA points to the RIASEC owner page without deterministic career claims.
- Confirm no Search submission or discoverability action is bundled with CMS publish unless separately authorized.
- Confirm observation is read-only and does not treat GSC/GA as purchase truth.
