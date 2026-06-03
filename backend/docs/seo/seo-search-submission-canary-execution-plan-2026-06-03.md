# SEO-SEARCH-SUBMISSION-CANARY-EXECUTION-PLAN-01

Date: 2026-06-03
Repo: fap-api
Task type: read-only search submission execution planning

## Boundary

This planning task did not submit sitemap, call Google Search Console indexing submission, call Baidu push, call IndexNow, create Search Channel queue records, mutate CMS, publish, unpublish, deploy, read cookies/local storage, or expose secrets.

Chrome dashboard checks were read-only and used the already logged-in browser session. Dashboard account identifiers and submission tokens were intentionally not recorded in this report.

## Inputs

Primary input report:

- `backend/docs/seo/seo-search-submission-preflight-scan-2026-06-03.md`

Target canary URLs:

- zh-CN: `https://fermatmind.com/zh/articles/mbti-vs-holland-career-choice`
- en: `https://fermatmind.com/en/articles/mbti-vs-holland-code-career-choice`

Target sitemap:

- `https://fermatmind.com/sitemap.xml`

## Current Readiness Summary

Public SEO readiness remains usable for execution planning:

- article pages: PASS
- canonical / hreflang: PASS
- robots index/follow: PASS
- Article / FAQPage / BreadcrumbList schema: PASS
- sitemap / llms / llms-full enumeration: PASS
- public article API / SEO API: PASS
- CMS publish state: PASS
- private URL exposure check: PASS
- existing target URL submission rows: PASS, none found

Execution path readiness:

| Path | Decision | Reason |
| --- | --- | --- |
| GSC sitemap submit | GO after exact user authorization | GSC property and sitemap page are accessible; sitemap submit UI is visible. |
| GSC URL inspection / request indexing | CONDITIONAL GO after exact user authorization | URL inspection entry is visible; actual request-indexing flow was not opened or executed. |
| Baidu manual URL submit | GO after exact user authorization, zh URL only by default | Baidu verified-site dashboard and 普通收录 page are accessible; manual submit UI is visible. |
| Baidu sitemap submit | CONDITIONAL GO after exact user authorization | 普通收录 sitemap section is visible; exact field state should be rechecked immediately before execution. |
| Baidu API push | NO-GO by default | Dashboard exposes an API token, but Codex must not record or use it unless a separate secret-safe execution path is explicitly authorized. |
| IndexNow | NO-GO | Backend live gate/key are not confirmed and production config previously showed live submission disabled. |
| Backend Search Channel | NO-GO | Both canary canonical dry-runs returned `canonical_url_not_found`; CMS article URL Truth / candidacy is missing. |

Overall decision:

GO for channel-specific execution planning.

Do not run any submission until the user provides an exact approval phrase for the chosen channel.

## Chrome Dashboard Evidence

### Google Search Console

Observed through Chrome:

- Search Console property for `fermatmind.com` is accessible.
- Performance dashboard for `sc-domain:fermatmind.com` is accessible.
- Left navigation includes:
  - URL inspection
  - Pages
  - Sitemaps
  - Removals
  - Settings
- Sitemaps page is accessible at the `sc-domain:fermatmind.com` property.
- Sitemaps page shows:
  - "Add a new sitemap"
  - sitemap URL input
  - submit control
  - submitted sitemaps table

Not done:

- no sitemap was submitted
- no URL inspection query was run
- no request indexing action was run
- no account email was recorded

GSC readiness:

- property access: PASS
- sitemap submit UI access: PASS
- URL inspection UI access: PASS
- actual write/submit action: NOT PERFORMED

### Baidu Search Resource Platform

Observed through Chrome:

- Baidu Search Resource dashboard is accessible for site `https://fermatmind.com/`.
- Site dashboard shows site information and resource submission navigation.
- 普通收录 page is accessible.
- 普通收录 page shows:
  - API submission section
  - sitemap section
  - manual submission section
  - submission controls
- Manual submission area is visible.

