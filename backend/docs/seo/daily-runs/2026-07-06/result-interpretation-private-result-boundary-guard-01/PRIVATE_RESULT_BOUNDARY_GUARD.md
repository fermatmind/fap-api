# Private Result Boundary Guard

Date: 2026-07-06
Scope: result-interpretation owner-route guardrail

## Boundary Matrix

| Surface family | Evidence path | Public owner-route eligibility | Notes |
| --- | --- | --- | --- |
| Attempts | `backend/routes/api.php` includes `/attempts/start`, `/attempts/submit`, `/attempts/{id}`, `/attempts/{id}/result`, `/attempts/{id}/report`, `/attempts/{id}/report-access`, progress, question delivery, feedback, invite unlocks, PDF, observation, and journey endpoints. | No | Product/API surfaces tied to attempts, identifiers, progress, results, or unlock state. |
| Results and reports | `backend/routes/api.php`; `backend/app/Http/Controllers/LookupController.php`; result-agent docs under `backend/docs/riasec` and `backend/docs/big5`. | No | May contain score, vector, unlock, report, share, PDF, or private state. |
| Orders and payments | `backend/routes/api.php` includes `/orders/checkout`, `/orders/lookup`, `/orders/{order_no}`, `/orders/{provider}`, `/orders/{order_no}/pay/alipay`, and recovery/resend paths. | No | Commerce/support surfaces; not SEO/GEO content owners. |
| Share and public summary variants | `backend/routes/api.php` includes `/attempts/{id}/share`; RIASEC handoff docs require share summaries to be redacted. | No as full owner | Share summaries must remain limited and cannot expose full report or private URL evidence. |
| Account/history/recovery | Help drafts discuss result recovery, history links, lookup links, and personal access links. | No | Support workflows are not owner-route candidates. |
| Email-generated report links | `backend/app/Services/Email/EmailOutboxService.php` builds report/report PDF/order lookup URLs. | No | Delivery links are access mechanisms, not public citation surfaces. |
| Public articles/guides | Existing support articles listed in the owner-route matrix. | Possible after authorization | Only public, indexable, backend/CMS-authorized routes can become owner candidates. |

## Guardrail Tests Applied

1. A candidate owner must be public and intentionally indexable.
2. A candidate owner must not require an attempt id, order number, payment state, email lookup, account state, token, or private access link.
3. A candidate owner must not expose raw scores, vectors, percentiles, report unlock state, internal metadata, selector traces, user identifiers, payment identifiers, or support recovery details.
4. A candidate owner must not be added to sitemap, llms, canonical, JSON-LD, or Search Console submission through this PR.

## Current Status

The boundary is clear enough for planning work:

- Public support routes can be evaluated in future owner-route selection.
- Private product and commerce routes are blocked from owner candidacy.
- The next Mode C brief queue should write public content briefs without referencing private result URLs as source or destination pages.

## Evidence Limits

This is a repository/document scan. It does not authenticate into production, access private user data, inspect secrets, or verify live noindex headers for private URLs.
