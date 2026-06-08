# HELP-CONTENT-DRAFT-PUBLISH-PREFLIGHT-R3-01

Status: `ready_for_exact_publish_authorization`

This read-only R3 preflight reran the Window 9 Help service publish checks after the backend and frontend production runtime deploys. It did not mutate CMS rows, publish, deploy, submit search URLs, access private result/order/share/pay/payment/history URLs, read secrets/env/cookies/tokens, run payment/refund flows, change payment-provider behavior, or claim Operator approval.

## Decision

`GO_FOR_EXACT_PUBLISH_AUTHORIZATION_PROMPT`

The production runtime now contains the required frontend Help FAQ schema runtime and backend Help service controlled publish runtime. The controlled backend dry-run is clean for exactly the 12 existing Help service draft rows.

## Evidence

| Check | Result |
| --- | --- |
| Source import package hash | `15843defa1d3925624a49844e0ff15244a6cdff6cbb7c93bd3eac3c7fa5bed44` |
| Draft source hash | `af59792b896892fa308ccddab0915e72c565e3f696bc10feb0af2c96f6c54d6d` |
| CMS sync postcheck artifact | 12 rows draft / non-public / non-indexable / unpublished |
| Private phrase gate | 0 hits for `payment_id`, `transaction_id`, payment identifier, private route, token |
| fap-web production SHA | `37299875412c68a39e2a096f1f797800b96ff92e` |
| fap-api production REVISION | `bf937f67543dc9df656219e195e364d37c8bb63a` |
| Backend controlled publish runtime | `content-pages:publish-controlled --scope=help-service` visible |
| Backend controlled publish dry-run | `ok=true`, `target_count=12`, `would_publish_count=12`, `blocked_count=0` |
| Dry-run write safety | `writes_committed=false`, `would_create_count=0`, `no_upsert_missing=true` |
| Dry-run discoverability safety | after-preview keeps `is_indexable=false`; no sitemap/llms/footer enablement |
| Public Help draft routes | 12/12 still 404, no JSON-LD, no FAQPage, `noindex` |
| `/zh/support` and `/en/support` | 200 |
| `/zh/privacy`, `/en/privacy`, `/zh/terms`, `/en/terms` | 200 |
| `/zh/help/faq`, `/en/help/faq`, `/zh/help/contact`, `/en/help/contact` | 200 with canonical tags |
| `/zh/orders/lookup`, `/en/orders/lookup` | 200 and noindex/nofollow/noarchive/nocache |
| sitemap / llms / llms-full | 200; no Help service slug hits; no private-pattern hits |

## Publish Boundary

This PR does not authorize or execute publish. It only establishes that an exact publish authorization prompt may now be requested for the next task.

The next task must remain bounded to the controlled backend runtime:

`content-pages:publish-controlled --scope=help-service --locale=all --keys=help-unlock-failure,help-payment-refund,help-result-recovery,help-privacy-data,help-use-boundaries,help-data-deletion --execute --json`

The next task must still forbid deploy, search submission, private URL access, secret/env/cookie/token reads, payment/refund actions, payment-provider changes, Operator approval claims, and sitemap/llms/footer enablement.

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
| Operator approval claim | no |

Next recommendation: `HELP-CONTENT-DRAFT-PUBLISH-EXECUTE-01` after exact user authorization.
