# CAREER-L3-DYNAMIC-SLOT-ARCHITECTURE-01

## 1. Executive Summary

This PR records the architecture boundary for future MBTI, Big Five, and RIASEC x Career personalization.

The approved model is dynamic-slot-only. Public career detail pages remain the 1046 backend-authoritative occupation pages. Personalized cross-assessment explanations may be rendered only as bounded runtime slots after a user context exists, not as static public pages.

## 2. Dynamic Slot Model

Dynamic slots are request-time or session-aware overlays that combine:

- backend career runtime authority;
- assessment result vectors or user-held result context;
- bounded explanation templates approved by backend/CMS authority;
- explicit claim and discoverability gates.

They must not create static URL families such as `/career/jobs/{slug}/mbti/{type}`, `/mbti/{type}/careers/{slug}`, or equivalent cross-matrix pSEO pages.

## 3. Static pSEO Block

The L3 combinatorial matrix is intentionally not generated:

- 1046 careers x 16 MBTI types is 16,736 combinations before Big Five and RIASEC are considered.
- Static SSG/ISR page generation would create build, sitemap, llms, and claim-boundary risk.
- Search surfaces must not expose personalized or cross-matrix slot content as public URL Truth.

## 4. Discoverability Boundary

Sitemap, `llms.txt`, `llms-full.txt`, Search Channel, and footer/nav exposure are limited to canonical backend-authoritative public pages.

Dynamic L3 slots are not independently sitemap-eligible, llms-eligible, Search Channel-eligible, or canonical URL entities until a future explicitly approved authority model exists.

## 5. Claim Boundary

L3 copy may frame outputs as exploratory decision support. It must not claim:

- best career for the user;
- hiring fit or job suitability guarantee;
- salary, turnover, or career success prediction;
- diagnosis, treatment, or cure;
- MBTI, Big Five, or RIASEC determines a career outcome.

## 6. Validation

Focused validation:

- `php artisan test --filter=CareerL3DynamicSlotArchitecture01 --no-ansi`

The test validates the generated artifact, non-generation flags, discoverability gates, blocked URL patterns, claim boundary, and report sections.

## 7. What Was Not Done

- No runtime L3 product was implemented.
- No static L3 pages were generated.
- No CMS mutation or content generation occurred.
- No sitemap, llms, footer/nav, or Search Channel exposure changed.
- No fap-web change was made.
- No deploy or production operation was performed.

## 8. Final Decision

`career_l3_dynamic_slot_architecture_completed_ready_for_release_train_sidecar_soft_alert`

## 9. Next Task

`RELEASE-TRAIN-SIDECAR-SOFT-ALERT-01`
