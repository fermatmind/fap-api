# Big Five Authority V2 structured data parity (PR45)

This change locks the PR38 audit subset containing nine FAQPage failures and four Article/Breadcrumb failures. The checked-in fixture is evidence input, not a claim that production pages have already changed.

Runtime authority is backend-only. A Big Five CMS Article emits Article, BreadcrumbList, or FAQPage fragments only when the current published revision is public, indexable, effective, canonically addressable, and backed by the PR42 visible-date and PR43 visible author/reviewer/source projections. Each schema family additionally requires its exact boolean CMS gate. FAQPage also requires non-hidden, editor-supplied visible FAQ items.

The reviewer is an eligibility gate. It is not written as `reviewedBy` on Article because that property is not valid for Article in the current Schema.org vocabulary. Source labels are emitted as Article citations; no source URL is invented. Raw `schema_json` cannot bypass the Big Five projection.

Repository rule impact: no ownership change. CMS/backend remains authoritative; frontend inference and fallback content remain prohibited. This PR does not modify Topic, media, sitemap, hreflang, or llms behavior and performs no CMS/database/production write.

Validation:

- `node generated/big-five-authority-v2/big5-authority-v2-structured-data-45/validate-package.mjs`
- `cd backend && php artisan test tests/Feature/SEO/BigFiveAuthorityV245Test.php --no-ansi`
- the two PR45 runtime-freeze tests listed in the PR train manifest
