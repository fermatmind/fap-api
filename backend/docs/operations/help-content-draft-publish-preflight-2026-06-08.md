# HELP-CONTENT-DRAFT-PUBLISH-PREFLIGHT-01

Status: `blocked`

This read-only publish preflight checked the 12 Help service `ContentPage` CMS drafts after Operator approval R2. It did not mutate CMS rows, publish, deploy, submit search URLs, access private result/order/share/pay/payment/history URLs, read secrets/env/cookies/tokens, run payment/refund flows, or change payment-provider behavior.

## Decision

`NO-GO_FOR_PUBLISH_EXECUTE`

The preflight itself completed, but the Help service pages are not ready for publish execution.

## Evidence

| Check | Result |
| --- | --- |
| Source import package hash | `82a0d495f3fdd21df35696950eaa0b0a6b12d224d5dc7a8f82c8fa3e49cdcb65` |
| Draft source hash | `7d034de0b0eb5dc2c78fbb0e1828f3820e36f1c1a03d8f1567722c336438b453` |
| Production CMS rows checked | 12 |
| Draft / non-public / non-indexable / unpublished | 12 / 12 / 12 / 12 |
| Support contact | 12 rows match `support@fermatmind.com` |
| Policy version | 12 rows match `help_service_policy.v1` |
| FAQ items | 12 rows have 4 structured FAQ items |
| Public draft routes | 12/12 still 404 |
| `/zh/support` and `/en/support` | 200 |
| `/zh/privacy`, `/en/privacy`, `/zh/terms`, `/en/terms` | 200 |
| sitemap / llms / llms-full | no new Help service slug hits; no checked private-pattern hits |

## Blockers

1. Private-boundary phrase gate is blocked: three English Help draft rows contain the `payment_id` phrase pattern.
   - `help-data-deletion` / `en`
   - `help-payment-refund` / `en`
   - `help-unlock-failure` / `en`

2. FAQ schema runtime is not ready for these service topics. The current fap-web Help detail route emits `FAQPage` only for `/help/faq` from visible markdown extraction and does not yet prove CMS `faq_items` / `schema_enabled` authority for the six Help service topics.

3. Controlled publish runtime is not ready for this exact scope. The existing `content-pages:publish-controlled` command is bounded to the old English five-page scope (`brand`, `charter`, `foundation`, `careers`, `policies`) and does not allow the 12 Help service rows or `zh-CN`.

## Sidecars

| Task | Status | Purpose |
| --- | --- | --- |
| `HELP-CONTENT-DRAFT-PREFLIGHT-BLOCKER-REPAIR-01` | required | Remove blocked `payment_id` phrase hits from local/import/CMS drafts without changing policy meaning. |
| `HELP-SERVICE-FAQ-SCHEMA-RUNTIME-01` | required | Connect Help detail runtime to CMS `faq_items` / `schema_enabled` while preserving visible FAQ and JSON-LD parity. |
| `HELP-CONTENT-PAGES-CONTROLLED-PUBLISH-RUNTIME-01` | required | Add a fail-closed controlled publish path for exactly the 12 approved Help service rows. |
| `HELP-CONTENT-DRAFT-PUBLISH-PREFLIGHT-R2-01` | required | Rerun read-only publish preflight after repairs. |

## Scope Validation

| Boundary | Result |
| --- | --- |
| CMS mutation | no |
| Publish | no |
| Deploy | no |
| Search submission | no |
| Private URL access | no |
| Secret/env/cookie/token read | no |
| Payment/refund action | no |
| Payment provider change | no |

Next recommendation: `HELP-CONTENT-DRAFT-PREFLIGHT-BLOCKER-REPAIR-01`.
