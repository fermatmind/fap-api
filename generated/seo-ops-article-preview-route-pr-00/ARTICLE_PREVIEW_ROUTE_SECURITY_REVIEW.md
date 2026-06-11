# Article Preview Route Security Review

## Security decision

PASS_FOR_PR_REVIEW

## Guards implemented

| Guard | Implementation | Evidence |
|---|---|---|
| Ops-only access | Route uses existing Ops/admin/CMS-read middleware stack. | `backend/routes/web.php` |
| Content read authorization | `EnsureCmsAdminAuthorized:read` required. | `backend/routes/web.php` |
| No publish side effect | Controller only reads Article/working revision and returns a response. It does not call Article publish services or mutate models. | `backend/app/Http/Controllers/Ops/ArticleDraftPreviewController.php` |
| noindex | Response header `X-Robots-Tag: noindex, noarchive, nosnippet`; page meta robots mirrors this. | controller + Blade view |
| no-store | Response header `Cache-Control: no-store`; session middleware may append `private`, but no-store remains present. | controller + test |
| no canonical public authority | Preview page prints canonical metadata value as text only and does not emit `<link rel="canonical">`. | Blade view + test |
| no hreflang | Preview page does not emit alternate/hreflang link tags. | Blade view + test |
| no schema | Preview page does not emit `application/ld+json`. | Blade view + test |
| no sitemap/llms | New route is under `/ops/article-preview/{article}` and no sitemap/llms source was modified. | scoped changed files |
| no search submission | No search channel, seo_intel, or submission writer files were touched. | scoped changed files |
| no ISR revalidation | No content-release/revalidation route or job is called. | controller implementation |
| private URL redaction | Preview body redacts private result/order/payment/pay/share/history/take routes and sensitive query tokens before markdown rendering. | controller implementation + test |

## Private data boundary

The implementation does not query raw PII, orders, payments, users, attempts, results, shares, or sessions. It reads CMS Article content and SEO fields only.

## Known limitation

This is an Ops-rendered preview, not a pixel-identical fap-web public runtime preview. It is intentionally minimal and safe. If pixel parity is required later, add a separate fap-web preview route backed by a read-only preview API in a follow-up two-repo plan.
