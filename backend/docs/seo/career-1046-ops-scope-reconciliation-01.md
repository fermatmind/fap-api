# CAREER-1046-OPS-SCOPE-RECONCILIATION-01

## 1. Executive Summary

Production public career runtime is serving 1046 career job rows per locale and 2092 localized career detail URLs in sitemap and llms surfaces. The CMS/Ops career job count of 378 and the SEO Ops published discovery blocker count of 398 are not sourced from the same authority path as the public 1046 runtime.

The mismatch is primarily expected scope mismatch plus legacy CMS table scope and SEO Ops read-model disconnect from the runtime projection. It is not evidence that the public 1046 runtime is wrong. SEO Intel / SEO智能 still requires read-model repair or population because the observed URL Truth rows are 0 / unavailable while public runtime surfaces are live.

Final decision: `career_1046_ops_scope_reconciliation_completed_requires_ops_read_model_repair`.

## 2. Public Runtime 1046 Source

Public `/api/v0.5/career/jobs` is backed by `CareerJobListController`, which delegates to `CareerJobListBundleBuilder`. The builder starts from `CareerRuntimePublishProjectionVisibility::publicDetailItems()` and then limits or supplements older CMS / snapshot / directory sources to the runtime-published slug set.

The runtime projection authority is `CareerRuntimePublishProjectionLookup`. It loads, in order:

- `storage/app/private/career_runtime_publish_projection/career-runtime-publish-projection.json`
- latest full release ledger under `storage/app/private/career_release_ledger`
- cached dataset hub payload at `career:public-authority:dataset-hub:v3`

`CareerRuntimePublishProjectionService` marks rows `published` only when the public resolution type is `public_canonical_job`, canonical and robots gates pass, release gate passes, and detail route / sitemap / llms flags are live.

Read-only public API validation on May 29, 2026:

| Surface | Observed |
| --- | ---: |
| `/api/v0.5/career/jobs?locale=en` | 1046 items |
| `/api/v0.5/career/jobs?locale=zh-CN` | 1046 items |
| `/api/v0.5/career/datasets/occupations` `collection_summary.member_count` | 1046 |
| `/api/v0.5/career/datasets/occupations` `public_detail_indexable_count` | 1046 |

The local direct TLS connection to `api.fermatmind.com` failed with `SSL_ERROR_SYSCALL`, so the read-only API confirmation used the public same-origin `/api/v0.5/...` route, which returned 200.

## 3. CMS/Ops 378 Source

The Ops career jobs table is `CareerJobResource`, backed by `App\Models\CareerJob` and filtered to global CMS content with:

- `withoutGlobalScopes()`
- `where('org_id', 0)`

This resource does not read `occupations`, `career_job_display_assets`, release ledger rows, dataset hub members, or runtime projection rows. Therefore the observed CMS/Ops visible count of 378 is a legacy CMS `career_jobs` table scope, not the 1046 runtime occupation projection.

The 20 career guide SEO gaps are similarly sourced from `CareerGuide` / `career_guides` in `SeoOperationsPage` and `SeoOperationsService`, not from the runtime projection.

## 4. SEO Ops 398 Blocker Source

SEO Ops uses `SeoOperationsPage` and `SeoOperationsService`. The dashboard builds its career totals from:

- `CareerGuide::withoutGlobalScopes()->where('org_id', 0)->with('seoMeta')->latest('updated_at')->limit(500)`
- `CareerJob::withoutGlobalScopes()->where('org_id', 0)->with('seoMeta')->latest('updated_at')->limit(500)`

`published_discovery_blockers` is computed as published public records where either `is_indexable` is false or `hasGrowthBlocker()` is true. `hasGrowthBlocker()` checks metadata, canonical, and robots gaps against CMS SEO meta, not runtime projection visibility.

The observed 398 published discovery blockers are therefore a CMS/Ops backlog signal for 378 career job records plus 20 career guide records. It does not enumerate the 1046 runtime-published occupation detail pages.

## 5. SEO智能 / seo_intel Read Model Status

SEO Intel read-only dashboard services read only the `seo_intel` connection and these tables:

- `seo_urls`
- `seo_url_entities`
- `seo_issue_queue`
- `seo_search_channel_queue_items`
- `seo_search_channel_queue_batches`
- `seo_search_channel_queue_events`
- `seo_crawler_log_daily_aggregates`

`SeoUrlTruthReadService` reports live count from `seo_urls`. `SeoSearchChannelQueueReadService` reports live counts from Search Channel queue tables. `BackendAuthorityUrlTruthSource` currently builds candidates from scale catalog, research reports, content pages, and configured canaries; this inspected source path does not enumerate the 1046 career runtime projection.

Given the observed SEO智能 state of URL Truth rows 0 / read model unavailable, the current conclusion is read-model unavailable or unpopulated for the career runtime authority, not a public runtime count bug.

## 6. Source Table / Service Matrix

