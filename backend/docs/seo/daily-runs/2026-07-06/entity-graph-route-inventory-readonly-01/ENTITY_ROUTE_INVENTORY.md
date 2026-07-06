# Entity Graph Route Inventory

Date: 2026-07-06
Scope: read-only inventory for P4 entity graph route families

## Source Context

Upstream P4 evidence currently says:

- P4 status is `PLANNED_ONLY`.
- Six entity families still need complete route map, public/runtime status, and internal-link graph proof.
- Existing contracts and dry-run artifacts are useful governance, but they are not enough to prove public/runtime entity graph completion.

## Family Inventory

| Entity family | Expected entity count | Current evidence type | Current route proof status | Next PR |
| --- | ---: | --- | --- | --- |
| MBTI type pages | 16 | Personality/internal-link graph artifacts and MBTI content authority signals exist. | Not proven complete in current runtime/public route matrix. | `MBTI-TYPE-PAGES-ENTITY-GRAPH-RUNTIME-MATRIX-01` |
| Big Five dimension pages | 5 | Big Five content/import and graph contracts exist in repository evidence. | Published route map and runtime graph proof not yet closed. | `BIG-FIVE-DIMENSION-PAGES-ENTITY-GRAPH-MATRIX-01` |
| Enneagram type pages | 9 | Enneagram result/explainer and CMS command evidence exists. | Type page inventory and public route status not yet closed. | `ENNEAGRAM-TYPE-PAGES-ENTITY-GRAPH-MATRIX-01` |
| RIASEC six-type pages | 6 | RIASEC major graph authority and RIASEC cluster planning exist. | Public six-type route status, review status, and link graph not yet closed. | `RIASEC-SIX-TYPE-PAGES-ENTITY-GRAPH-MATRIX-01` |
| Career pages | To be inventoried | Career guide and career content factory evidence exists. | Complete career page graph and runtime link proof not yet closed. | `CAREER-MAJOR-PAGES-ENTITY-GRAPH-MATRIX-01` |
| Major pages | To be inventoried | RIASEC major graph authority package exists with noindex/internal authority status. | Public major route map, claim review, link graph, and indexability gate not yet closed. | `CAREER-MAJOR-PAGES-ENTITY-GRAPH-MATRIX-01` |

## Readiness Interpretation

This inventory is ready for deeper matrix PRs, but it does not make P4 complete.

To become complete, each family needs:

- canonical entity list;
- public route list or explicit no-public-route decision;
- runtime status where applicable;
- internal-link graph evidence;
- claim boundary status;
- sitemap/llms/indexability status where relevant;
- private URL exclusion.

## Guardrails

Do not advance to implementation from this PR alone. Future family matrix PRs must stay read-only unless separately authorized.

Do not include:

- private result/report/attempt/order/payment/share/account/history URLs;
- hidden schema-only graph proof;
- unsupported claim of public completeness;
- Search submission or deploy verification.
