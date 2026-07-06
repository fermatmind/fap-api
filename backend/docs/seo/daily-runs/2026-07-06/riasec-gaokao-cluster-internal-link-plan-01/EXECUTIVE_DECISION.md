# RIASEC-GAOKAO-CLUSTER-INTERNAL-LINK-PLAN-01

Date: 2026-07-06
Repository: fermatmind/fap-api
Scope: generated docs only

## Decision

Final verdict: INTERNAL_LINK_PLAN_READY

This PR defines a read-only internal-link direction for the Chinese RIASEC / gaokao / major / career cluster. It does not edit live links, CMS records, article bodies, page blocks, sitemap, llms, schema, metadata, canonical, noindex, Search, or deploy state.

## Link Architecture

- Money owner stays `/zh/tests/holland-career-interest-test-riasec`.
- Scenario articles link back to the RIASEC owner and relevant explainer/boundary pages.
- Explainer and boundary pages support the owner, not replace it.
- Existing and future scenario pages link laterally only when user intent is adjacent and not cannibalizing.
- Private result/report/attempt/order/payment/share/account/history URLs remain excluded.

## Deferred Items

- No CMS internal-link write.
- No public page mutation.
- No sitemap, llms, schema, canonical, noindex, Search, GSC/GA, or deploy change.
- No use of GSC/GA as purchase truth.
