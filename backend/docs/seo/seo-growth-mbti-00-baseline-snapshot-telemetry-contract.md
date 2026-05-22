# MBTI Baseline Snapshot and Telemetry Contract

Task: SEO-GROWTH-MBTI-00

Type: docs/generated/test only.

This contract starts the MBTI growth planning train after SEO-GROWTH-MBTI-00A resolved the URL Truth ambiguity blocker. It defines the baseline scope, measurable surfaces, deferred surfaces, telemetry split, bot/human separation, PII boundaries, observation inputs, and non-authoritative sources for the first entity-level growth loop.

Growth loop: Search -> Content -> Test -> Result -> Report -> Revenue -> Observation -> Repair -> Next Action.

This PR does not write URL Truth, mutate CMS, enqueue or submit Search Channel URLs, modify fap-web, send Digital PR, run production operations, run migrations, enable schedulers, read production crawler logs, write to seo_intel, expose Metabase, auto-fix claims, auto-create internal links, create pSEO, or touch business DB / Tencent RDS / Node2 DB.

## Baseline Scope

The MBTI baseline covers:

- MBTI test page.
- MBTI research report.
- MBTI topic hub.
- MBTI personality type pages.
- MBTI articles.
- MBTI take/result/report/paywall/private flows.
- Digital PR HRZone/HREC state.
- Search Channel readiness state.
- Internal Link readiness state.
- Claim Lint readiness state.
- Funnel/revenue telemetry readiness state.

## Current Candidate URLs

Known public candidates from the 00A contract:

- `/en/tests/mbti-personality-test-16-personality-types`.
- `/zh/tests/mbti-personality-test-16-personality-types`.
- `/en/research/mbti-personality-types-salary-turnover-report`.
- `/zh/research/mbti-personality-types-salary-turnover-report`.

These remain candidates only. They require backend-authoritative dry-run confirmation, claim-safe verification where applicable, and later scoped approval before any Search Channel action.

## Deferred Surfaces

Deferred until backend authority is explicit:

- `/en/topics/mbti` and `/zh/topics/mbti`.
- `/en/personality/{type}` and `/zh/personality/{type}`.
- MBTI article URLs.

Private/noindex surfaces remain excluded:

- take.
- result.
- report.
- paywall.
- order.
- PDF.
- history.

## Telemetry Contract

Frontend observation events:

- `landing_view`.
- `test_cta_click`.
- `test_start_click`.
- `report_preview_view`.
- `unlock_click`.
- `checkout_button_click`.
- `email_form_view`.

Backend truth events:

- `attempt_created`.
- `attempt_submitted`.
- `result_generated`.
- `email_captured`.
- `order_created`.
- `payment_success`.
- `benefit_granted`.
- `report_access_granted`.
- `pdf_generated`.

Telemetry rules:

- frontend observation != backend truth.
- backend payment/order/report access is truth.
- bot/crawler traffic is excluded from conversion formulas.
- crawler traffic only enters crawler aggregate observation.
- email must not enter public HTML, search, analytics payloads, URLs, or Digital PR artifacts.

## Baseline Observation Inputs

Allowed observation inputs:

- backend-authoritative URL Truth candidates.
- claim lint states.
- Search Channel dry-run eligibility states.
- Issue Queue observations.
- crawler aggregate observations.
- /ops/seo read-only operational views.
- Digital PR manual tracking state.
- human-only funnel telemetry contracts.

Not truth:

- frontend fallback.
- static sitemap.
- static llms.
- crawler logs.
- search engine responses.
- Digital PR mentions.
- local copies.
- GA4/GSC/referral signals.

## Measurable Now

- Contract completeness.
- Candidate URL families.
- private/noindex exclusion boundary.
- telemetry event taxonomy.
- claim-gate requirements.
- Search Channel preconditions.
- internal link dry-run output shape.
- Digital PR manual tracking fields.

## Not Measurable Yet

- complete backend-authoritative URL Truth rows.
- verified production CMS topic/personality/article rows.
- live Search Channel outcomes.
- live Digital PR response outcomes.
- complete human-only revenue conversion formulas.
- production claim lint pass/fail across all MBTI surfaces.

## Next Task

After this PR merges, continue with `SEO-GROWTH-MBTI-01｜Entity Map and URL Truth Review`.
