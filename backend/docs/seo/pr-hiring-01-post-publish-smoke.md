# PR-HIRING-01-POST-PUBLISH-SMOKE Report

## 1. Executive Summary

The Careers content page runtime is stable after the PR-HIRING-01 content
authority work and related Careers content updates. Public Careers pages return
HTTP 200 with exact apex canonicals and `index, follow` robots metadata.

The three PR-HIRING-01 role drafts remain non-runtime and are not exposed as
public role detail pages, sitemap entries, llms entries, footer/nav links, or
Search Channel queue items.

Final decision: `pr_hiring_01_post_publish_smoke_completed_stable`.

## 2. Source PR State

Relevant merged PRs:

- PR #1760: `PR-HIRING-01A: Align EN careers content authority`
- PR #1762: `PR-HIRING-01: Add open roles to careers pages`
- PR #1795: `PR-HIRING-01 hiring content authority package`

All three are merged with green GitHub checks.

## 3. CMS Published State

| Locale | Slug | Status | Public | Indexable | Published at | Updated at |
| --- | --- | --- | --- | --- | --- | --- |
| `en` | `careers` | `published` | true | true | 2026-05-30 | 2026-05-31 00:07:07 |
| `zh-CN` | `careers` | `published` | true | true | 2026-04-19 | 2026-05-31 00:07:08 |

## 4. API Runtime Check

The following public read-only API checks returned HTTP 200:

- `https://fermatmind.com/api/v0.5/content-pages/careers?locale=en&org_id=0`
- `https://fermatmind.com/api/v0.5/content-pages/careers?locale=zh-CN&org_id=0`
- `https://api.fermatmind.com/api/v0.5/content-pages/careers?locale=en&org_id=0`
- `https://api.fermatmind.com/api/v0.5/content-pages/careers?locale=zh-CN&org_id=0`

## 5. Public Runtime Check

| URL | HTTP | Title | H1 | Canonical | Robots |
| --- | --- | --- | --- | --- | --- |
| `https://fermatmind.com/en/careers` | 200 | `Work With FermatMind | FermatMind | FermatMind` | `Work With FermatMind` | `https://fermatmind.com/en/careers` | `index, follow` |
| `https://fermatmind.com/zh/careers` | 200 | `加入费马测试 | FermatMind` | `加入费马测试` | `https://fermatmind.com/zh/careers` | `index, follow` |

No staging canonical was observed.

## 6. Role Draft Exposure Check

The PR-HIRING-01 role package is still `draft_review_only`. Public role detail
paths return 404:

- `/en/careers/technical-partner-engineering-lead`
- `/en/careers/product-design-brand-systems-lead`
- `/en/careers/growth-seo-content-operations-lead`

The role keys were not found in public Careers page HTML, sitemap, `llms.txt`,
`llms-full.txt`, or public home navigation checks.

## 7. Sitemap / llms / Footer Exposure

Careers page exposure:

- `sitemap.xml`: EN and ZH Careers pages present.
- `llms.txt`: Careers pages not present in the short llms index.
- `llms-full.txt`: EN and ZH Careers pages present.
- Public `/en` and `/zh` navigation surfaces include the matching Careers link.

Role draft exposure:

- `sitemap.xml`: 0 role draft hits.
- `llms.txt`: 0 role draft hits.
- `llms-full.txt`: 0 role draft hits.
- Public `/en` and `/zh` navigation surfaces: 0 role draft hits.

## 8. Search Channel Safety

Production read-only checks found zero Search Channel queue items for:

- `https://fermatmind.com/en/careers`
- `https://fermatmind.com/zh/careers`
- `https://fermatmind.com/en/careers/technical-partner-engineering-lead`
- `https://fermatmind.com/en/careers/product-design-brand-systems-lead`
- `https://fermatmind.com/en/careers/growth-seo-content-operations-lead`

Queue item 2 remains the EN MBTI IndexNow item in `approved/submitted` state.
Queue item 3 remains the ZH MBTI IndexNow item in `approved/submitted` state.

## 9. Claim Boundary

The published Careers pages and the non-runtime role draft package do not
create a hiring suitability guarantee, salary guarantee, career success
guarantee, diagnosis, treatment, or cure claim in this task. No page body copy
was modified.

## 10. Sidecar Issues

`llms.txt` does not include the Careers pages while `sitemap.xml` and
`llms-full.txt` do include them. This is recorded as an observation only
because this task is read-only and did not change llms generation policy.

## 11. Validation

Focused validation:

- `php artisan test --filter=PrHiring01PostPublishSmoke --no-ansi`

Additional repository validation is recorded in the generated JSON artifact.

## 12. What Was Not Done

No CMS mutation, production data mutation, publish, deploy, Search Channel
action, URL submission, external search API call, env/DNS/nginx edit, raw log
read, or fap-web mutation was performed.

## 13. Final Decision

`pr_hiring_01_post_publish_smoke_completed_stable`

## 14. Next Task

`none_foundation_hiring_smoke_train_complete`
