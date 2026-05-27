# Career Runtime Cohort and Detail Repair

Task: `GLOBAL-CAREER-RUNTIME-COHORT-AND-DETAIL-REPAIR-01`

Date: 2026-05-27

## Scope

Backend-first runtime diagnosis and scoped repair for the public career jobs cohort, job list timeout, and detail 404/504 behavior.

This work did not mutate CMS data, did not publish the 2289 excluded records, did not generate pSEO pages, did not add sitemap or `llms.txt` exposure, and did not add fap-web fallback authority.

## Production Evidence

Observed through public runtime requests:

| Surface | Observation | Meaning |
|---|---|---|
| `/api/v0.5/career/datasets/occupations?locale=zh-CN` | `200`, `member_count=30`, `included_count=30`, `excluded_count=2289`, `public_detail_indexable_count=30` | Current cached dataset hub exposes a 30-item public runtime cohort. |
| Dataset manifest | `career.dataset_authority.runtime_projection_plus_legacy_342.v2` | Dataset builder is the runtime projection plus legacy 342 authority layer, not raw frontend content. |
| Dataset tracking | `expected_total_occupations=30`, `tracked_total_occupations=30`, `missing_occupations=0` | The cached runtime authority currently treats 30 as the active published cohort. |
| `/api/v0.5/career/jobs?locale=zh-CN` | `504 Gateway Time-out` after about 30 seconds | Job index runtime work exceeded the gateway budget. |
| `/api/v0.5/career/jobs/accountants-and-auditors?locale=zh-CN` | `504 Gateway Time-out` | Non-current-cohort detail resolution was timing out instead of failing closed quickly. |
| `/api/v0.5/career/jobs/software-developers?locale=zh-CN` | `504 Gateway Time-out` | Manual-hold detail resolution was timing out instead of failing closed quickly. |
| fap-web detail route | `fetchCareerJobBundle()` returns `null` for API errors; page route calls `notFound()` | Backend 504 can surface to users as frontend 404. |

## Cohort Meanings

`30` is the current runtime-published, public detail/indexable cohort from the cached dataset hub response. It is the only cohort this repair treats as actively public.

`342` is the legacy B71X/DOCX `career_all_342` baseline. The codebase defines it from batch manifests: `30` batch 2 members, `80` batch 3 members, `222` batch 4 members, plus `10` excluded first-wave slugs. It is a governed baseline, not automatic public runtime exposure.

`2289` is the current excluded count reported by the runtime dataset authority. Based on the code path, these are authority/candidate records excluded by runtime projection and release gates. They are not public jobs, and this repair does not publish them.

## Root Cause

Primary root cause:

`CareerRuntimePublishProjectionLookup` could fall back to building the full runtime projection through `CareerRuntimePublishProjectionExporter` during a public request when a materialized projection artifact was not available. That path can rebuild ledger/projection authority and is too expensive for public list/detail requests.

Secondary root cause:

`CareerJobListBundleBuilder` assembled list candidates from compiled snapshots, DOCX jobs, directory drafts, and runtime projection items before narrowing to the active runtime projection. That allowed work to scale toward legacy/raw cohorts even when the public runtime cohort was only 30.

Frontend 404 behavior:

The frontend route currently treats a failed or null career job API bundle as `notFound()`. Therefore backend timeout/null can appear as a 404 detail page. This repair keeps the authority fix in the backend and does not add frontend fallback data.

## Implemented Repair

1. `CareerRuntimePublishProjectionLookup`
   - Keeps materialized runtime projection as the first authority.
   - Keeps materialized release ledger projection as the second authority.
   - Adds a fast cached dataset hub fallback using `PublicCareerAuthorityResponseCache::DATASET_HUB_CACHE_KEY`.
   - Removes synchronous exporter rebuild from the public lookup fallback path.
   - Fails closed when no materialized projection, ledger, or cached dataset hub authority exists.

2. `CareerJobListBundleBuilder`
   - Reads runtime detail authority once.
   - Builds a release-gate slug set from `publicDetailItems()`.
   - When runtime detail authority exists, constrains compiled snapshot, DOCX, directory draft, and runtime projection list sources to that slug set.
   - Preserves legacy behavior for tests/dev contexts without explicit runtime projection authority.

## Expected Runtime Behavior After Deploy

| URL | Expected |
|---|---|
| `/api/v0.5/career/jobs?locale=zh-CN` | `200` within gateway budget, limited to runtime release-gate jobs. |
| `/api/v0.5/career/jobs/actuaries?locale=zh-CN` | `200` if content/display authority requirements pass for the current 30 cohort. |
| `/api/v0.5/career/jobs/accountants-and-auditors?locale=zh-CN` | Fast `404` unless Product/SEO promotes it into runtime projection. |
| `/api/v0.5/career/jobs/software-developers?locale=zh-CN` | Fast `404`; current code treats it as a manual hold. |
| `/zh/career/jobs` | Should stop showing detail-ready count `0` caused by job index timeout once backend list endpoint recovers. |

## Tests Added

- `CareerRuntimePublishProjectionLookupTest::test_it_falls_back_to_cached_dataset_hub_without_rebuilding_projection`
- `CareerJobListApiTest::test_runtime_projection_limits_public_job_index_to_release_gate_slugs`

## Still Needs Product/SEO Confirmation

- Whether `30` is the intended current public cohort or only a temporary launch cohort.
- Whether and when the legacy `342` baseline should become public.
- Whether any of the `2289` excluded records should move into a future release queue.
- Whether frontend should distinguish backend timeout from true `404` in user-facing career detail routes.

## Decision

`career_runtime_cohort_repair_completed_ready_for_deploy_readiness`
