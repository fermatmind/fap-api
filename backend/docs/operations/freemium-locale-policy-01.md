# Freemium Locale Policy 01

Date: 2026-06-04

PR id: FREEMIUM-LOCALE-POLICY-01

Repo: fap-api

Branch: codex/freemium-locale-policy-01

Mode: backend-authoritative policy implementation. No payment provider behavior, real payment, production order, CMS content, publish, or deploy action is included.

## Policy Source

Backend authority source:

- Config: `backend/config/freemium_locale_policy.php`
- Service: `App\Services\Commerce\FreemiumLocalePolicy`
- Frontend payload key: `locale_freemium_policy`
- Schema version: `freemium_locale_policy.v1`

Frontend may render offers, checkout CTAs, and report access hints only from the backend payload. It must not infer paid/free business rules from locale strings, UI copy, region defaults, or hardcoded SKU assumptions.

## Locale / Currency / SKU / Report Access Matrix

| Locale family | Scale | Policy | Currency | Price | SKU | Free report modules | Paid/unlock modules | Order creation |
| --- | --- | --- | --- | ---: | --- | --- | --- | --- |
| `en` | MBTI | free until `2026-12-31` | n/a | n/a | n/a | `core_free`, `core_full`, `career`, `relationships` | none | blocked |
| `zh` / `zh-CN` | MBTI | free result plus CNY 1.99 unlock | CNY | 199 cents | `MBTI_REPORT_FULL_199` | `core_free` | `core_full`, `career`, `relationships` | allowed only when SKU, currency, price, scale, and attempt locale match |
| unsupported | MBTI | stop condition | n/a | n/a | n/a | none by policy | none by policy | blocked |
| not configured | non-MBTI | existing scale behavior | existing SKU truth | existing SKU truth | existing SKU truth | existing scale behavior | existing scale behavior | existing order rules |

## English Free-Until Handling

For English locale family (`en`, `en-US`, `en-GB`, etc.) the policy resolves to full/free MBTI report access through `2026-12-31`.

Backend behavior while active:

- Report paywall offers are empty.
- `upgrade_sku` and `upgrade_sku_effective` are `null`.
- CTA visibility is false because there is no backend-authorized paid offer.
- Report access is full/free for the configured MBTI modules.
- Order creation for CNY paid SKU is rejected before an order row is inserted.

This prevents English locale traffic from accidentally seeing or triggering the Chinese CNY paywall.

## Chinese CNY 1.99 Unlock Handling

For Chinese locale family (`zh`, `zh-CN`) the policy resolves to a CNY 1.99 MBTI full-report unlock.

Backend behavior:

- Free modules: `core_free`.
- Unlock modules: `core_full`, `career`, `relationships`.
- Allowed SKU: `MBTI_REPORT_FULL_199`.
- Allowed anchor SKU: `MBTI_REPORT_FULL`.
- Allowed currency: `CNY`.
- Allowed price: `199` cents.
- Order creation is allowed only after target attempt ownership has resolved and the attempt locale/scale match the policy.

The policy does not change provider behavior, create orders by itself, grant entitlements, or bypass existing ownership/payment checks.

## Mismatch Stop Conditions

The backend stops before checkout/order creation when any of these conditions match:

- `locale_missing_for_policy_scale`
- `requested_locale_conflicts_with_attempt_locale`
- `unsupported_locale_for_policy_scale`
- `english_paid_offer_before_free_until`
- `sku_not_allowed_for_locale`
- `currency_not_allowed_for_locale`
- `price_not_allowed_for_locale`

The order API returns `ok=false`, an error code beginning with `LOCALE_POLICY_`, and the `locale_freemium_policy` payload. English paid SKU requests are rejected before `OrderManager::createOrder()` inserts an order row.

## Frontend Consumption Contract

Frontend must:

- Read `locale_freemium_policy.authority=backend`.
- Treat `paywall_allowed=false` as no paid offer and no checkout CTA.
- Treat non-empty `stop_conditions` as a stop-before-checkout state.
- Use `sku`, `upgrade_sku`, `currency`, and `price_cents` only from the payload when `paywall_allowed=true`.
- Stop on locale mismatch instead of silently falling back between English and Chinese policies.

Frontend must not:

- Hardcode English as paid or Chinese as paid without the backend payload.
- Show CNY offers when `locale_freemium_policy.locale_family=en`.
- Create or submit checkout/order requests when `order_creation_allowed=false`.
- Infer report modules from UI locale or static content.

## Validation Intent

Focused tests prove:

- English MBTI SKU discovery returns no CNY paid offer when `locale=en`.
- Chinese MBTI SKU discovery returns the CNY 1.99 policy and SKU when `locale=zh-CN`.
- English MBTI report policy resolves full/free with no CNY paywall offer while the free-until window is active.
- English CNY order attempts are rejected before order creation.
