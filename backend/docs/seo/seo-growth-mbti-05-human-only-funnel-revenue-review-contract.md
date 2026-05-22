# MBTI Human-only Funnel and Revenue Review Contract

Task: SEO-GROWTH-MBTI-05

Train: SEO-GROWTH-MBTI-PR-TRAIN-01

This is a docs / generated JSON / tests-only contract. It does not query production databases, does not touch the business DB, does not mutate analytics, does not expose PII, does not modify fap-web, and does not implement runtime telemetry.

## Purpose

The MBTI growth loop must separate frontend observation from backend truth before any revenue review. Conversion formulas are human-only. Known bots, suspected bots, crawlers, and identifiable internal/QA traffic are excluded from funnel and revenue formulas.

## Frontend Observation Events

- `landing_view`
- `test_cta_click`
- `test_start_click`
- `report_preview_view`
- `unlock_click`
- `checkout_button_click`
- `email_form_view`

Frontend observation is not backend truth.

## Backend Truth Events

- `attempt_created`
- `attempt_submitted`
- `result_generated`
- `email_captured`
- `order_created`
- `payment_success`
- `benefit_granted`
- `report_access_granted`
- `pdf_generated`

Backend `payment_success` is revenue truth. Backend `report_access_granted` is report access truth.

## Required Rules

- Conversion formulas use human-only traffic.
- `known_bot`, `suspected_bot`, and `crawler` traffic are excluded.
- Internal and QA traffic are excluded where identifiable.
- Crawler traffic belongs only in crawler aggregate observation.
- Frontend `unlock_click` is observation only.
- Email is PII and must not enter public HTML, search surfaces, analytics URLs, or Digital PR artifacts.
- Revenue review must not query the business DB in this PR.

## Funnel Metrics

- Human landing views.
- Human test starts.
- Human submitted attempts.
- Human generated results.
- Human report previews.
- Human unlock clicks as observation only.
- Human orders created.
- Human payment successes.
- Human benefits granted.
- Human report access grants.
- Human PDF generations.

## Dedupe Concepts

Contract-level dedupe keys may use attempt, order, email hash, report, session, and date grain. Raw email must not be exposed to public, search, Digital PR, or analytics URL surfaces.

## Source-of-truth Table Families

Future implementation may reference attempt, result, email capture, order, payment, benefit, report access, and PDF generation table families at a contract level only. This PR performs no production query.

## Gaps and Sidecars

- Bot/human separation is partially proven and must be verified before formulas are operational.
- GSC/GA4/referral inputs are not revenue truth.
- Frontend events remain observation until backend truth joins are explicit.

## Next Task

SEO-GROWTH-MBTI-06
