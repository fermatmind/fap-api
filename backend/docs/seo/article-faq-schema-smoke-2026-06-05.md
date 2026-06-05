# Article FAQ Schema Smoke

Date: 2026-06-05

PR train item: ARTICLE-FAQ-SCHEMA-SMOKE-01

Scope: repeatable smoke gate for Article, BreadcrumbList, and FAQPage structured data on SEO articles.

## Boundary

This smoke gate does not write FAQ questions, FAQ answers, article copy, title, H1, meta, CTA copy, ad copy, or social copy. It does not mutate CMS, create a draft, publish, submit search, deploy, or inspect private URLs.

## Decision

GO for Article and BreadcrumbList schema smoke as a required check on every published article.

GO for FAQPage schema only when FAQ items are visibly rendered from CMS/backend article answer-surface data.

NO-GO for hidden FAQ schema, generated fallback FAQ, frontend-local FAQ authority, or FAQPage schema when no visible FAQ block exists.

## Required Smoke Checks

| Schema type | Required when | Source authority | Smoke expectation |
| --- | --- | --- | --- |
| Article | public article detail is published and indexable | CMS article or approved CMS SEO JSON-LD | one Article JSON-LD object with canonical URL, headline, description, language, author, published/modified dates, and mainEntityOfPage |
| BreadcrumbList | public article detail is rendered | public locale route and article title | one BreadcrumbList object matching Home -> Articles -> article |
| FAQPage | visible article FAQ exists | `answer_surface_v1.faq_blocks` or approved CMS/backend visible FAQ authority | FAQPage mainEntity count equals visible FAQ count |

## FAQ Schema Rules

- FAQPage may be emitted only from visible FAQ content.
- The visible FAQ source must be CMS/backend-owned.
- FAQPage must not be inferred from hidden HTML, frontend fallback copy, article body heuristics, or GPT-only package notes.
- If visible FAQ is absent, the correct result is no FAQPage schema.
- If FAQ content exists in a draft package but is not visible in the rendered draft/public page, FAQPage is NO-GO.
- If FAQ visibility or source authority is Unknown, FAQPage is NO-GO until reviewed.

## Read-only Runtime Evidence

Read-only fap-web evidence found:

- `app/(localized)/[locale]/articles/[slug]/page.tsx` builds Article JSON-LD and BreadcrumbList for article details.
- The same article page maps `article.answerSurface.faqBlocks` into FAQPage only when question and answer values exist.
- `tests/contracts/article-answer-surface.contract.test.ts` verifies FAQPage is emitted from visible answer-surface FAQ and not emitted when visible FAQ is absent.
- `tests/contracts/fixtures/discoverability-foundation/structured-data-contract.v1.json` records article_detail authority as `cms_article_visible_content` and allows only Article, BreadcrumbList, and FAQPage.

This PR records the smoke gate only. It does not change fap-web runtime.

## CMS Package Requirements

Every future article content package should carry these non-copy fields before CMS draft planning:

- article locale,
- article slug placeholder,
- schema intent for Article and BreadcrumbList,
- FAQ intended state: `none`, `visible_cms_faq`, or `Unknown`,
- FAQ source authority,
- visible FAQ count,
- expected FAQPage mainEntity count,
- Article schema source: CMS SEO JSON-LD or article-derived fallback,
- Breadcrumb canonical path,
- claim-boundary notes,
- unresolved Unknown fields.

The package must not carry final FAQ copy in Codex-produced planning artifacts.

## Draft And Publish Gate

For drafts:

- draft route should remain noindex or absent from sitemap/llms,
- schema smoke can be recorded from authorized preview/dry-run output only if preview access is approved,
- if preview access is not approved, record draft schema as Unknown rather than 0.

For published articles:

- public page returns 200,
- Article JSON-LD exists unless explicitly blocked by article authority,
- BreadcrumbList exists,
- FAQPage exists only when visible FAQ exists,
- FAQPage `mainEntity` count matches visible FAQ count,
- sitemap/llms inclusion is checked only after publish,
- no private or tokenized URL is used during smoke.

## Failure Conditions

Block publish or follow-up expansion when:

- FAQPage exists without visible FAQ,
- visible FAQ exists but FAQPage count differs,
- schema contains private/result/order/share/pay/payment/history URLs,
- schema contains frontend-local editorial fallback content,
- Article/Breadcrumb schema is missing from a published indexable article,
- schema source authority is Unknown.

## Result

This smoke gate is reusable for the second and third SEO articles.

It keeps FAQ/schema stable by separating structure and authority checks from publishable FAQ copy.

## Next Task

Proceed to SEO-ARTICLE-PUBLISH-HOLD-GATE-01 after this PR merges.
