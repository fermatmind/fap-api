# Article CTA Route Gate

Date: 2026-06-05

PR train item: ARTICLE-CTA-ROUTE-GATE-01

Scope: reusable route and tracking gate for SEO article CTA targets.

## Boundary

This gate does not write article CTA copy, article body copy, title, H1, meta, FAQ, ad copy, social copy, or CMS content. It does not create CMS drafts, publish, unpublish, submit search URLs, inspect private URLs, or mutate runtime data.

## Decision

GO for article CTA targets that resolve to public canonical test or article routes.

CONDITIONAL for career hub or career page CTA targets until public canonical eligibility is recorded by the internal link plan sidecars.

NO-GO for private, tokenized, result, order, share, pay, payment, or history routes.

## Public Canonical CTA Allowlist

| Target family | Gate status | Required proof before CMS draft |
| --- | --- | --- |
| Public test landing pages | Allowed | Locale route is public, canonical, indexable, and not a take/result/order/payment/share/history route |
| Published article pages | Allowed | Article is published, canonical, indexable, in sitemap/llms after publish, and not a draft/noindex surface |
| Article index pages | Allowed for navigation only | Public index route returns 200 and is canonical for the locale |
| Career hub | Conditional | Career hub strategy and public canonical eligibility are approved |
| Tier A career pages | Conditional | CAREER-TIER-A-LINK-ELIGIBILITY records approved public canonical targets |

## Blocked CTA Targets

Always reject:

- `/result/**`
- `/orders/**`
- `/share/**`
- `/pay/**`
- `/payment/**`
- `/history/**`
- private take/session/result/order/payment/share URLs
- tokenized URLs
- user-specific URLs
- dashboard, ops, admin, preview, draft, or authenticated CMS URLs
- noindex routes unless explicitly approved for non-SEO operational navigation outside article CTA slots
- external competitor pages

Host-aware note: an external social sharing URL that contains `/share/` is not automatically a FermatMind private route, but it must not be treated as an article CTA target. CTA route checks must evaluate both host and path.

## Route Validation Contract

Before a request card becomes a CMS draft, every CTA href must pass:

| Check | Expected result |
| --- | --- |
| Locale route shape | `/{locale}/tests/{slug}` or another approved public canonical family |
| Host | Empty relative path or FermatMind public canonical host only |
| Private segment scan | No result/order/share/pay/payment/history/private/token/session/user-specific segment |
| Query scan | No token, email, order, payment, attempt, result, share, session, or user identifier |
| Indexability | Public target is indexable unless explicitly non-SEO operational |
| Canonical match | Target canonical equals the approved public route |
| Tracking params | Only safe attribution fields are allowed |

If any field is Unknown, the CTA is not eligible for SEO article publishing until reviewed.

## Tracking Expectation

The preferred event for article CTA clicks into tests is `article_to_test_click`.

Read-only fap-web evidence found:

- `components/cta/SeoTrackedCtaLink.tsx` emits `article_to_test_click` when `sourceRouteFamily === "article_detail"`.
- `tests/contracts/seo-ops-02-article-cta-attribution.contract.test.ts` preserves safe article CTA context across article, test detail, and RIASEC take flow.
- `lib/tracking/events.ts` declares `ARTICLE_TO_TEST_CLICK`.

Therefore no new runtime tracking sidecar is opened by this PR. Later performance reads must still verify that production analytics ingestion exposes this event; missing dashboard visibility is Unknown, not 0.

## Safe Attribution Fields

Allowed as article CTA attribution context:

- `source_route_family`
- `source_page_type`
- `source_slug`
- `target_test_slug`
- `target_action`
- `cta_id`
- `utm_source`
- `utm_medium`
- `utm_campaign`
- `utm_term`
- `utm_content`
- safe click ids only when the tracking whitelist accepts them

Rejected from CTA URLs and event payloads:

- email, phone, name, address, or other direct identifiers
- order numbers
- attempt ids
- payment ids
- result ids
- share ids
- session ids
- auth tokens
- arbitrary unreviewed query parameters

## CMS Draft Gate

CMS draft creation for any future SEO article requires a route-gate record with:

- source article locale and slug placeholder,
- primary CTA target family,
- primary CTA href,
- secondary CTA hrefs if present,
- route validation result,
- blocked-route scan result,
- tracking event expectation,
- safe attribution field list,
- unresolved Unknown fields,
- approval owner and date.

## Result

This gate is reusable for second and third article planning.

Do not publish a new article if its CTA target has Unknown privacy, indexability, canonical, or tracking state.

## Next Task

Proceed to ARTICLE-FAQ-SCHEMA-SMOKE-01 after this PR merges.
