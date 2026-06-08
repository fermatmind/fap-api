# RIASEC V2 zh Locale Contract Fix

Task: `SEO-ARTICLE-RIASEC-V2-ZH-LOCALE-CONTRACT-FIX-01`

Decision: `GO_ZH_CANONICAL_200_SEARCH_SUBMISSION_STILL_BLOCKED`

## Scope

This task fixed the published Chinese RIASEC article locale contract only. The goal was to make the public canonical route return 200:

- `https://fermatmind.com/zh/articles/riasec-holland-career-interest-test-explained`

The fix did not rewrite article body, title, H1, meta copy, FAQ entries, CTA labels, references, media, publication state, or tracking.

## Production Fix

Article `40` was already published, public, and indexable, but its backend locale fields used `zh`. Existing article/public API contracts expect Chinese article records to use `zh-CN`, while the frontend public route segment remains `/zh`.

The bounded production CMS update normalized locale fields only:

| Record | Before | After |
| --- | --- | --- |
| `articles.locale` | `zh` | `zh-CN` |
| `articles.source_locale` | `zh` | `zh-CN` |
| `article_seo_meta.locale` | `zh` | `zh-CN` |
| `article_editorial_package_imports.locale` | `zh` | `zh-CN` |
| `article_test_edges.locale` | `zh` | `zh-CN` |
| `article_translation_revisions.locale` | `zh` | `zh-CN` |

## Smoke Result

| Surface | URL/query | Status |
| --- | --- | --- |
| backend article API | article 40, `locale=zh-CN` | 200 |
| backend article API | article 40, `locale=zh` | 404, expected after normalization |
| frontend zh canonical | `/zh/articles/riasec-holland-career-interest-test-explained` | 200 |
| frontend en canonical | `/en/articles/what-is-riasec-holland-code-career-interest-test` | 200 |

## Remaining Gates

Search submission is still blocked. Sitemap and llms outputs were not fully converged after the locale fix:

- `sitemap.xml`: zh article absent, en article absent
- `llms.txt`: zh article absent, en article present
- `llms-full.txt`: zh article absent, en article present

Recommended next task: `SEO-ARTICLE-RIASEC-V2-POST-PUBLISH-SMOKE-02`.

Do not run GSC, Baidu, IndexNow, or any search submission until a separate post-publish smoke passes and separate exact search submission authorization is provided.
