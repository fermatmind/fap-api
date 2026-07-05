# Backend Authority And Free Result Audit

This file records repository-visible backend evidence only. It does not authorize runtime, CMS, commercial, sitemap, or `llms` changes.

## Strategy Baseline

The current strategy document `backend/docs/seo/fermatmind-free-assessment-global-seo-geo-strategy-2026-07-04.md` sets the six major assessment categories as MBTI, Big Five, Enneagram, RIASEC/Holland, IQ, and EQ. It also states the current SEO wedge:

- free assessment experience;
- free complete personal result view;
- public SEO pages explain tests, methods, interpretation, boundaries, and next steps;
- private result/attempt/order/payment flows remain private/noindex by default.

## Registry Evidence

Source: `backend/database/seeders/ScaleRegistrySeeder.php`.

| Scale | Registry code | Primary slug | Free/result authority evidence | Notable caveat |
| --- | --- | --- | --- | --- |
| MBTI | `MBTI` | `mbti-personality-test-16-personality-types` | `seo_i18n_json.zh.title` says `免费 MBTI 测试：16 型人格完整结果`; `content_i18n_json.zh.faq` includes 8 scale-specific FAQ entries. | Commercial fields still contain MBTI report SKU compatibility; current public landing is already aligned with free-result claim. |
| Big Five | `BIG5_OCEAN` | `big-five-personality-test-ocean-model` | `capabilities_json.paywall_mode=free_only`; view policy has no blur and no upgrade SKU. | `commercial_json.price_tier=PAID` and `report_unlock_sku=SKU_BIG5_FULL_REPORT_299` remain present. Do not rewrite money-intent copy until this authority conflict is reconciled. |
| Enneagram | `ENNEAGRAM` | `enneagram-personality-test-nine-types` | `paywall_mode=free_only`, two forms, `price_tier=FREE`, no unlock SKU. | FAQ comes from generic registry helper, not a scale-specific free-complete-result FAQ set. |
| RIASEC | `RIASEC` | `holland-career-interest-test-riasec` | `paywall_mode=free_only`, two forms, `price_tier=FREE`, no unlock SKU, public page currently says free/complete result. | FAQ comes from generic registry helper in production scan; held RIASEC parity readback must not be executed in this train. |
| IQ | `IQ_RAVEN` | `iq-test-intelligence-quotient-assessment` | Registry is public/active and `commercial_json.price_tier=FREE`. | `view_policy_json` still has `blur_others=true` and `teaser_percent=0.35`; prior sidecar evidence records missing norm table/calibrated IQ estimate. Do not claim clinical/official IQ validity. |
| EQ | `EQ_60` | `eq-test-emotional-intelligence-assessment` | `paywall_mode=free_only`, rich free sections, no unlock SKU, `price_tier=FREE`. | FAQ comes from generic registry helper, not a scale-specific free-complete-result FAQ set. |

## Result/Report Content Inventory Evidence

Source: `backend/docs/seo/result-en-parity-00-assessment-result-content-inventory.md`.

Relevant read-only findings:

- MBTI still has external content package/export and frontend clone-content authority risks for result/report copy.
- Big Five v1 has balanced compiled rows, but v2 result-page assets are Chinese-heavy and English counterparts are missing.
- Enneagram has zh-CN registry catalogs without English registry counterparts.
- RIASEC lifecycle/result assets are zh-CN-only in the scanned content inventory.
- IQ report sections have Chinese dimension labels and missing English IQ/pro payloads.
- EQ has balanced compiled packs in the scanned repo-visible assets, but future sensitive/paid result surfaces should stay fail-closed on locale fallback.

## Authority Conclusion

The hub-level SEO money-page skeleton is present for all six tests, but the free-complete-result closeout is not equally proven across all six. The next implementation work should be split by authority problem:

1. Commercial/access authority conflict: Big Five first.
2. Scale-specific FAQ authority: Enneagram, RIASEC, IQ, EQ.
3. IQ claim/norm authority: IQ before stronger money-intent copy.
4. Result interpretation inventory: needed before adding or promoting result guide pages.

This audit should not be used as permission to patch frontend fallback content or to invent new FAQ copy locally.
