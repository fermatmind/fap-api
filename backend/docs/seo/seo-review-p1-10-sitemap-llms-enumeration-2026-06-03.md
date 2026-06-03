# SEO-REVIEW-P1-10-SITEMAP-LLMS-ENUMERATION-01

Date: 2026-06-03
Repo: fap-api
PR train item: SEO-REVIEW-P1-10-SITEMAP-LLMS-ENUMERATION-01
Scope: sitemap / llms-full enumeration gap investigation only

## Boundary

This investigation did not publish, unpublish, mutate CMS state, submit sitemap, call Baidu push, call IndexNow, call search console submission, or modify runtime code.

Production checks were read-only. fap-web runtime files were read for investigation only and were not modified.

## Target URLs

| Locale | Article id | Slug | Public URL |
| --- | --- | --- | --- |
| zh-CN | 37 | `mbti-vs-holland-career-choice` | `https://fermatmind.com/zh/articles/mbti-vs-holland-career-choice` |
| en | 39 | `mbti-vs-holland-code-career-choice` | `https://fermatmind.com/en/articles/mbti-vs-holland-code-career-choice` |

## Current Public Signal Snapshot

Latest live check: 2026-06-03T13:10:09+08:00.

| Surface | Result | Evidence |
| --- | --- | --- |
| zh article detail API | PASS | 200, contains both canary slugs through article and alternate payload |
| en article detail API | PASS | 200, contains both canary slugs through article and alternate payload |
| public article list API | PASS | `page=1&per_page=40` returns zh article id 37 and en article id 39 as first item for their locale |
| `llms.txt` | PASS | 200, contains both canary slugs |
| backend `/api/v0.5/seo/sitemap-source` | NOT CONVERGED | 200, `X-Fermat-Cache: stale`, count 2387, contains neither canary slug |
| frontend `sitemap.xml` | NOT CONVERGED | 200, contains neither canary slug |
| `llms-full.txt` | NOT CONVERGED | 200, `Generated-At: 2026-06-03T04:43:43.012Z`, contains neither canary slug |

## Finding A: Backend Sitemap Source Is Serving Stale Cache

Read-only production tinker check on `/var/www/fap-api/current/backend`:

```json
{
  "fresh_is_array": false,
  "stale_is_array": true,
  "stale_count": 2387,
  "stale_contains": [false, false],
  "direct_count": 2389,
  "direct_contains": [true, true],
  "direct_matches": [
    {
      "loc": "https://fermatmind.com/en/articles/mbti-vs-holland-code-career-choice",
      "lastmod": "2026-06-03T04:43:00+00:00",
      "slug": "articles:en:mbti-vs-holland-code-career-choice"
    },
    {
      "loc": "https://fermatmind.com/zh/articles/mbti-vs-holland-career-choice",
      "lastmod": "2026-06-03T04:42:59+00:00",
      "slug": "articles:zh:mbti-vs-holland-career-choice"
    }
  ]
}
```

Interpretation:

- `SitemapGenerator::generateUrls()` already includes both canary article URLs.
- The live backend sitemap-source endpoint does not include them because `seo:sitemap-source:v1:stale` still holds the old 2387-item payload.
- `SitemapSourceController` returns stale cache when no fresh cache exists, so the request path does not rebuild while stale cache is present.
- The existing `seo:warm-sitemap-source-cache --json` command can build and store a 2389-item payload, but running it is a cache write operation and was not authorized in this investigation.

Result: backend data and generator are correct; backend sitemap-source cache refresh is missing after controlled article publish.

## Finding B: Frontend sitemap.xml Is A Static Build Artifact

Read-only fap-web code inspection:

- `next-sitemap.config.js` builds article paths through `/v0.5/articles`.
- That build-time article path source would currently see article ids 37 and 39 because the public article list APIs return them on page 1.
- The live `https://fermatmind.com/sitemap.xml` remains unchanged because it is a generated static sitemap artifact from the active frontend build.
- A normal HTTP cache window cannot add post-build article URLs to a static sitemap artifact.

Interpretation:

- Waiting for cache alone is not sufficient for `sitemap.xml` to converge.
- A frontend rebuild/redeploy would likely regenerate `sitemap.xml` with the canary article paths, because the public article APIs now expose them.
- A durable fix would make sitemap enumeration refresh part of the controlled content publish workflow, or move the relevant sitemap source to a runtime/dynamic authority with a controlled invalidation path.

Result: frontend sitemap convergence requires a separately authorized rebuild/deploy or a separately authorized runtime/workflow fix.

## Finding C: llms-full.txt Is Blocked By Response Cache / Revalidation Gap

Read-only fap-web code inspection:

- `app/llms-full.txt/route.ts` uses `LLMS_FULL_CACHE_FRESH_MS = 60 * 60 * 1000`.
- It uses `LLMS_FULL_CACHE_STALE_MS = 24 * 60 * 60 * 1000`.
- It reads articles with `listCmsArticlesForLlmsWithLastKnownGood`, `LLMS_ROUTE_LIMITS.articles = 40`, and `LLMS_ROUTE_ARTICLE_MAX_PAGES = 1`.
- The canary articles are currently first item in their locale article list API, so the one-page article budget is not the immediate blocker.
- The current live `llms-full.txt` response has `Generated-At: 2026-06-03T04:43:43.012Z`, but does not contain the canary slugs.
- `app/api/content-release/revalidate/route.ts` can clear the llms-full response cache only when `/llms-full.txt` is accepted in the revalidation path list.
- For `content.type === "article"`, the default derived revalidation paths are article list and detail paths only; `/llms.txt` and `/llms-full.txt` are not automatically added.

