# SEO-REVIEW-P1-10 Post-Publish Signals Review

Date: 2026-06-03
Repo: fap-api
PR train item: SEO-REVIEW-P1-10
Scope: post-publish public/search signal review only

## Boundary

This review did not publish, unpublish, mutate CMS state, submit sitemap, call Baidu push, call IndexNow, or trigger any search submission surface.

The production checks were read-only. The only production write referenced by this report was the earlier separately authorized controlled publish of article ids 37 and 39.

## Target Articles

| Article id | Locale | Slug | Published revision |
| --- | --- | --- | --- |
| 37 | zh-CN | `mbti-vs-holland-career-choice` | 42 |
| 39 | en | `mbti-vs-holland-code-career-choice` | 44 |

## Production CMS State

Read-only production DB verification on `/var/www/fap-api/current/backend` confirmed:

| Article id | Status | is_public | is_indexable | published_revision_id | translation_status | published_at UTC |
| --- | --- | --- | --- | --- | --- | --- |
| 37 | published | true | true | 42 | approved | 2026-06-03 04:42:59 |
| 39 | published | true | true | 44 | approved | 2026-06-03 04:43:00 |

Revision state:

| Revision id | Article id | revision_status | approved_at UTC | published_at UTC |
| --- | --- | --- | --- | --- |
| 42 | 37 | published | 2026-06-03 04:38:58 | 2026-06-03 04:42:59 |
| 44 | 39 | published | 2026-06-03 04:38:59 | 2026-06-03 04:43:00 |

SEO meta:

| Article id | robots | is_indexable |
| --- | --- | --- |
| 37 | index,follow | true |
| 39 | index,follow | true |

Relevant audit log rows were present for operator approval, content release publish, and Codex controlled publish for both article ids.

Result: PASS.

## Public Page And API Signals

Latest review check: 2026-06-03T12:50:30+08:00.

| Surface | URL | Status | Target slug present | noindex present | Result |
| --- | --- | --- | --- | --- | --- |
| zh page | `https://fermatmind.com/zh/articles/mbti-vs-holland-career-choice` | 200 | yes | no | PASS |
| en page | `https://fermatmind.com/en/articles/mbti-vs-holland-code-career-choice` | 200 | yes | no | PASS |
| zh article API | `https://api.fermatmind.com/api/v0.5/articles/mbti-vs-holland-career-choice?locale=zh-CN` | 200 | yes | no | PASS |
| en article API | `https://api.fermatmind.com/api/v0.5/articles/mbti-vs-holland-code-career-choice?locale=en` | 200 | yes | no | PASS |
| zh SEO API | `https://api.fermatmind.com/api/v0.5/articles/mbti-vs-holland-career-choice/seo?locale=zh-CN` | 200 | yes | no | PASS |
| en SEO API | `https://api.fermatmind.com/api/v0.5/articles/mbti-vs-holland-code-career-choice/seo?locale=en` | 200 | yes | no | PASS |
| zh article list API | `https://api.fermatmind.com/api/v0.5/articles?locale=zh-CN&page=1&per_page=50` | 200 | zh slug yes | no | PASS |
| en article list API | `https://api.fermatmind.com/api/v0.5/articles?locale=en&page=1&per_page=50` | 200 | en slug yes | no | PASS |

Page checks also found Article schema and FAQ schema on both public article pages. SEO API checks found Article schema and FAQ schema.

Result: PASS.

## Sitemap And LLM Enumeration

Latest review check: 2026-06-03T12:50:30+08:00.

| Surface | URL | Cache-Control | Target slugs present | Result |
| --- | --- | --- | --- | --- |
| sitemap.xml | `https://fermatmind.com/sitemap.xml` | `public, max-age=0` | no | NOT CONVERGED |
| llms.txt | `https://fermatmind.com/llms.txt` | `public, s-maxage=3600, stale-while-revalidate=86400` | yes | PASS, cache-sensitive |
| llms-full.txt | `https://fermatmind.com/llms-full.txt` | `public, s-maxage=3600, stale-while-revalidate=86400` | no | NOT CONVERGED |

Observed review history:

- Immediate public pages/API checks converged after publish.
- `llms.txt` showed cache-sensitive behavior during the review window: earlier checks varied, but the latest review check contained both target slugs.
- `sitemap.xml` did not contain either target slug during the review window.
- `llms-full.txt` did not contain either target slug during the review window.

Result: PARTIAL. `llms.txt` currently converged, but `sitemap.xml` and `llms-full.txt` did not converge.

## Search Submission Boundary

No search submission action was performed:

- No sitemap submission.
- No Baidu push.
- No IndexNow call.
- No search console submission.

Search submission should remain forbidden until sitemap and llms-full enumeration are understood or resolved.

## Decision

NO-GO for search submission.

The bilingual canary is published and public article/page/API signals are live, but sitemap and llms-full enumeration did not converge inside this review window. `llms.txt` is currently converged but cache-sensitive.

## Recommended Follow-Up

Proposed follow-up PR train item, requiring separate authorization before execution:

```yaml
- id: SEO-REVIEW-P1-10-SITEMAP-LLMS-ENUMERATION-01
  repo: fap-api
  depends_on:
    - SEO-REVIEW-P1-10
  branch: codex/seo-review-p1-10-sitemap-llms-enumeration-01
  title: "docs(seo): investigate SEO canary sitemap and llms-full enumeration gap"
  train_scope: seo_cms_canary
  status: planned
  scope:
    - Investigate why published, public, indexable canary articles enumerate in public APIs and llms.txt but not sitemap.xml or llms-full.txt.
    - Do not submit search surfaces.
    - Decide whether the gap is cache lag, frontend enumeration source mismatch, API pagination/filtering behavior, or a runtime bug requiring a separate fix PR.
  allowed_paths:
    - backend/docs/seo/**
    - docs/codex/pr-train.yaml
    - docs/codex/pr-train-state.json
  do_not:
    - Submit sitemap, call Baidu push, call IndexNow, publish, unpublish, mutate CMS data, or modify runtime code unless separately authorized in a later fix PR.
  validation:
    - python3 -m json.tool docs/codex/pr-train-state.json >/dev/null
    - python3 -c "import yaml, pathlib; yaml.safe_load(pathlib.Path('docs/codex/pr-train.yaml').read_text()); print('yaml ok')"
    - git diff --check -- backend/docs/seo docs/codex
    - git diff --cached --check
```

Follow-up execution prompt:

```text
明确授权在 fap-api 新增并执行 PR train item SEO-REVIEW-P1-10-SITEMAP-LLMS-ENUMERATION-01，更新 docs/codex/pr-train.yaml 和 docs/codex/pr-train-state.json，只做 sitemap/llms-full enumeration gap investigation，不提交 sitemap/Baidu/IndexNow，不 publish，不改 runtime code，除非调查报告后单独授权 fix PR。
```

## Validation Commands

Commands run for this review:

```bash
ssh "$API_SSH_ALIAS" 'cd /var/www/fap-api/current/backend && php artisan tinker --execute="... read-only articles/revisions/seo_meta/audit_logs check ..."'
python3 inline public page/API/sitemap/llms/llms-full check
```

Local PR validation commands:

```bash
python3 -m json.tool docs/codex/pr-train-state.json >/dev/null
python3 -c "import yaml, pathlib; yaml.safe_load(pathlib.Path('docs/codex/pr-train.yaml').read_text()); print('yaml ok')"
git diff --check -- backend/docs/seo docs/codex
git diff --cached --check
```
