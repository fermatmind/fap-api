# RIASEC-FULL-CONTENT-PACK-11-BE

## What changed

PACK-11-BE imports the V7.3 lifecycle assets into `backend/content_assets/riasec` and bridges them through backend-owned contracts only:

- `share_pdf_history_v1.zh-CN.json`
- `faq_v1.zh-CN.json`
- `faq_v1.zh-CN.md`
- `technical_note_user_summary_v1.zh-CN.json`
- `professional_method_boundary_v1.zh-CN.json`

## Backend bridge decision

This PR keeps the current runtime boundaries and only adds asset-backed lifecycle copy where the existing backend contract can already carry it safely:

- `RiasecTechnicalNoteService` now reads asset-backed summary and method-boundary copy.
- `RiasecTechnicalNoteService` exposes additive `technical_note_v1.lifecycle_copy_v1` metadata for share/PDF/history and FAQ copy.
- `RiasecPublicProjectionService` now emits additive `lifecycle_copy_v1` in `riasec_public_projection_v2`.

## Preserved invariants

- No scorer or question-pack changes.
- No Holland Code generation changes.
- No measured-score mutation.
- No report snapshot mutation.
- No share/PDF/history measured-payload mutation.
- No raw feedback public exposure.
- No internal `snapshot_id` public exposure.
- No frontend fallback copy.
- No new career match, job fit, ranking, or success-prediction claims.

## Conditional frontend follow-up

- `PACK-10-FE` remains deferred unless Action Lab / Next Exploration Nodes must become visible frontend modules.
- `PACK-11-FE` remains deferred unless lifecycle payload consumers need renderer or route-contract updates after this backend bridge.