Interpretation:

- `llms-full.txt` generated and cached an article set that did not include the new canary URLs.
- The content-release revalidation route has the capability to clear `llms-full` cache, but article release metadata does not derive that path automatically.
- `llms.txt` is already converged, so the article list source itself is not blocked.

Result: `llms-full.txt` requires a separately authorized revalidation/cache-clear operation or a workflow/runtime fix that includes llms-full in article publish invalidation.

## Search Submission Decision

NO-GO for search submission.

Do not submit sitemap, Baidu push, IndexNow, or search console URLs while:

- `sitemap.xml` does not enumerate the published article URLs.
- `llms-full.txt` does not enumerate the published article URLs.
- backend sitemap-source is still serving stale cache for the canary URLs.

## Recommended Next Actions

### Immediate Ops Option Requiring Separate Exact Authorization

These are not executed by this PR:

1. Warm backend sitemap-source cache on production current release:

```bash
cd /var/www/fap-api/current/backend && php artisan seo:warm-sitemap-source-cache --json
```

Expected read-after-write evidence:

- `/api/v0.5/seo/sitemap-source` returns count 2389.
- `/api/v0.5/seo/sitemap-source` contains both canary article URLs.

2. Clear/revalidate frontend llms routes using the existing content-release revalidation endpoint with an authorized token and explicit paths:

```json
{
  "cache_signal": {
    "paths": ["/llms.txt", "/llms-full.txt"]
  }
}
```

Expected read-after-write evidence:

- `llms-full.txt` has a newer `Generated-At`.
- `llms-full.txt` contains both canary article URLs.

3. Regenerate `sitemap.xml` through a separately authorized frontend deployment or a separately authorized sitemap runtime fix.

Expected evidence:

- `https://fermatmind.com/sitemap.xml` contains both canary article URLs.

### Durable Fix PR Candidates

These require separate authorization and should not be combined with this investigation PR:

1. fap-api controlled publish cache refresh:
   - Add a post-publish cache refresh or cache invalidation path for `seo:sitemap-source:v1:*`.
   - Validate that controlled publish causes backend sitemap-source to include newly published article URLs.

2. fap-web content-release revalidation expansion:
   - For article release metadata, derive `/llms.txt` and `/llms-full.txt`.
   - Ensure `/llms-full.txt` cache is cleared when the route is revalidated.

3. fap-web sitemap convergence strategy:
   - Either keep static sitemap and require a controlled frontend rebuild after CMS publish, or move relevant article sitemap enumeration to a runtime source with explicit cache invalidation.

## Proposed Follow-Up PR Train Item

```yaml
- id: SEO-REVIEW-P1-10-SITEMAP-LLMS-FIX-01
  repo: fap-web
  depends_on:
    - SEO-REVIEW-P1-10-SITEMAP-LLMS-ENUMERATION-01
  branch: codex/seo-review-p1-10-sitemap-llms-fix-01
  title: "fix(seo): refresh article publish sitemap and llms enumeration"
  train_scope: seo_cms_canary
  status: planned
  scope:
    - Add or document the controlled runtime path needed for published CMS article URLs to converge in sitemap.xml and llms-full.txt.
    - Keep CMS/backend as content authority.
    - Do not submit search surfaces.
  allowed_paths:
    - app/api/content-release/revalidate/**
    - app/llms-full.txt/**
    - tests/contracts/**
    - docs/**
    - docs/codex/pr-train.yaml
    - docs/codex/pr-train-state.json
  do_not:
    - Publish, unpublish, mutate CMS data, submit sitemap, call Baidu push, call IndexNow, change GPT content, or add frontend editorial fallback content.
```

Follow-up execution prompt:

```text
明确授权在 fap-web 新增并执行 PR train item SEO-REVIEW-P1-10-SITEMAP-LLMS-FIX-01，修复 CMS article publish 后 sitemap / llms-full 枚举刷新路径；不提交 sitemap/Baidu/IndexNow，不 publish，不改 CMS 内容，不新增 frontend editorial fallback。
```

## Validation Commands

Investigation commands:

```bash
python3 inline public API / sitemap / llms / llms-full signal check
ssh "$API_SSH_ALIAS" 'cd /var/www/fap-api/current/backend && php artisan tinker --execute="... read-only Cache::get and SitemapGenerator::generateUrls check ..."'
sed -n '1,260p' backend/app/Services/SEO/SitemapGenerator.php
sed -n '1,280p' backend/app/Http/Controllers/API/V0_5/SEO/SitemapSourceController.php
sed -n '995,1035p' /Users/rainie/Desktop/GitHub/fap-web/app/llms-full.txt/route.ts
sed -n '1,310p' /Users/rainie/Desktop/GitHub/fap-web/app/api/content-release/revalidate/route.ts
```

Local PR validation commands:

```bash
python3 -m json.tool docs/codex/pr-train-state.json >/dev/null
python3 -c "import yaml, pathlib; yaml.safe_load(pathlib.Path('docs/codex/pr-train.yaml').read_text()); print('yaml ok')"
git diff --check -- backend/docs/seo docs/codex
git diff --cached --check
```
