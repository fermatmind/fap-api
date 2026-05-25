# Fermat CRO Result / Report Funnel

Status: draft internal operator skill.

This document adapts upstream `cro`, `paywalls`, `analytics`, and `emails` thinking into FermatMind's result/report conversion review process. It is not runtime code, not a content asset, not an installed skill, and not authorization to access production user data, mutate CMS, change paywalls, deploy, or submit Search Channel items.

## Authority Rules

- Backend result/report asset catalog is authority.
- Backend order/payment/benefit/report-access events are commercial truth.
- `fap-web` clone interpretation copy is not authority.
- Frontend CTA observations are not payment/order truth.
- Public runtime is observation only.
- No production user data may be accessed in scans.
- No real attempt/result IDs should be fetched unless explicitly approved and privacy-safe.

## Result Page Funnel

Core sequence:

1. landing view.
2. test start.
3. attempt submit.
4. result view.
5. report preview view.
6. unlock click.
7. checkout start.
8. order created.
9. payment success.
10. benefit granted.
11. report access granted.
12. PDF/email/share/My Results lifecycle.

The result page should clarify the user's signal and the next best action without overclaiming.

## Report Preview Funnel

The report preview must:

- explain the free vs paid boundary.
- show what the paid report adds.
- avoid false urgency.
- avoid clinical/career/IQ/income overclaim.
- preserve English/Chinese asset parity rules.
- keep report interpretation content backend-owned.

## Paid Unlock Funnel

Review:

- unlock CTA clarity.
- price and offer clarity.
- refund/support visibility where applicable.
- result-to-report continuity.
- payment entry path.
- post-payment report access path.

Do not treat `unlock_click` as purchase truth.

## Checkout / Order / Recovery Funnel

Backend truth events:

- `order_created`.
- `payment_success`.
- `benefit_granted`.
- `report_access_granted`.

Frontend observations:

- button view.
- CTA click.
- checkout click.
- modal open.
- copy exposure.

Payment success, benefit granted, and report access granted are backend truth. Frontend events only describe user interaction.

## My Results Funnel

Review:

- email lookup entry clarity.
- result card labels.
- report access status.
- paid/unpaid boundary.
- recovery path.
- no email leakage into URLs, analytics, public HTML, or Search Channel payloads.

No raw email should enter analytics/search/public HTML.

## PDF / Email / Share Lifecycle

Review:

- PDF labels and report sections are locale-safe.
- email copy is PII-safe and claim-safe.
- share copy does not expose private result data.
- share links respect private/noindex policies.
- lifecycle messaging does not overpromise outcomes.

## Invite Unlock Boundary

Invite unlock flows must not:

- bypass payment/benefit truth.
- expose private result/report content.
- imply diagnosis, hiring suitability, or guaranteed career outcome.
- leak emails, order ids, private report ids, or attempt ids into public search surfaces.

## Analytics / Telemetry Split

Frontend observation signals:

- `landing_view`.
- CTA visible/clicked.
- `result_view`.
- `report_preview_view`.
- `unlock_click`.
- PDF/share/email UI interactions.

Backend truth events:

- `test_start` / `start_attempt` where backend records it.
- `attempt_submit`.
- `order_created`.
- `payment_success`.
- `benefit_granted`.
- `report_access_granted`.

Analytics should attribute funnel behavior, not replace backend commerce truth.

## Bot and Crawler Exclusion

Conversion formulas must exclude:

- known bots.
- crawler traffic.
- internal QA sessions where identifiable.
- Search Channel canary checks.
- uptime probes.
- raw crawler log observations.

Crawler observations do not represent human conversion.

## PII Boundaries

Do not send or store in analytics/search/public HTML:

- raw email.
- phone number.
- order id where not explicitly approved for transaction tracking.
- private attempt id.
- private report id.
- user tokens.
- cookies or raw IP/user-agent.

## English Result / Report Asset Parity

Rules:

- EN result/report cannot silently fall back to zh-CN interpretation copy.
- missing EN interpretation assets must fail closed or be explicitly deferred.
- presentation labels must be distinguished from interpretation prose.
- backend result/report asset catalog remains authority.
- frontend clone interpretation copy must be classified non-authoritative or migration-only.

Related artifacts:

- `RESULT-EN-PARITY-00`.
- `RESULT-EN-PARITY-01`.
- `RESULT-EN-PARITY-02`.
- `RESULT-EN-PARITY-03`.
- `RESULT-EN-PARITY-04`.
- `RESULT-EN-PARITY-05`.
- `RESULT-EN-PARITY-06`.

## Funnel Review Checklist

- `landing_view`.
- `test_start`.
- `attempt_submit`.
- `result_view`.
- `report_preview_view`.
- `unlock_click`.
- `checkout_start`.
- `order_created`.
- `payment_success`.
- `benefit_granted`.
- `report_access_granted`.
- `pdf_download`.
- `email_capture`.
- My Results lookup.

For each event:

- identify frontend observation vs backend truth.
- identify page/source/CTA/test slug attribution.
- confirm bot/crawler exclusion.
- confirm PII exclusion.
- confirm locale and claim boundary.

## CRO Checks

- CTA clarity.
- free vs paid boundary.
- report value framing.
- no false urgency.
- no unsupported diagnostic, career, income, hiring, treatment, cure, IQ, salary, or turnover claims.
- no PII leakage.
- no crawler traffic in conversion formula.
- no raw email in analytics/search/public HTML.
- EN/ZH parity and no-zh-fallback rules remain intact.

## Stop Conditions

Stop if review requires:

- production user data.
- real private attempt/result IDs without explicit privacy-safe approval.
- CMS mutation.
- result/report logic change.
- payment/order logic change.
- content generation.
- Search Channel action.
- deploy.
