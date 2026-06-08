# HELP-CONTENT-DRAFT-POST-PUBLISH-SMOKE-R2-01

Decision: `PARTIAL_GO_SCHEMA_RUNTIME_BLOCKED`.

This is a read-only post-publish smoke rerun after the frontend Help noindex runtime repair was merged and deployed. It does not mutate CMS rows, publish, deploy, submit search URLs, access private result/order/share/pay/payment/history URLs, read secrets/env/cookies/tokens, run payment/refund flows, change payment-provider behavior, or claim Operator approval.

## Public Help route smoke

| Check | Result |
| --- | --- |
| Help service pages checked | 12 |
| HTTP 200 pages | 12/12 |
| Robots noindex pages | 12/12 |
| Robots `index, follow` pages | 0/12 |
| Canonical tag failures | 0 |
| Tokenized private URL pattern hits | 0 |
| `FAQPage` JSON-LD hits | 0 |

## Machine-readable exposure smoke

| Resource | Status |
| --- | --- |
| `/sitemap.xml` | 200 |
| `/llms.txt` | 200 |
| `/llms-full.txt` | 200 |
| Help service slug hits | 0 |
| Tokenized private URL pattern hits | 0 |

## Outcome

The Help pages are published, public, reachable, and now correctly non-indexable at runtime. The original robots blocker is resolved.

The FAQ schema blocker remains open because the public smoke still found zero `FAQPage` JSON-LD hits across the 12 Help pages. This PR records the blocker only; it does not repair schema runtime.

## Next

Proceed with `HELP-SERVICE-FAQ-SCHEMA-RUNTIME-R2-01` only if the next scope is to repair or rewire backend-authoritative Help FAQ schema output. Keep it separate from publish, CMS mutation, deploy, search submission, and private URL access unless separately authorized.
