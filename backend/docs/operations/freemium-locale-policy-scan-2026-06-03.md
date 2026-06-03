# Freemium Locale Policy Scan

Date: 2026-06-03

PR id: FREEMIUM-LOCALE-POLICY-SCAN-01

Repo: fap-api

Branch: codex/freemium-locale-policy-scan-01

Mode: read-only scan and docs-only report. No runtime code, CMS data, order/payment provider, deployment, or content asset was changed.

## 1. GO / NO-GO

NO-GO for paid ads and public commercial launch of the proposed locale policy.

GO only for documenting the current state and planning `FREEMIUM-LOCALE-POLICY-01`.

Reason: the backend can prove a general MBTI CNY 1.99 full-report SKU and entitlement unlock chain, but the repository scan did not find an authoritative locale policy that guarantees:

- English users receive full free access until 2026-12-31.
- Chinese users receive a free test, partial/free result surface, and CNY 1.99 unlock on selected result/report surfaces.
- Offer visibility, order creation, entitlement, and report access are gated by the same backend-owned locale policy.

## 2. Current Monetization Policy Found In Code

The current codebase has a general commerce and report-access model:

- `backend/database/seed_data/skus_mbti.json` defines active MBTI SKU rows including `MBTI_REPORT_FULL_199` at `price_cents=199`, `currency=CNY`, `benefit_type=report_unlock`, `benefit_code=MBTI_REPORT_FULL`, `scope=attempt`, and modules `core_full`, `career`, `relationships`.
- `backend/database/seeders/ScaleRegistrySeeder.php` initializes MBTI with `view_policy_json.free_sections=["intro","score"]`, `blur_others=true`, `teaser_percent=0.3`, and an upgrade SKU default. Its `commercial_json.price_tier` still starts as `FREE`.
- `backend/database/seeders/Pr19CommerceSeeder.php` later refreshes `commercial_json` from active SKU rows and sets `report_unlock_sku` / `upgrade_sku_anchor` for MBTI when a default SKU exists.
- `backend/app/Services/Commerce/SkuCatalog.php` resolves active SKUs by SKU, scale, org, and global fallback. It does not filter SKUs by locale.
- `backend/app/Http/Controllers/API/V0_3/CommerceController.php` validates order creation by SKU, attempt ownership, contact identity, provider, and idempotency key. It does not enforce a visible locale-specific freemium policy in the scanned create-order path.
- `backend/app/Services/Report/ReportGatekeeper.php`, `AccessResolver.php`, and `EntitlementManager.php` map locked/partial/full access using paywall mode, benefit grants, modules, and full-access state.

The current repository shape is therefore "commerce-capable" but not "locale-policy-authoritative".

## 3. English Free Policy Evidence

Status: Unknown / not proven.

Findings:

