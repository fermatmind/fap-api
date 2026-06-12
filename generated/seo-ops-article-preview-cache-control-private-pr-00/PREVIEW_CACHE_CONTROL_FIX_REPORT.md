# Preview Cache-Control Fix Report

Decision: READY_FOR_PR

Scope:
- fap-api only.
- No CMS mutation.
- No draft import.
- No publish/index/sitemap/llms/search/revalidation changes.

Change implemented:
- Updated `ArticleDraftPreviewController` response header for `/ops/article-preview/{article}` from `Cache-Control: no-store` to `Cache-Control: no-store, private`.

Files changed:
- `backend/app/Http/Controllers/Ops/ArticleDraftPreviewController.php`
- `backend/tests/Feature/Ops/ArticleDraftPreviewRouteTest.php`

Safety notes:
- `X-Robots-Tag` remains `noindex, noarchive, nosnippet`.
- Preview response still renders no canonical link tag.
- Preview response still renders no hreflang/alternate tag.
- Preview response still renders no JSON-LD/schema.
- Route remains behind Ops/admin middleware.
- Public canonical article routes are not touched.
