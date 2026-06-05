# SEO Article Performance Baseline Template

Date: 2026-06-05

PR train item: SEO-ARTICLE-PERFORMANCE-BASELINE-TEMPLATE-01

Scope: article-specific 7-day and 14-day baseline template only.

## Boundary

This template does not query dashboards, does not mutate CMS, does not create drafts, does not publish, does not submit search, does not inspect private URLs, and does not treat analytics as purchase truth.

Unknown values must remain `Unknown`. They must not be recorded as `0`.

## Article Identity Fields

| Field | Required | Source | Rule |
| --- | --- | --- | --- |
| article_id | yes | CMS/backend | Numeric id or `Unknown` before CMS creation |
| locale | yes | CMS/backend | Use canonical API locale |
| slug | yes | CMS/backend | Public slug only |
| canonical_url | yes | Public canonical route | No private, tokenized, result, order, share, payment, pay, or history URL |
| target_keyword | yes | request card / Search Console | Query text is allowed; do not infer volume from absence |
| target_intent | yes | request card | Informational, comparison, career guidance, or explanation |
| primary_cta_target | yes | request card / CMS | Public canonical route only |
| secondary_cta_target | optional | request card / CMS | Public canonical route only or `Unknown` |

## Indexing And Search Fields

| Field | T+7 | T+14 | Rule |
| --- | --- | --- | --- |
| in_sitemap | PASS / FAIL / Unknown | PASS / FAIL / Unknown | Public sitemap check only |
| indexed_google | Yes / No / Unknown | Yes / No / Unknown | GSC or public search evidence only |
| indexed_baidu | Yes / No / Unknown | Yes / No / Unknown | Baidu evidence only |
| google_impressions | number / Unknown | number / Unknown | Search Console truth; Unknown is not 0 |
| google_clicks | number / Unknown | number / Unknown | Search Console truth; Unknown is not 0 |
| google_ctr | number / Unknown | number / Unknown | Derived only if impressions and clicks are known |
| google_avg_position | number / Unknown | number / Unknown | Search Console truth |
| baidu_search_pv | number / Unknown | number / Unknown | Baidu dashboard truth if available |

## Behavior And Funnel Fields

| Field | T+7 | T+14 | Rule |
| --- | --- | --- | --- |
| landing_pv | number / Unknown | number / Unknown | Analytics observation only |
| article_to_test_click | number / Unknown | number / Unknown | Must use canonical event or documented attribution equivalent |
| start_test | number / Unknown | number / Unknown | Analytics event only, not purchase truth |
| complete_test | number / Unknown | number / Unknown | Analytics event only |
| view_result | number / Unknown | number / Unknown | Analytics event only; no private result URL stored |
| private_url_seen | Yes / No / Unknown | Yes / No / Unknown | `Yes` opens P0 privacy investigation |
| internal_link_clicks | number / Unknown | number / Unknown | Only public canonical targets |
| content_update_decision | hold / update / expand / pause / rollback / Unknown | hold / update / expand / pause / rollback / Unknown | Decision must cite evidence class |

## Review Rules

- `private_url_seen=Yes` opens a P0 privacy investigation and blocks scale.
- Purchase truth is backend/order/report truth only; analytics does not prove purchase.
- Store canonical public URLs only.
- Do not store private result, order, share, pay, payment, history, tokenized, or user-specific URLs.
- Do not record raw user identifiers.
- Do not interpret missing dashboard access as zero.
- Do not submit search during review without a separate exact authorization.

## T+7 Review

Purpose: decide whether the article has early crawl, index, impression, and CTA signals.

Allowed decisions:

- `hold`: keep observing.
- `update`: request a human/GPT content package revision; no direct Codex copy.
- `expand`: prepare next request card or internal link support.
- `pause`: stop further articles in the same topic until more evidence exists.
- `rollback`: only with separate content/CMS authorization.
- `Unknown`: evidence is insufficient.

## T+14 Review

Purpose: decide whether the topic should be expanded, revised, or paused.

Required evidence classes:

- public URL and sitemap/llms state,
- Search Console or Baidu evidence if available,
- article CTA/funnel attribution evidence if available,
- internal link evidence if available,
- privacy route scan result,
- claim/schema regression status.

## Template Row

Use this row shape for each locale-specific article review. Values shown as `Unknown` are placeholders, not zeroes.

| article_id | locale | slug | canonical_url | target_keyword | target_intent | primary_cta_target | secondary_cta_target | in_sitemap | indexed_google | indexed_baidu | google_impressions | google_clicks | google_ctr | google_avg_position | baidu_search_pv | landing_pv | article_to_test_click | start_test | complete_test | view_result | private_url_seen | internal_link_clicks | content_update_decision |
| --- | --- | --- | --- | --- | --- | --- | --- | --- | --- | --- | --- | --- | --- | --- | --- | --- | --- | --- | --- | --- | --- | --- | --- |
| Unknown | Unknown | Unknown | Unknown | Unknown | Unknown | Unknown | Unknown | Unknown | Unknown | Unknown | Unknown | Unknown | Unknown | Unknown | Unknown | Unknown | Unknown | Unknown | Unknown | Unknown | Unknown | Unknown | Unknown |

## Next Task

Proceed to SEO-ARTICLE-TOPIC-PRIORITY-01 after this PR merges. Do not create CMS drafts, publish, submit search, or write article copy.
