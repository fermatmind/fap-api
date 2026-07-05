# Blockers And Next Prompts

## Current PR Outcome

`SIX-TEST-HUB-COMPLETENESS-READONLY-AUDIT-01` is complete as a generated-only audit. It should not block the requested train if local checks and GitHub required checks pass.

## External Issues Recorded In This Audit

These issues were not introduced by this PR and do not affect this PR's generated-only checks.

| Issue | Evidence | Why outside current scope | Required follow-up |
| --- | --- | --- | --- |
| Big Five free-result/commercial mismatch | Registry has `paywall_mode=free_only`, but `commercial_json.price_tier=PAID` and `report_unlock_sku=SKU_BIG5_FULL_REPORT_299`. | Current PR is read-only and must not alter commercial policy or result access. | `BIG5-FREE-COMPLETE-RESULT-AUTHORITY-AUDIT-01` candidate. |
| Non-MBTI hubs use generic FAQ | Production FAQPage JSON-LD count is 4 for Big Five, Enneagram, RIASEC, IQ, and EQ. | Current PR may not create FAQ content or modify schema/runtime. | Separate backend/CMS FAQ authority PRs after owner-page map. |
| IQ `llms-full` absence | Production discoverability scan did not find `/zh/tests/iq-test-intelligence-quotient-assessment` in `llms-full.txt`. | Current PR may not modify `llms-full.txt` or discoverability runtime. | IQ claim/discoverability eligibility audit. |
| IQ norm/claim boundary unresolved | Existing result inventory and sidecar evidence record missing norm/calibrated IQ evidence. | Current PR cannot create scientific authority or norm tables. | IQ claim-readiness and result authority PR before stronger IQ money copy. |
| RIASEC FAQ parity readback held | Operator explicitly held `RIASEC-ZH-TEST-LANDING-FAQ-PARITY-READBACK-01`. | Current train explicitly excludes that task. | Reauthorize separately if needed. |

## Next Requested Train Item

Proceed to:

`MONEY-INTENT-OWNER-PAGE-MAP-READONLY-01`

Recommended scope:

- map core money-intent query families to exactly one owner page;
- record conflicts where multiple pages compete;
- keep output generated-only/read-only;
- do not change title, meta, H1, FAQ, sitemap, `llms`, schema, CMS content, or runtime.

## Candidate Follow-Up PRs After Current Read-Only Train

These are not authorized by this audit and should not be opened automatically inside this PR train:

1. `BIG5-FREE-COMPLETE-RESULT-AUTHORITY-AUDIT-01`
2. `NON-MBTI-TEST-HUB-FAQ-AUTHORITY-PLAN-01`
3. `IQ-TEST-HUB-CLAIM-AND-LLMSFULL-READINESS-01`
4. `ENNEAGRAM-TEST-HUB-FREE-RESULT-FAQ-READBACK-01`
5. `EQ-TEST-HUB-FREE-RESULT-FAQ-READBACK-01`

## Stop Boundary

Stop before any task that requires:

- CMS writes;
- production imports;
- DB migrations;
- runtime adapter/schema repair;
- sitemap or `llms` mutation;
- Search Console URL submission;
- production deploy or manual cache purge;
- editing fap-web.
