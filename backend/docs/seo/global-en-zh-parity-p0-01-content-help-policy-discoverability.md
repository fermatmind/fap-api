# GLOBAL-EN-ZH-PARITY-P0-01 Content / Help / Policy Discoverability Cleanup

## Summary

GLOBAL-EN-ZH-PARITY-SCAN-00 found 18 content/help/policy URLs exposed in `sitemap.xml` that returned hard 404, plus `/en/support` and `/zh/support` exposed in `llms.txt` / `llms-full.txt`.

This PR keeps backend/CMS authority as the source of truth. It does not create placeholder content, does not mutate CMS, does not deploy, and does not submit URLs.

## Invalid URL Set

Sitemap hard-404 paths:

- `/en/help/about`
- `/en/help/contact`
- `/en/help/faq`
- `/en/help/for-business-and-research`
- `/en/help/team`
- `/en/help/used-and-mentioned`
- `/zh/help/contact`
- `/zh/help/faq`
- `/zh/help/for-business-and-research`
- `/en/method-boundaries`
- `/zh/method-boundaries`
- `/zh/policies`
- `/en/privacy`
- `/zh/privacy`
- `/en/support`
- `/zh/support`
- `/en/terms`
- `/zh/terms`

LLMS hard-404 overlap:

- `/en/support`
- `/zh/support`

## Root Cause

Backend `SitemapGenerator` already gates `content_pages` on published, public, indexable authority with non-empty content.

The invalid public exposure comes from the frontend discoverability consumer layer:

- `fap-web/lib/seo/sitemapAuthorityAdapters.cjs` statically generated missing-authority content/help/policy paths.
- `fap-web/app/llms.txt/route.ts` and `fap-web/app/llms-full.txt/route.ts` hardcoded support canonical URLs as primary entries.

## Current PR Boundary

`fap-api` records the authority evidence and guard test. The linked `fap-web` PR removes the invalid static sitemap/llms exposure. Career job URL exposure remains explicitly deferred to `GLOBAL-EN-ZH-PARITY-P0-02`.

## Safety

- No CMS mutation.
- No production mutation.
- No deploy.
- No URL submission.
- No Search Channel enqueue.
- No external search API call.
- No production migration.
- No placeholder content.
- No frontend fallback authority.

## Next Task

`GLOBAL-EN-ZH-PARITY-P0-02` should investigate and clean up career job detail sitemap exposure.