- No exact repository hit was found for `2026-12-31`, `free_until`, `full_free`, or an equivalent English free-through date in `backend` or `docs`.
- `ReportGatekeeper::shouldForceFreeOnly()` can force free-only for paywall modes and hard-coded scales such as EQ_60 and RIASEC, but it does not encode an English MBTI free-full policy through 2026-12-31.
- `AccessResolver::isForceFreeFullAccessScale()` grants full free access for BIG5_OCEAN, EQ_60, ENNEAGRAM, and RIASEC when force-free applies. MBTI is not included in that force-free full-access set.
- `loadCommercialSpecForAttempt()` reads content-pack commercial spec by attempt region/locale, but this scan did not find a proved English full-free deadline policy in the scanned repository files.
- fap-web maps checkout region from locale (`zh -> CN_MAINLAND`, non-zh -> US`) but checkout availability and SKU truth still come from backend report/CTA/offer payloads.

Conclusion: English full free until 2026-12-31 is not currently proven as a backend authority rule.

## 4. Chinese CNY 1.99 Evidence

Status: Partially proven.

Strong evidence:

- `MBTI_REPORT_FULL_199` exists as an active SKU.
- Price is `price_cents=199`.
- Currency is `CNY`.
- Benefit code is `MBTI_REPORT_FULL`.
- Scope is `attempt`.
- Included modules are `core_full`, `career`, and `relationships`.
- The frontend MBTI checkout contract expects `MBTI_REPORT_FULL_199` and sends `region="CN_MAINLAND"` when the locale is `zh`.

Gaps:

- The SKU row itself is not locale-scoped.
- The backend SKU catalog does not filter by locale.
- The order creation controller path does not prove a locale policy gate.
- The same active SKU can be resolved by scale/org unless a higher-level policy hides or blocks it.

Conclusion: CNY 1.99 MBTI full-report commerce exists, but Chinese-only enforcement is not proven.

## 5. SKU / Price / Currency / Locale Matrix

| Surface | Evidence | Price | Currency | Locale policy status |
| --- | --- | ---: | --- | --- |
| MBTI full report | `MBTI_REPORT_FULL_199` active SKU | 199 cents | CNY | No SKU-level locale gate found |
| MBTI anchor SKU | `MBTI_REPORT_FULL` anchor/deprecated, maps to effective SKU | 199 cents | CNY | Compatibility anchor, not locale policy |
| MBTI career module | `MBTI_CAREER_99` inactive | 99 cents | CNY | Inactive; not launch evidence |
| MBTI relationship module | `MBTI_RELATIONSHIP_99` inactive | 99 cents | CNY | Inactive; not launch evidence |
| English MBTI full free | No authoritative deadline rule found | n/a | n/a | Unknown / not proven |
| Chinese MBTI CNY 1.99 unlock | Active SKU + frontend CN_MAINLAND checkout region evidence | 199 cents | CNY | Partially proven, not policy-complete |

## 6. Checkout / Unlock / Report Entitlement Flow

Observed chain:

1. fap-web MBTI result shell resolves a checkout SKU from report CTA or full-report offers.
2. fap-web calls `createCheckoutOrOrder()` with attempt id, SKU, idempotency key, attribution payload, and a region derived from UI locale.
3. fap-web API client posts to `/v0.3/orders/checkout` and sends `X-Region` when present.
4. fap-api commerce controller validates SKU, ownership/contact identity, provider, idempotency, and target attempt ownership.
5. fap-api order manager and payment pipeline can grant a report unlock through `EntitlementManager::grantAttemptUnlock()`.
6. `EntitlementManager` writes active `benefit_grants`, syncs grant state, and refreshes unified access projection.
7. `ReportGatekeeper` and `AccessResolver` use benefit grants and modules to resolve locked, partial, or full access.

This proves a general commercial unlock architecture. It does not prove the final locale freemium policy.

## 7. Privacy And Order Boundary

Read-only repository evidence:

- Order creation prohibits caller-supplied `org_id`, `user_id`, and `anon_id`.
- Order creation requires a user, anon id, or contact email.
- Target attempt id is resolved through ownership checks.
- Order read uses ownership context and payment recovery token handling.
- fap-web private result/order/pay/payment/history surfaces are guarded by existing noindex/discoverability contracts in the commercial readiness scans.

No real order, result, payment, share, or history id was accessed during this scan.

## 8. P0 Blockers Before Paid Ads

1. Backend-owned locale freemium policy is missing or not discoverable as an authority contract.
2. English full-free-until-2026-12-31 is not proven.
3. Chinese-only CNY 1.99 offer enforcement is not proven.
4. SKU/offer visibility and order creation do not appear to share one explicit locale policy gate.
5. Commercial dashboard taxonomy still needs the event alignment follow-up from `ANALYTICS-COMMERCIAL-EVENTS-SCAN-01`.
6. Live private URL telemetry and paid funnel smoke remain external operational checks before paid ads.
7. No authorized production checkout/payment smoke was performed in this scan.

## 9. Follow-Up PR Scope: FREEMIUM-LOCALE-POLICY-01

Proposed PR id: `FREEMIUM-LOCALE-POLICY-01`

Proposed title: `feat(commerce): add authoritative locale freemium policy gate`

Proposed repo: `fap-api`

Proposed scope:

- Add a backend-owned freemium locale policy service/config/read model.
- Encode English full-free policy and its expiry date as explicit backend authority.
- Encode Chinese free test, partial/free result, and CNY 1.99 unlock eligibility.
- Gate offer generation, report access, and order creation through the same policy.
- Add contract tests for en/zh behavior, SKU visibility, order rejection/allowance, and locked/partial/full result states.
- Add docs for manual smoke and rollback.

Likely allowed files:

- `backend/app/Services/Commerce/**`
- `backend/app/Services/Report/**`
- `backend/config/**`
- `backend/tests/Feature/Commerce/**`
- `backend/tests/Feature/Report/**`
- `backend/docs/operations/**`
- `docs/codex/pr-train.yaml`
- `docs/codex/pr-train-state.json`

Required checks:

- Focused commerce/report policy tests.
- Existing MBTI checkout/order/report entitlement tests.
- JSON/YAML parse.
- `git diff --check`.
- Scope guard.

Dependency assumptions:

- `FREEMIUM-LOCALE-POLICY-SCAN-01` is merged.
- `ANALYTICS-COMMERCIAL-EVENTS-01` may remain parallel, but paid ads must remain blocked until both policy and measurement gates pass.

## 10. Manual Smoke Checklist

Do not run against production without separate approval.

- English MBTI result: full/free policy is visible, no paid offer shown, no order creation available, and no checkout CTA.
- Chinese MBTI free result: free/partial surface renders only approved free modules.
- Chinese MBTI locked/partial result: full-report offer shows CNY 1.99 and uses `MBTI_REPORT_FULL_199`.
- Chinese checkout: creates order only for owned attempt and allowed locale/policy state.
- English checkout attempt with paid SKU: blocked or hidden according to the policy before order creation.
- Successful sandbox payment/unlock: grants only attempt-scoped `MBTI_REPORT_FULL` and unlocks full modules.
- Failed/cancelled payment: remains locked/partial and does not grant benefit.
- Order/result/pay URLs remain noindex and absent from sitemap/llms surfaces.
- Commercial telemetry emits `view_result`, `click_unlock`, checkout/order, unlock, and purchase events without private ids in public analytics payloads.

## Validation Notes

Commands run or corrected during the scan:

- `git status --short --branch`
- `git log -n 5 --oneline`
- `php backend/artisan list | grep -i "order\|payment\|report\|sku\|publish" || true`
- Initial broad `rg ... backend app config database tests docs -S` was corrected because this repository nests Laravel code under `backend/`.
- Corrected backend scans used `rg ... backend docs -S` and focused reads of SKU, commerce, report, and entitlement files.
- fap-web was scanned read-only for checkout/unlock/result wiring evidence.

Not run:

- Broad `cd backend && php artisan test --filter=Order --filter=Payment` was not run because this docs-only scan did not need to create local order/payment fixtures, and the dual-filter command is ambiguous.

No production write, CMS mutation, order creation, payment provider call, deployment, content generation, or frontend/backend runtime code change was performed.
