# RIASEC V2 Post-Publish Smoke 02

Task: `SEO-ARTICLE-RIASEC-V2-POST-PUBLISH-SMOKE-02`

Decision: `NO_GO_SEARCH_SUBMISSION_SITEMAP_LLMS_NOT_FULLY_CONVERGED`

## Summary

The article runtime itself is healthy after the zh locale contract fix:

- zh article API: 200
- en article API: 200
- zh article page: 200
- en article page: 200
- Article, BreadcrumbList, and FAQPage JSON-LD are present on both pages
- FAQ JSON-LD count matches visible API FAQ count on both pages
- RIASEC CTA routes are public canonical test routes
- Article CTA tracking path is wired through `article_to_test_click`

Search submission remains blocked because sitemap and llms surfaces are not fully converged.

## Passed

| Gate | zh | en |
| --- | --- | --- |
| backend public article API | 200 | 200 |
| frontend article canonical | 200 | 200 |
| robots | `index, follow` | `index, follow` |
| canonical link | present | present |
| JSON-LD types | Article, BreadcrumbList, FAQPage | Article, BreadcrumbList, FAQPage |
| FAQ schema vs visible FAQ | 6 / 6 | 6 / 6 |
| public RIASEC CTA | present | present |
| CTA attribution params | present | present |

Tracking evidence:

- `app/(localized)/[locale]/articles/[slug]/page.tsx` renders article test CTAs through `SeoTrackedCtaLink`.
- `components/cta/SeoTrackedCtaLink.tsx` uses `article_to_test_click` for `article_detail`.
- `components/analytics/TrackedEntryCtaLink.tsx` calls `trackEvent` on click.

## Blocked

| Surface | zh article | en article | Status |
| --- | --- | --- | --- |
| backend sitemap-source | absent | absent | blocked |
| sitemap.xml | absent | absent | blocked |
| llms.txt | present | present | passed |
| llms-full.txt | absent | present | blocked |

## Decision

Do not run search submission preflight yet.

Recommended next task: `SEO-ARTICLE-RIASEC-V2-SITEMAP-LLMS-CONVERGENCE-FIX-01`.

That task should diagnose why published Article ids `40` and `41` are not fully represented in backend sitemap-source, sitemap.xml, and llms-full.txt. It should not submit URLs to search platforms.
