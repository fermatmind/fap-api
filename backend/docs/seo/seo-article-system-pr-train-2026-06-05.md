# SEO Article System PR Train

Date: 2026-06-05

Train: Window 6 SEO article system

Decision: CONDITIONAL. The first bilingual canary proves that the SEO article workflow can run end to end. It does not prove that articles can be batch-published without repeating the gates.

## Scope

This train turns the canary workflow into a repeatable article production mechanism. It covers request cards, quality review, article-specific baseline review, topic priority, internal link planning, CTA route safety, FAQ/schema smoke checks, and publish hold gates.

This train does not create article body copy, article metadata copy, CMS drafts, publish actions, search submissions, URL inspections, IndexNow calls, Baidu pushes, or private URL checks.

## PR Queue

| Order | PR id | Purpose | Publish allowed |
| ---: | --- | --- | --- |
| 1 | SEO-ARTICLE-SYSTEM-TRAIN-01 | Register the train and boundaries | No |
| 2 | SEO-ARTICLE-CANARY-QUALITY-REVIEW-01 | Review the first canary as a process template | No |
| 3 | SEO-ARTICLE-PERFORMANCE-BASELINE-TEMPLATE-01 | Define 7-day and 14-day article baseline fields | No |
| 4 | SEO-ARTICLE-TOPIC-PRIORITY-01 | Rank next article topics without writing content | No |
| 5 | SEO-ARTICLE-REQUEST-CARDS-02 | Create next-batch request cards only | No |
| 6 | ARTICLE-INTERNAL-LINK-PLAN-01 | Define public-canonical internal link matrix | No |
| 7 | ARTICLE-CTA-ROUTE-GATE-01 | Gate article CTA route safety and tracking expectations | No |
| 8 | ARTICLE-FAQ-SCHEMA-SMOKE-01 | Define Article, FAQ, and Breadcrumb schema smoke rules | No |
| 9 | SEO-ARTICLE-PUBLISH-HOLD-GATE-01 | Make publish forbidden until all gates and exact approval pass | No |

## Hard Boundaries

- Do not write second-article body copy.
- Do not write final title, H1, meta, FAQ, CTA, social, ad, or publication copy.
- Do not create CMS drafts.
- Do not publish or unpublish.
- Do not submit to Google Search Console, Baidu, IndexNow, 360, Sogou, or Shenma.
- Do not run GSC URL Inspection.
- Do not access result, order, share, pay, payment, history, private, tokenized, or user-specific URLs.
- Do not treat analytics as purchase truth.
- Do not treat Unknown as zero.
- Keep article authority in CMS/backend.

## Sidecar Issues

The train may record these issues without stopping request-card and planning work, provided the current PR did not introduce the issue and required checks pass:

- ARTICLE-TO-TEST-CLICK-TRACKING-RECONCILE-01
- ANALYTICS-SEO-P0-01 through ANALYTICS-SEO-P0-05
- PRIVATE-URL-LIVE-REVIEW follow-up
- CAREER-TIER-A-LINK-ELIGIBILITY
- TEST-LANDING-PROOF-SURFACE follow-up

## Exit Criteria

Window 6 can close as complete when PR9 is merged and cleanup confirms:

- request cards exist for the next batch,
- topic priority recommends the next request card,
- internal link planning is public-canonical only,
- CTA route gate is documented,
- FAQ/schema smoke policy is documented,
- publish hold gate blocks CMS draft, publish, and search submission without separate exact authorization,
- no article copy or CMS draft was created by the train.