| Count / Surface | Source tables / artifacts | Services / routes | Runtime projection connected |
| --- | --- | --- | --- |
| 1046 public career runtime | runtime projection JSON, release ledger, dataset hub cache, `occupations`, display assets | `CareerRuntimePublishProjectionLookup`, `CareerJobListBundleBuilder`, `/api/v0.5/career/jobs` | Yes |
| 378 CMS/Ops career jobs | `career_jobs` | `CareerJobResource`, `SeoOperationsPage`, `SeoOperationsService` | No |
| 398 published discovery blockers | `career_jobs`, `career_guides`, related SEO meta tables | `SeoOperationsService::hasPublishedDiscoveryBlocker()` | No |
| 20 career guides | `career_guides` | `CareerGuideResource`, `SeoOperationsPage`, `SeoOperationsService` | No |
| 2092 sitemap career detail URLs | backend sitemap source, runtime projection filter, frontend sitemap route | `SitemapGenerator`, `SitemapSourceController`, fap-web sitemap | Yes |
| 2092 llms career detail URLs | fap-web `listBackendSitemapCareerJobPaths()` via career index / sitemap source fallback | `app/llms.txt/route.ts`, `app/llms-full.txt/route.ts`, `lib/seo/backendSitemapSource.ts` | Yes |
| SEO智能 URL Truth rows | `seo_urls`, `seo_url_entities` | `SeoUrlTruthReadService`, `SeoDashboardOverviewReadService` | Not for 1046 career runtime in inspected source |
| Search Channel queue | `seo_search_channel_queue_*` | `SeoSearchChannelQueueReadService`, planner / writer services | Not touched |

## 7. Expected vs Unexpected Mismatch

Expected:

- Public runtime has 1046 because it is runtime projection and dataset authority driven.
- CMS/Ops shows 378 because it reads the legacy/global CMS `career_jobs` table.
- SEO Ops shows 398 blockers because it counts CMS `career_jobs` and `career_guides` records with SEO meta gaps.
- sitemap / llms show 2092 because 1046 slugs are localized into English and Chinese detail URLs.

Unexpected / requires follow-up:

- SEO智能 URL Truth rows visible as 0 / unavailable while public runtime and discoverability surfaces are live.
- SEO Ops readiness is labeled against CMS scope only, so it can be misread as readiness for the 1046 runtime projection.

## 8. Production Risk Assessment

Public user risk is low for the observed 1046 rollout because public API, sitemap, llms, and llms-full agree on the live runtime count and excluded slugs remain absent.

Operations risk is medium because dashboards can lead operators to believe only 378 career jobs exist or that career SEO readiness is 0 percent for the entire public runtime. That is a scope-labeling and read-model risk, not a confirmed public serving regression.

No production write, DB mutation, CMS mutation, deploy, Search Channel action, URL submission, external search API call, raw log read, or fap-web change was performed.

## 9. Recommended Fixes

1. Add a dedicated runtime career projection panel to SEO Ops that labels the 1046 source as runtime projection / dataset authority, separate from CMS `career_jobs`.
2. Add URL Truth ingestion or read model support for runtime-published career detail URLs, sourced from the backend runtime projection or public dataset authority.
3. Rename or annotate the existing 378/398 SEO Ops widgets as CMS career content scope, not public runtime scope.
4. Add dashboard health copy for SEO智能 when `seo_urls` is 0 or unavailable, with source table and collector status.
5. Keep Search Channel disabled for this reconciliation until URL Truth is populated and reviewed.

## 10. Validation

Read-only public checks:

| Check | Result |
| --- | --- |
| `/api/v0.5/career/jobs?locale=en` | 200, 1046 items |
| `/api/v0.5/career/jobs?locale=zh-CN` | 200, 1046 items |
| `/api/v0.5/career/datasets/occupations` | 200, member_count 1046, public_detail_indexable_count 1046 |
| `/sitemap.xml` | 200, 2092 unique career detail URLs |
| `/llms.txt` | 200, 2092 unique career detail URLs |
| `/llms-full.txt` | 200, 2092 unique career detail URLs |
| Excluded slugs | 0 hits in sitemap / llms / llms-full career detail URL set |
| Search Channel | No action, no queue write, no URL submission |

Local validation commands are listed in the PR train manifest and state ledger for this task.

## 11. PR / Merge Result

Pending at report creation. The PR contains only the report, generated JSON, focused test, and authorized PR train metadata.

## 12. What Was Not Done

- No production write.
- No database mutation.
- No CMS mutation.
- No runtime promotion.
- No deployment.
- No fap-web runtime change or commit.
- No Search Channel action.
- No URL submission.
- No external search API call.
- No env, DNS, or nginx edit.
- No raw log read.
- No production user data access.
- No edit, publish, approve, delete, export, or configure action in Ops/CMS.

## 13. Final Decision

`career_1046_ops_scope_reconciliation_completed_requires_ops_read_model_repair`

This is not a public 1046 runtime bug. It is an expected scope mismatch between runtime projection authority and CMS/Ops legacy table scope, plus a SEO智能 read-model availability or population gap.

## 14. Next Task

`CAREER-1046-OPS-READ-MODEL-REPAIR-01`: add a read-only SEO Ops / SEO智能 bridge that ingests or displays runtime-published career URL Truth from the runtime projection authority, labels CMS scope separately, and keeps Search Channel submission disabled until reviewed.