Not done:

- no Baidu URL was submitted
- no sitemap was submitted
- no API push was called
- no token was copied into this report
- no token was used

Baidu readiness:

- dashboard access: PASS
- verified site context: PASS
- manual submit UI access: PASS
- sitemap submit UI access: CONDITIONAL, recheck field state before execution
- API push: NO-GO by default because it requires secret handling

## Recommended Execution Strategy

### Phase 1: GSC Sitemap Submission

Recommended first execution PR/task:

`SEO-SEARCH-SUBMISSION-GSC-SITEMAP-CANARY-01`

Why:

- Sitemap now contains both canary URLs.
- GSC sitemap submit UI is visible.
- This is lower risk than immediate URL Inspection request-indexing.
- It avoids sending individual URL request-indexing actions before post-submit monitoring is ready.

Execution scope:

- submit `https://fermatmind.com/sitemap.xml` in GSC for `sc-domain:fermatmind.com`
- capture visible success/error state
- do not submit individual URLs
- do not submit Baidu or IndexNow
- do not mutate CMS
- do not create Search Channel records

Exact confirmation phrase:

```text
I explicitly approve GSC sitemap submission for https://fermatmind.com/sitemap.xml on property sc-domain:fermatmind.com. Do not request indexing for individual URLs. Do not submit Baidu or IndexNow. Do not mutate CMS.
```

### Phase 2: Baidu Manual zh URL Submission

Recommended second execution PR/task:

`SEO-SEARCH-SUBMISSION-BAIDU-MANUAL-ZH-CANARY-01`

Why:

- Baidu verified-site dashboard is accessible.
- Baidu priority should be the Chinese canonical URL by default.
- Manual submission avoids Codex handling the Baidu API token.

Execution scope:

- submit only `https://fermatmind.com/zh/articles/mbti-vs-holland-career-choice`
- do not submit the English URL to Baidu by default
- do not use API push/token
- capture visible success/error state
- do not submit GSC or IndexNow
- do not mutate CMS
- do not create Search Channel records

Exact confirmation phrase:

```text
I explicitly approve Baidu Search Resource manual submission for https://fermatmind.com/zh/articles/mbti-vs-holland-career-choice only. Do not submit the English URL. Do not use Baidu API push or expose the token. Do not submit GSC or IndexNow. Do not mutate CMS.
```

### Optional: GSC URL Inspection Request Indexing

Recommended only after GSC sitemap submission is recorded or if a human specifically wants individual URL acceleration.

Potential task:

`SEO-SEARCH-SUBMISSION-GSC-URL-INSPECTION-CANARY-01`

Exact confirmation phrase:

```text
I explicitly approve GSC URL Inspection request indexing for https://fermatmind.com/zh/articles/mbti-vs-holland-career-choice and https://fermatmind.com/en/articles/mbti-vs-holland-code-career-choice on property sc-domain:fermatmind.com. Do not submit Baidu or IndexNow. Do not mutate CMS.
```

### Optional: Baidu Sitemap Submission

Recommended only if Baidu manual zh URL submission is not enough or if the user wants sitemap-level discovery.

Potential task:

`SEO-SEARCH-SUBMISSION-BAIDU-SITEMAP-CANARY-01`

Exact confirmation phrase:

```text
I explicitly approve Baidu Search Resource sitemap submission for https://fermatmind.com/sitemap.xml on site https://fermatmind.com/. Do not use Baidu API push or expose the token. Do not submit GSC or IndexNow. Do not mutate CMS.
```

### Deferred: IndexNow

Potential task:

`SEO-SEARCH-SUBMISSION-INDEXNOW-CANARY-01`

Current decision:

NO-GO.

Required before execution:

- confirm safe key presence without printing key
- confirm live gate policy
- decide whether to use backend runtime or manual external endpoint
- re-run no-write preflight

### Deferred: Backend Search Channel CMS Article Candidacy

Potential fix/planning task:

