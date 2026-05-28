# GLOBAL-CAREER-RUNTIME-COHORT-PUBLISH-AUTHORITY-ALIGNMENT-01

## Summary

This PR aligns career job SEO authority with the career job detail runtime gate.

Before this fix, `/api/v0.5/career/jobs/{slug}` used `CareerJobDetailBundleBuilder` and runtime publish projection, while `/api/v0.5/career-jobs/{slug}/seo` could answer from CMS `CareerJob` rows directly. That allowed a drift state where a slug could be 404 in the runtime detail API but still appear indexable through the SEO authority endpoint.

## Implementation

- `/api/v0.5/career-jobs/{slug}/seo` now uses `CareerJobDetailBundleBuilder::buildBySlug()` as the authority gate.
- If the runtime detail bundle cannot be built, the SEO authority endpoint returns 404.
- If the runtime detail bundle is available and indexable, the SEO payload includes:
  - `meta.robots=index,follow`
  - `seo_surface_v1.index_eligible=true`
  - `seo_surface_v1.index_state=indexable`
  - `seo_surface_v1.sitemap_state=included`
  - `seo_surface_v1.llms_exposure_state=allow`

## Boundaries

No career cohort publish was performed. This PR does not promote the 1018 ready-not-public candidates, does not mutate CMS data, does not deploy, does not enqueue Search Channel, and does not submit URLs.

## Next Task

`DETAIL_READY_1048_ROLLOUT_DRY_RUN-01`
