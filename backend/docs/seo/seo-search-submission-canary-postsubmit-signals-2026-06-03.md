# SEO Search Submission Canary Post-Submit Signals

Date: 2026-06-03

PR train item: `SEO-SEARCH-SUBMISSION-CANARY-POSTSUBMIT-SIGNALS-01`

## Scope

This is a read-only post-submit signals review after:

- GSC sitemap submission for `https://fermatmind.com/sitemap.xml`.
- Baidu manual zh-CN URL submission for `https://fermatmind.com/zh/articles/mbti-vs-holland-career-choice`.

No submission or mutation was performed in this run.

## Result

GO: public SEO surfaces remain stable after search submission.

NO-GO: do not claim Baidu indexing or organic discovery yet. Public Baidu search does not show the zh-CN canary URL immediately after submission.

## Public Surface Checks

Checked at: `2026-06-03T11:41:57Z`.

| Surface | URL | Status | Result |
| --- | --- | ---: | --- |
| zh article page | `https://fermatmind.com/zh/articles/mbti-vs-holland-career-choice` | 200 | PASS |
| en article page | `https://fermatmind.com/en/articles/mbti-vs-holland-code-career-choice` | 200 | PASS |
| sitemap.xml | `https://fermatmind.com/sitemap.xml` | 200 | contains both canary slugs |
| llms.txt | `https://fermatmind.com/llms.txt` | 200 | contains both canary slugs |
| llms-full.txt | `https://fermatmind.com/llms-full.txt` | 200 | contains both canary slugs |
| robots.txt | `https://fermatmind.com/robots.txt` | 200 | no canary-specific block observed |

Article page checks:

- zh canonical: `https://fermatmind.com/zh/articles/mbti-vs-holland-career-choice`
- en canonical: `https://fermatmind.com/en/articles/mbti-vs-holland-code-career-choice`
- zh robots noindex: false.
- en robots noindex: false.
- hreflang entries on both article pages: 3.
- Article schema present on both pages.
- FAQPage schema present on both pages.
- Breadcrumb schema present on both pages.

## Public API Checks

Checked at: `2026-06-03T11:44:13Z`.

| Surface | URL | Status | Result |
| --- | --- | ---: | --- |
| zh article API | `https://api.fermatmind.com/api/v0.5/articles/mbti-vs-holland-career-choice?locale=zh-CN&org_id=0` | 200 | PASS |
| en article API | `https://api.fermatmind.com/api/v0.5/articles/mbti-vs-holland-code-career-choice?locale=en&org_id=0` | 200 | PASS |
| zh SEO API | `https://api.fermatmind.com/api/v0.5/articles/mbti-vs-holland-career-choice/seo?locale=zh-CN&org_id=0` | 200 | PASS |
| en SEO API | `https://api.fermatmind.com/api/v0.5/articles/mbti-vs-holland-code-career-choice/seo?locale=en&org_id=0` | 200 | PASS |
| zh article list API | `https://api.fermatmind.com/api/v0.5/articles?locale=zh-CN&page=1&per_page=50&org_id=0` | 200 | canary article found |
| en article list API | `https://api.fermatmind.com/api/v0.5/articles?locale=en&page=1&per_page=50&org_id=0` | 200 | canary article found |

List API canary row state:

| Locale | Article ID | Slug | Status | is_public | is_indexable |
| --- | ---: | --- | --- | --- | --- |
| zh-CN | 37 | `mbti-vs-holland-career-choice` | published | true | true |
| en | 39 | `mbti-vs-holland-code-career-choice` | published | true | true |

The article detail and SEO API payload checks did not find `noindex`, `private`, or `draft` markers for the canary detail payloads.

## GSC Signals

Read-only Google Search Console sitemap page check:

| Field | Visible value |
| --- | --- |
| Property | `sc-domain:fermatmind.com` |
| Sitemap | `https://fermatmind.com/sitemap.xml` |
| Type | sitemap |
| Submitted date | 2026-06-03 |
| Last read date | 2026-06-03 |
| Status | success |
| Discovered pages | 2,272 |
| Discovered videos | 0 |

No URL Inspection request indexing was performed.

## Baidu Signals

The Baidu manual zh-CN URL submission success was already confirmed in:

```text
backend/docs/seo/seo-search-submission-baidu-manual-zh-canary-confirm-2026-06-03.md
```

During this run, the Baidu Search Resource Platform link submission page was read only. It did not show a durable submitted-history state for the canary URL on a fresh visit.

Public Baidu search query:

```text
site:fermatmind.com/zh/articles/mbti-vs-holland-career-choice
```

Visible result:

```text
抱歉，未找到相关结果。
```

Interpretation: Baidu manual submission is confirmed, but Baidu public indexing/organic discovery is not yet visible. This is expected immediately after submission and should be reviewed after a processing window.

## Boundary Verification

- Submitted any GSC URL or sitemap: no.
- Submitted any Baidu URL or sitemap: no.
- Submitted English canary URL to Baidu: no.
- Called IndexNow: no.
- Created Search Channel queue records: no.
- Mutated CMS data: no.
- Published or unpublished content: no.
- Modified content: no.
- Modified runtime code: no.
- Deployed: no.
- Used, read, printed, stored, or exposed Baidu API token, GSC credential, cookies, browser storage, account email, or secrets: no.

## Follow-Up

Recommended next tasks:

1. `SEO-SEARCH-SUBMISSION-CANARY-BASELINE-REVIEW-01` after a 7-day and/or 14-day processing window.
2. `SEO-SEARCH-CHANNEL-CMS-ARTICLE-CANDIDACY-01` if internal Search Channel automation is needed later.

Do not submit additional search surfaces or run IndexNow without separate authorization.
