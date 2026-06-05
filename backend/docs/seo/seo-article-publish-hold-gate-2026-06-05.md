# SEO Article Publish Hold Gate

Date: 2026-06-05

PR train item: SEO-ARTICLE-PUBLISH-HOLD-GATE-01

Scope: publish hold checklist and next authorization boundary for SEO article production.

## Boundary

This gate does not write article copy, title, H1, meta, FAQ, CTA, ad copy, social copy, or CMS content. It does not create CMS drafts, publish, unpublish, submit search URLs, call GSC, call Baidu, call IndexNow, deploy, mutate databases, or inspect private URLs.

## One-line Decision

HOLD for publish. GO only for a separately authorized GPT-5.5 Pro content package request for the next approved article topic.

## Default State

Every new SEO article remains in HOLD until all upstream and downstream gates pass.

HOLD means:

- no CMS draft,
- no publish,
- no unpublish,
- no sitemap or search submission,
- no public URL inspection for a non-existent draft except public absence checks,
- no private/result/order/share/pay/payment/history/tokenized URL access,
- no dashboard value interpreted as 0 when evidence is Unknown.

## Required Gates Before CMS Draft Planning

| Gate | Required status |
| --- | --- |
| Topic priority | approved request-card topic exists |
| Request card | complete and contains no publishable copy |
| Claim boundary | reviewed for the topic and locale |
| CTA route gate | PASS for every CTA target |
| Internal link plan | PASS or explicit CONDITIONAL entries |
| FAQ/schema smoke | PASS for intended schema state |
| CMS field map | required fields known or marked Unknown |
| Performance baseline owner | assigned for T+7 and T+14 review |
| Private URL policy | PASS; no private CTA, canonical, or stored URL |

If any required gate is Unknown, CMS draft planning stays HOLD.

## Required Gates Before Publish Preflight

| Gate | Required status |
| --- | --- |
| CMS draft authorization | separate explicit authorization exists |
| Import/package equivalence | PASS |
| Draft state | draft, non-public, non-indexable, no published revision |
| Draft public absence | PASS for page, API, SEO API, sitemap, llms, and llms-full |
| Editorial review | approved by authorized operator |
| Claim warnings | acknowledged only when preflight says they exist |
| Media/graph/reference/CTA/FAQ completeness | PASS or approved exception |
| Controlled publish dry-run | PASS with no writes |

If any required gate is Unknown or FAIL, publish preflight stays HOLD.

## Required Gates Before Publish Execution

Publish execution remains forbidden unless a later task has all of:

- controlled publish preflight returns `ok=true`,
- exact article ids are known from CMS/backend,
- exact locale and slug list is known,
- every target revision is approved,
- claim warnings are explicitly acknowledged only for the affected article ids,
- CTA route gate remains PASS,
- FAQ/schema smoke remains PASS,
- draft public absence remains PASS before publish,
- user gives a separate exact publish authorization after the successful preflight.

This document does not issue a publish authorization phrase.

## Post-publish Gate

After a separately authorized publish, the post-publish smoke must check:

- public page 200,
- public article API 200,
- public SEO API 200,
- canonical and hreflang,
- Article/Breadcrumb/FAQ schema state,
- CTA hrefs remain public canonical,
- sitemap/llms/llms-full inclusion,
- no private URL exposure,
- baseline row initialized with Unknown values preserved,
- T+7 and T+14 review calendar owner assigned.

Search submission remains HOLD unless a separate exact search-channel authorization is provided.

## Next Authorization Prompt

The only next authorization this train prepares is content-package intake, not publish:

```text
I authorize Codex to prepare the GPT-5.5 Pro content package request inputs for the next RIASEC explanation SEO article only. Do not create a CMS draft, do not write final article copy in Codex, do not publish, do not submit search URLs, and do not access private/result/order/share/pay/payment/history/tokenized URLs.
```

This prompt authorizes request-input preparation only. It does not authorize GPT output acceptance, CMS import, CMS draft creation, editorial approval, publish preflight, publish execution, or search submission.

## Stop Conditions

Stop and report HOLD if:

- the requested action asks Codex to write publishable article copy,
- title/H1/meta/FAQ/CTA copy is requested inside Codex,
- CMS mutation is requested without separate explicit authorization,
- publish or search submission is requested without successful preflight,
- a CTA or canonical URL points to a private/tokenized route,
- any metric or dashboard value is unavailable and someone tries to record it as 0,
- purchase, order, or payment truth is inferred from analytics.

## Result

The SEO article system is ready to move from Window 6 infrastructure into the next separately authorized GPT-5.5 Pro request-card/content-package intake step.

It is not authorized for CMS draft creation, publish, search submission, or content publication.
