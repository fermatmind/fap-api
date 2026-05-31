# CAREER-1046-OPS-READ-MODEL-REPAIR-01

## 1. Executive Summary

This PR adds a read-only Career runtime projection read model for SEO/Ops so the
public Career runtime scope is no longer conflated with the legacy CMS
`career_jobs` table scope.

The intended production interpretation is:

- legacy CMS/Ops `career_jobs` scope: `378`
- runtime public Career detail slugs: `1046`
- localized public Career detail URLs: `2092`
- sitemap and `llms.txt` expected Career detail URL count: `2092`

The read model is intentionally non-mutating. It does not change runtime
projection, public API output, sitemap, `llms.txt`, `llms-full.txt`, Search
Channel, or CMS content.

## 2. Implementation

Added `CareerRuntimeReadModelService` under the SEO Intel Ops dashboard service
area. The service reads `CareerRuntimePublishProjectionVisibility` and returns a
separate runtime scope summary:

- runtime projection source-of-truth label
- legacy CMS scope label
- runtime public slug count
- localized public URL count
- sitemap and `llms.txt` expected URL counts
- excluded slug absence
- explicit no-write/no-Search-Channel flags

The legacy CMS count remains labeled as the legacy CMS career jobs table scope.
It is not treated as public runtime authority.

## 3. Runtime vs CMS Scope

The runtime Career surface is driven by backend runtime projection authority,
release ledger state, dataset authority, and public Career API contracts. The
legacy CMS/Ops `career_jobs` table is a separate editorial/admin scope.

This PR makes the split explicit:

| Metric | Scope | Count |
| --- | --- | ---: |
| Legacy CMS career jobs | `legacy_cms_career_jobs_table_scope` | 378 |
| Runtime public Career slugs | `runtime_projection_public_career_detail_scope` | 1046 |
| Localized runtime Career URLs | EN + ZH public detail URLs | 2092 |

## 4. SEO/Ops Read Model

The new read model gives SEO/Ops a safe bridge into the runtime Career
projection without using frontend rendering, sitemap, `llms.txt`, or Search
Channel as authority.

It is suitable as the backend source for a later dashboard panel that labels:

- CMS editorial scope
- public runtime projection scope
- localized URL exposure scope
- excluded slug safety

## 5. Safety Boundaries

Not performed:

- production write
- database mutation
- CMS mutation
- runtime promotion
- public runtime mutation
- deployment
- Search Channel enqueue
- URL submission
- external search API call
- fap-web change

Excluded slugs remain outside the read model's public runtime count:

- `software-developers`
- `digital-forensics-analysts`
- `computer-occupations-all-other`

## 6. Validation

Focused validation:

- `php artisan test --filter=Career1046OpsReadModelRepair01 --no-ansi`

Required repository validation is recorded in the PR train state for this task.

## 7. Final Decision

`career_1046_ops_read_model_repair_completed_ready_for_observability_slo`

## 8. Next Task

`CAREER-1046-OBSERVABILITY-SLO-01`