`SEO-SEARCH-CHANNEL-CMS-ARTICLE-CANDIDACY-01`

Current decision:

NO-GO.

Reason:

- Search Channel dry-run returned `canonical_url_not_found` for both CMS article canonical URLs.

Scope for later:

- make URL Truth / Search Channel planner recognize published CMS article canonical URLs
- preserve private/noindex/canonical gates
- do not live-submit during the fix

## Risk Boundary

Do not submit:

- draft URLs
- preview URLs
- noindex URLs
- non-canonical URLs
- result/order/share/payment/history/take URLs
- tokenized URLs
- English URL to Baidu unless later policy explicitly allows it

Do not use:

- Baidu API token unless a separate secret-safe path is authorized
- IndexNow key unless a separate secret-safe path is authorized
- backend Search Channel until CMS article candidacy is fixed

## Recommended Task Order

1. `SEO-SEARCH-SUBMISSION-GSC-SITEMAP-CANARY-01`
2. `SEO-SEARCH-SUBMISSION-BAIDU-MANUAL-ZH-CANARY-01`
3. Optional: `SEO-SEARCH-SUBMISSION-GSC-URL-INSPECTION-CANARY-01`
4. Optional: `SEO-SEARCH-SUBMISSION-BAIDU-SITEMAP-CANARY-01`
5. Deferred: `SEO-SEARCH-SUBMISSION-INDEXNOW-CANARY-01`
6. Deferred fix: `SEO-SEARCH-CHANNEL-CMS-ARTICLE-CANDIDACY-01`

## Proposed PR Train Entries Requiring User Authorization

This planning task did not update `docs/codex/pr-train.yaml` or `docs/codex/pr-train-state.json`.

Before opening PRs or doing channel-specific execution, user authorization should add the relevant train item. Suggested entries:

```yaml
- id: SEO-SEARCH-SUBMISSION-GSC-SITEMAP-CANARY-01
  repo: fap-api
  branch: codex/seo-search-submission-gsc-sitemap-canary-01
  title: "ops(seo): submit canary sitemap in GSC"
  train_scope: seo_cms_canary
  status: planned
  depends_on:
    - SEO-SEARCH-SUBMISSION-CANARY-EXECUTION-PLAN-01
  allowed_paths:
    - backend/docs/seo/**
    - docs/codex/pr-train.yaml
    - docs/codex/pr-train-state.json
  checks:
    - git diff --check -- backend/docs/seo docs/codex

- id: SEO-SEARCH-SUBMISSION-BAIDU-MANUAL-ZH-CANARY-01
  repo: fap-api
  branch: codex/seo-search-submission-baidu-manual-zh-canary-01
  title: "ops(seo): submit Chinese canary URL in Baidu"
  train_scope: seo_cms_canary
  status: planned
  depends_on:
    - SEO-SEARCH-SUBMISSION-CANARY-EXECUTION-PLAN-01
  allowed_paths:
    - backend/docs/seo/**
    - docs/codex/pr-train.yaml
    - docs/codex/pr-train-state.json
  checks:
    - git diff --check -- backend/docs/seo docs/codex
```

Follow-up authorization prompt:

```text
明确授权在 fap-api 新增并执行 PR train item SEO-SEARCH-SUBMISSION-GSC-SITEMAP-CANARY-01，更新 docs/codex/pr-train.yaml 和 docs/codex/pr-train-state.json，使用已登录 Chrome 的 Google Search Console 仅提交 https://fermatmind.com/sitemap.xml 到 sc-domain:fermatmind.com；不执行 URL Inspection request indexing，不提交百度/IndexNow，不改 CMS，不 publish，不创建 Search Channel 记录。
```

## Validation

Commands/checks run:

```bash
Chrome read-only GSC dashboard inspection
Chrome read-only Baidu Search Resource dashboard inspection
```

Local file check:

```bash
git diff --check -- backend/docs/seo/seo-search-submission-canary-execution-plan-2026-06-03.md
```
