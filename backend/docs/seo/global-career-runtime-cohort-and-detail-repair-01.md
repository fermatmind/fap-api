# GLOBAL-CAREER-RUNTIME-COHORT-AND-DETAIL-REPAIR-01

> Superseded runtime note (2026-05-29):
> This document reflects an older 30-job production cohort observation and is no longer the current live public truth.
> Current live production authority is:
> - `1046` public detail-indexable jobs
> - `2092` bilingual sitemap career detail URLs
> - `software-developers` remains manual hold / 404 / absent from sitemap
> - `1048` / `2096` remain planning or rollout targets, not live runtime truth
> - `342` remains the legacy dataset key/scope name (`career_all_342_*`), not the live public count

## Executive Summary

This backend-first diagnosis found that the sampled hard-404 detail URLs are not part of the current public career cohort. The current production cohort is 30 jobs. The sampled slugs `accountants-and-auditors` and `software-developers` are intentionally non-public today and fail closed with 404/noindex. They are not exposed in sitemap, llms, or footer/nav.

The current cohort itself has a separate authority mismatch: the backend job detail API returns 200 and `seo_contract.robots_policy=index,follow` for all 30 current cohort slugs, but production frontend metadata renders noindex for many of those pages. In the runtime sample, 18 EN cohort detail pages render indexable, 12 EN pages render noindex, and all 30 ZH cohort detail pages render noindex. This cannot be repaired by broad cohort activation or frontend fallback without an explicit controlled career publish/SEO authority decision.

## Career Count Meaning: 30 / 342 / 2289

- `30`: current runtime-published career cohort from the dataset authority response. Production `/api/v0.5/career/datasets/occupations` reports `member_count=30`, `included_count=30`, and `public_detail_indexable_count=30`.
- `342`: legacy B71X/DOCX `career_all_342` baseline. It is a governed baseline, not automatic public runtime exposure.
- `2289`: excluded runtime candidate/raw authority records reported by the dataset authority. These are not public jobs and must not be published by this repair.

## Public Cohort Policy

Current policy is fail-closed. Only the 30 runtime projection slugs are exposed in `/api/v0.5/career/jobs`. Non-cohort slugs return 404 from the backend detail endpoint.

The public job index pages link to the 30 runtime cohort detail routes. Production sitemap, llms.txt, and llms-full.txt expose zero career job detail URLs.

## API Runtime Findings

- `/api/v0.5/career/datasets/occupations?locale=en`: 200.
- `/api/v0.5/career/datasets/occupations?locale=zh-CN`: 200.
- `/api/v0.5/career/jobs?locale=en`: 200 with 30 items.
- `/api/v0.5/career/jobs?locale=zh-CN`: 200 with 30 items.
- All 30 current cohort detail API URLs sampled through both `en` and `zh-CN` returned 200.
- `/api/v0.5/career/jobs/accountants-and-auditors?locale=en`: 404.
- `/api/v0.5/career/jobs/accountants-and-auditors?locale=zh-CN`: 404.
- `/api/v0.5/career/jobs/software-developers?locale=en`: 404.
- `/api/v0.5/career/jobs/software-developers?locale=zh-CN`: 404.

## Public Frontend Findings

- `/en/career/jobs`: 200, index/follow, and links to 30 cohort detail pages.
- `/zh/career/jobs`: 200, index/follow, and links to 30 cohort detail pages.
- `/en/career/jobs/accountants-and-auditors`: 404/noindex.
- `/zh/career/jobs/accountants-and-auditors`: 404/noindex.
- `/en/career/jobs/software-developers`: 404/noindex.
- `/zh/career/jobs/software-developers`: 404/noindex.
- Current cohort detail pages return 200, but robots metadata is mixed:
  - EN cohort detail pages: 18 indexable, 12 noindex.
  - ZH cohort detail pages: 0 indexable, 30 noindex.

## Sitemap / llms / Footer Exposure

- sitemap career job detail exposure count: 0.
- llms.txt career job detail exposure count: 0.
- llms-full.txt career job detail exposure count: 0.
- Homepage footer/nav exposes no career job detail links.
- Career index pages expose the 30 cohort detail links only.

## Root Cause

The sampled slugs are not missing because of a timeout or frontend fallback. They are outside the current runtime projection, so the backend detail controller returns 404 and fap-web correctly renders notFound.

For current cohort detail pages, the remaining issue is an authority mismatch: backend detail bundles can claim `index,follow`, but production frontend metadata still renders noindex where SEO authority/trust/completeness gates do not line up. This requires a controlled career cohort publish or authority-alignment PR before job details can be considered fully public/indexable.

## Implementation

No runtime code was changed in this PR. The safe scoped output is the diagnosis artifact, generated JSON, focused test, and PR-train ledger entry. No CMS data, cohort membership, sitemap, llms, frontend route, or production runtime was mutated.

## Claim Boundary

This task did not expand career claims. Career pages remain bounded to occupation information, exploratory decision support, and interest/navigation context. It did not claim best career, hiring fit, job suitability guarantee, career success prediction, salary guarantee, or psychometric career determination.

## Recommended Fix

Create a controlled follow-up for career publish/SEO authority alignment. It should decide whether the 30 runtime cohort should be public indexable in both locales, whether ZH detail shells are still noindex by policy, and whether the SEO authority endpoint and fap-web metadata gate should align with backend `seo_contract` only after content completeness and claim boundaries pass.

If Product/SEO wants to promote any additional slugs beyond the current 30, use an explicit approval phrase before implementation:

`I explicitly approve a controlled career cohort publish and SEO authority alignment PR for the named career slugs, with no broad pSEO, no CMS mutation unless separately approved, no Search Channel action, no URL submission, and no deploy in the PR.`

## Decision

`career_runtime_requires_controlled_cms_or_cohort_publish`

## Next Task

`GLOBAL-CAREER-RUNTIME-COHORT-PUBLISH-AUTHORITY-ALIGNMENT-01`
