# RIASEC English Landing CMS Experiment 01

## Scope

This package applies the Window 9 E02 same-canonical metadata and first-fold experiment to the backend-authoritative English RIASEC landing surface only.

- experiment: `FERMATMIND-EN-RIASEC-CMS-EXPERIMENT-01`
- surface key: `test_detail_holland_career_interest_test_riasec`
- locale: `en`
- canonical: `/en/tests/holland-career-interest-test-riasec`
- authority: `landing_surfaces` public API consumed by fap-web

The package changes title, description, H1, hero copy, the default-form CTA label, visible method/result boundary copy, and the answer-first text. The existing 60Q/140Q form identities, counts, times, routes, publication state, indexability, page blocks, internal-link allowlist and original `published_at` value stay unchanged.

## Exact evidence boundary

`current_public_readback.json` is the pre-apply public API snapshot captured from:

`GET /api/v0.5/landing-surfaces/test_detail_holland_career_interest_test_riasec?locale=en`

The raw response captured during preflight had SHA-256 `f934781aba6090587f00157bb9c58729dae1fe7f2dead4847ed549d4c99676d6`. The raw-byte SHA is provenance for that capture; the checked-in formatted snapshot has its own manifest SHA.

`target_internal_update.json` is the exact target package consumed by `RiasecGlobalCmsApplyBridge`; it is not a generic CMS PUT body. Its `page_blocks: []` value is an exact zero-block precondition only. The bridge fails closed if any enabled or disabled block exists and never passes `page_blocks` to `fill()` or any block mutation boundary.

## Apply and rollback contract

1. Deploy and verify the exact backend release that contains `App\Services\Ops\RiasecGlobalCmsApplyBridge`.
2. Sign in to the hidden Ops page `/ops/riasec-global-cms-apply` as an authenticated owner, complete the configured TOTP gate, and leave organization selection unset so the bridge receives the public org-0 authority context.
3. Before any CMS apply preflight, use the fixed production baseline workflow to capture the 2026-07-13 through 2026-08-09 product baseline defined by `product_funnel_baseline.json` through read-only reporting only. Its landing/start/completion/result projection must call `SeoConversionDailyBuilder::build` directly over the authoritative `events` rows, accept only root-relative identities or the exact HTTPS FermatMind origin, normalize the landing canonical and `/take` paths, and count downstream events only when `source_url` is the canonical landing or the event session is linked to a canonical `landing_pv` in the same frozen window. Direct or unrelated `/take` traffic is excluded from this experiment baseline. Canonical landing views are counted before form selection; downstream totals remain restricted to `riasec_60` and `riasec_140`. The workflow must reconcile every non-excluded raw event in that exact scope to the projected totals with zero delta, treat builder skips outside the scope as informational, never read or refresh the materialized daily table, and project the attempt funnel to exactly the same two forms with recomputed totals and coverage health. Freeze the exact landing/start/completion/result/failure aggregates, each raw report SHA-256, each report's exact healthy `ok`/`status`/empty-`issues` state, capture timestamp, and active backend revision. Do not continue while its status is `pending_preapply_production_read`, any required source is pending or unhealthy, or its repository hash is not bound by `measurement_plan.json` and `manifest.json`.
4. Enter the exact active backend `REVISION` and managed release-directory identity, paste the raw bytes of `current_public_readback.json` and `target_internal_update.json`, then run `preflightExactPackage`. The bridge verifies those runtime identities, the owner/session/org-0 authority context, the package byte hashes, and the live row before it issues a session-bound `preflight_fingerprint`, expiry, and exact operator approval phrase.
5. Continue only when the server-side receipt reports `ready_to_apply`, the exact before/target SHA-256 values match this package, and the generated preflight remains inside its 15-minute lifetime. Obtain a new explicit user authorization containing the exact generated phrase. A prior deploy approval, prior CMS approval, expired preflight, another owner/session, or another SHA/release is not reusable.
6. In the same owner session, enter the exact generated phrase and run `applyExactPackage` once. The bridge validates and consumes the apply authorization before entering one database transaction, then locks the exact row with `lockForUpdate`, rechecks the full before state, refuses any page-block state, writes only the allowlisted landing-surface fields, and verifies exact post-write readback before commit. A repeat that would return `already_applied` still requires its own fresh apply preflight and exact phrase.
7. Read back the exact successful `riasec_global_cms_apply` audit row and bind `t0_receipt.json` to its deployed SHA, release id, preflight fingerprint, operator-phrase SHA-256, audit timestamp, and immutable sanitized audit-record SHA-256. A matching rendered state without this controlled bridge audit is not a successful experiment apply.
8. Read back the public API and rendered canonical page, then record exact T+0 facts and checkpoint timestamps in `t0_receipt.json` and `measurement_plan.json`.
9. If exact copy, CTA routes, canonical, public/indexable state, or readback fails, run `preflightExactRollback` and obtain a separate fresh explicit user authorization containing its exact generated rollback phrase before using `rollbackExactPackage`. Apply authorization never authorizes rollback. The bridge consumes the rollback authorization once, then locks and revalidates the exact target state before restoring the exact before state; an `already_rolled_back` retry also requires a fresh rollback preflight and phrase. A completed rollback receipt must bind the separate `riasec_global_cms_rollback` audit using the same immutable audit identity fields.

The pre-apply production baseline is frozen from successful workflow run `31490673495`, attempt `1`, captured at `2026-08-11T12:20:01Z` against active revision `6a76cf16a1162b93b4a47b4e681efe647a21c013` and managed release `standard-6a76cf16a116-31489724464-1`. The sanitized receipt SHA-256 is `ab133c8a7e7054c1f1ac827888ebc42dbbc41b069ef86d67adfe5ccf8ec7d20a`; all three sources were healthy, every frozen aggregate was zero, and every declared write/mutation guarantee remained false.

Do not call the generic internal landing-surface PUT, use generic free-form CMS editing, or manually replay the JSON through another controller/service. Do not touch any other locale or surface. Do not change canonical, hreflang, schema/JSON-LD behavior, page blocks, sitemap, llms, Search Channel, application code, database schema, secrets, permissions, or analytics event contracts.

## Measurement clock

T+0 begins only after exact public API and rendered-page readback pass. T+3 is safety/instrumentation only; T+7 is directional; T+14 is interim; T+28 is the first keep/revise/rollback/insufficient-data decision point. No expansion conclusion is authorized before a complete comparable window.

## Repository rule impact

The surface remains CMS/backend-authoritative. No new content surface, fallback, publishing ownership, runtime contract, public API schema, or discoverability authority is introduced.
