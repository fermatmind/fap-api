# Article Preview Route Test Report

## Commands run

```bash
cd /Users/rainie/Desktop/GitHub/fap-api/backend
FAP_ADMIN_PANEL_ENABLED=true php artisan route:list --name=ops.articles.preview
php artisan test --filter=ArticleDraftPreviewRouteTest
git diff --check
```

## Results

| Command | Result | Notes |
|---|---|---|
| `FAP_ADMIN_PANEL_ENABLED=true php artisan route:list --name=ops.articles.preview` | PASS | Listed `GET|HEAD ops/article-preview/{article}` route named `ops.articles.preview`. |
| `php artisan test --filter=ArticleDraftPreviewRouteTest` | PASS | 3 tests, 23 assertions. |
| `git diff --check` | PASS | No whitespace errors. |

## Focused test coverage

`ArticleDraftPreviewRouteTest` verifies:

- content-read admin can preview a draft article
- response has `X-Robots-Tag: noindex, noarchive, nosnippet`
- `Cache-Control` contains `no-store`
- preview renders working revision title/body
- preview redacts private URL/token content
- preview does not emit JSON-LD
- preview does not emit canonical link
- preview does not emit alternate/hreflang link
- preview does not change article `draft`, `is_public=false`, `is_indexable=false`, or `published_revision_id=null`
- non-content-read admin is forbidden
- `ArticleWorkspace::previewUrl()` builds `/ops/article-preview/{id}`

## Not run

- Full `bash backend/scripts/ci_verify_mbti.sh` was not run; this PR is scoped to an Ops CMS preview route and focused tests passed.
- `php artisan migrate` was not run because this PR adds no migration.
