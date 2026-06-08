# HELP-CONTENT-DRAFT-POST-PUBLISH-SMOKE-01

Decision: `BLOCKED_RUNTIME_REPAIR_REQUIRED`.

The Help service controlled publish command completed with `ok=true` for the exact 12 Help ContentPage rows. The command reported `writes_committed=true`, `target_count=12`, `would_create_count=0`, `blocked_count=0`, and no search, sitemap, llms, footer, deploy, or out-of-scope CMS write action.

The publish execution was effectively idempotent at observation time: all 12 target rows were already `published`, `is_public=true`, and `is_indexable=false` before this smoke recorded the public route state.

## Public smoke

| Check | Result |
| --- | --- |
| Apex Help service pages | 12/12 returned HTTP 200 |
| `www` behavior | 308 redirect to apex |
| Canonical | 1 canonical tag per checked page |
| Private URL pattern hits | 0 |
| Sitemap Help service slug hits | 0 |
| `llms.txt` Help service slug hits | 0 |
| `llms-full.txt` Help service slug hits | 0 |

## Blockers

1. Published Help pages render HTML robots as `index, follow` even though the backend rows are `is_indexable=false`.
2. Published Help pages did not emit `FAQPage` JSON-LD in the smoke sample.

## Boundary

This PR records the smoke result only. It does not mutate CMS rows, publish, deploy, submit search URLs, access private result/order/share/pay/payment/history URLs, read secrets/env/cookies/tokens, run payment/refund flows, change payment-provider behavior, or claim Operator approval.

## Next

Fix the frontend Help page noindex runtime first, then rerun post-publish smoke. FAQ schema runtime remains blocked until the published pages emit schema from backend-authoritative visible FAQ data.
