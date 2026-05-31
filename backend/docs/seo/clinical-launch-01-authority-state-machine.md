# CLINICAL-LAUNCH-01 Authority State Machine

## Scope

This PR establishes the backend launch authority contract for sensitive mental-health public surfaces as a test-locked contract. It does not add runtime application code, change scoring, question wording, result models, CMS publication state, sitemap exposure, Search Channel submission, or production data.

## Controlled Scales

- `SDS_20`: depression screening standard edition.
- `CLINICAL_COMBO_68`: depression and anxiety professional combo.

## Launch States

- `DRAFT`
- `STAGED_NOINDEX`
- `SAFETY_REVIEWED`
- `INDEXABLE_CANARY`
- `INDEXABLE_PUBLIC`
- `RETIRED`

Direct launch from `DRAFT` to `INDEXABLE_PUBLIC` is intentionally blocked. A scale must pass staged noindex and safety review before canary indexing.

## Initial Authority Decisions

- `SDS_20` defaults to `INDEXABLE_CANARY`.
- `CLINICAL_COMBO_68` defaults to `STAGED_NOINDEX`.
- No robots contract uses `nocache`.
- No robots contract relies on `noarchive`.
- Staged surfaces use `noindex,follow`.

## P0 Gates Captured

- Claim boundary, public naming, and diagnostic disclaimer are mandatory.
- SDS source, authorization, translation, score range, and age suitability must be audited.
- SDS item 19 and Clinical high-risk items require crisis sentinel coverage.
- Crisis resources must be locale-aware; a global hardcoded `988` is not allowed.
- Sensitive health data consent, minor policy, data retention, no ad targeting, and paid report noindex gates are mandatory.
- Free safety information and paid personalized report boundaries must remain separate.
- Related article readiness is fixed at 9 articles: 3 tool, 3 safety, 3 growth.

## Deferred

- Runtime integration into registry, sitemap, public API, CMS UI, or frontend rendering.
- Moving the contract into runtime application services after the freeze classifier is explicitly updated for a clinical scope.
- SDS indexable canary execution.
- Clinical Combo public indexing.
- Any content, media, scoring, question, migration, deployment, or Search Channel mutation.
