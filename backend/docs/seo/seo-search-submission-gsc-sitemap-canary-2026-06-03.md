# SEO-SEARCH-SUBMISSION-GSC-SITEMAP-CANARY-01

Date: 2026-06-03
Repo: fap-api
Task type: controlled GSC sitemap-only submission result archive

## Boundary

This task submitted only the public sitemap URL in Google Search Console after exact user authorization.

No URL Inspection request indexing, Baidu submission, IndexNow call, Baidu API token use, Search Channel queue record creation, CMS mutation, publish, unpublish, content edit, runtime code edit, deploy, or private URL access was performed.

## Submitted Surface

| Field | Value |
| --- | --- |
| GSC property | `sc-domain:fermatmind.com` |
| Submitted sitemap URL | `https://fermatmind.com/sitemap.xml` |
| Submission method | Logged-in Chrome Google Search Console sitemap page |
| Individual URL request indexing | Not performed |

## Visible GSC Result

GSC accepted the sitemap submission and displayed the success message:

- `已成功提交站点地图`

Immediate post-submit table state briefly showed the submitted sitemap row with an initial `无法抓取` state. After a read-only refresh and wait, the submitted sitemap table converged to:

| Field | Visible value |
| --- | --- |
| Sitemap | `https://fermatmind.com/sitemap.xml` |
| Type | `站点地图` |
| Submitted / read date | `2026年6月3日` |
| Status | `成功` |
| Discovered pages | `2,272` |
| Discovered videos | `0` |

Final visible state: PASS.

## Public Sitemap Cross-Check

Read-only public check after submission:

| Check | Result |
| --- | --- |
| `https://fermatmind.com/sitemap.xml` HTTP status | 200 |
| Response length | 333,760 bytes |
| Cache-Control | `public, max-age=0` |
| Contains zh canary URL | yes |
| Contains en canary URL | yes |

## Explicit Non-Actions

| Action | Performed |
| --- | --- |
| GSC URL Inspection request indexing | no |
| Individual URL submission | no |
| Baidu manual submission | no |
| Baidu sitemap submission | no |
| Baidu API push/token use | no |
| IndexNow call | no |
| Search Channel queue record creation | no |
| CMS mutation | no |
| Publish / unpublish | no |
| Runtime code edit | no |
| Deployment | no |

## Search Channel / Submission Surface Boundary

Backend Search Channel remains out of scope for this PR. The prior execution plan recorded that current backend Search Channel dry-runs return `canonical_url_not_found` for CMS article canonical URLs.

No Search Channel queue row was created by this task.

## Next Step

The next recommended task can proceed after this PR is merged:

`SEO-SEARCH-SUBMISSION-BAIDU-MANUAL-ZH-CANARY-01`

Recommended scope:

- submit only `https://fermatmind.com/zh/articles/mbti-vs-holland-career-choice`
- use Baidu Search Resource manual submission UI
- do not submit the English URL to Baidu
- do not use Baidu API push or expose the token
- do not submit GSC or IndexNow in the same task
- do not mutate CMS
- do not create Search Channel records

Exact confirmation phrase:

```text
I explicitly approve Baidu Search Resource manual submission for https://fermatmind.com/zh/articles/mbti-vs-holland-career-choice only. Do not submit the English URL. Do not use Baidu API push or expose the token. Do not submit GSC or IndexNow. Do not mutate CMS.
```

## Validation Commands

```bash
python3 -m json.tool docs/codex/pr-train-state.json >/dev/null
python3 -c "import yaml, pathlib; yaml.safe_load(pathlib.Path('docs/codex/pr-train.yaml').read_text()); print('yaml ok')"
git diff --check -- backend/docs/seo docs/codex
git diff --cached --check
```
