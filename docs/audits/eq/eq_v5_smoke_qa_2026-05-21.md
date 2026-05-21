# EQ v5 Smoke QA Report - 2026-05-21

## 1. Executive Summary

Status: **STOPPED - P0 blocker found**

The EQ-60 v5 smoke was run against staging, not production. The staging questions and submit path are reachable, and `report-access` returns the expected all-free contract. However, the staging `/report` endpoint did not return the EQ v5 report payload after repeated polling. It continued returning `generating=true` with an empty `report=[]`.

Per the execution gate, PR-EQ-V5-05 must not continue because the live smoke could not verify the v5 report payload or EQResultV5 page rendering from a real staging attempt.

## 2. Environment

| Item | Value |
| --- | --- |
| Environment type | staging |
| Frontend URL | `https://staging.fermatmind.com/en/tests/eq-test-emotional-intelligence-assessment/take` |
| Backend API URL | `https://staging-api.fermatmind.com` |
| Production used | No |
| Login required | No |
| Paid transaction used | No |
| Private API used | No |

## 3. Attempt / Session

| Item | Value |
| --- | --- |
| Anonymous ID | `codex_eq_v5_smoke_20260521211025` |
| Attempt ID | `92045de8-c53f-407a-af37-2b8c5651d95b` |
| Evidence directory | `/tmp/eq_v5_smoke_codex_eq_v5_smoke_20260521211025` |

## 4. EQ Test Page / Questions

Result: **PASS**

Staging questions endpoint:

- `ok=true`
- `scale_code=EQ_60`
- question count: `60`
- dimension codes: `SA`, `ER`, `EM`, `RM`
- zh-CN option anchors available
- en option anchors available

The smoke did not find a 50-question regression in staging questions metadata.

## 5. Submit Path

Result: **PASS**

The staging public API accepted a new anonymous EQ-60 attempt and accepted a complete 60-answer submit payload.

Start response summary:

```json
{"ok":true,"scale_code":"EQ_60","question_count":60,"attempt_id":"92045de8-c53f-407a-af37-2b8c5651d95b"}
```

Submit response summary:

```json
{"ok":true,"attempt_id":"92045de8-c53f-407a-af37-2b8c5651d95b"}
```

Note: the submit response did not include full result detail in the sampled staging response. The result/report verification therefore depended on `report-access` and `/report`.

## 6. Report Access

Result: **PASS**

`/api/v0.3/attempts/{attempt_id}/report-access` returned the expected all-free runtime contract:

```json
{
  "ok": true,
  "access_state": "ready",
  "report_state": "ready",
  "payload": {
    "locked": false,
    "variant": "full",
    "access_level": "full",
    "modules_allowed": [
      "eq_core",
      "eq_full",
      "eq_cross_insights",
      "eq_growth_plan"
    ],
    "modules_preview": [],
    "offers": [],
    "upgrade_sku": null,
    "upgrade_sku_effective": null,
    "blur_others": false,
    "free_sections": [
      "disclaimer_top",
      "quality_notice",
      "global_overview",
      "self_awareness",
      "emotion_regulation",
      "empathy",
      "relationship_management",
      "cross_quadrant_insight",
      "action_plan_14d",
      "methodology",
      "disclaimer_bottom"
    ]
  }
}
```

No staging `report-access` evidence showed `locked=true`, `blur_others=true`, paid offers, or EQ SKU exposure.

## 7. Report Payload

Result: **FAIL - P0**

Expected:

- `eq_report_mode`
- `measurement_type`
- `scores.global`
- `scores.dimensions`
- `dimension_summary`
- `quality`
- `interpretation`
- `asset_refs` or resolved assets
- `next_module.available=false`
- `next_module.status=planned`
- `methodology`

Actual after repeated polling:

```json
{
  "ok": true,
  "generating": true,
  "locked": false,
  "variant": "full",
  "access_level": "full",
  "upgrade_sku": null,
  "upgrade_sku_effective": null,
  "offers": [],
  "report_type": "array",
  "report_len": 0,
  "meta": {
    "generating": true,
    "snapshot_error": false,
    "retry_after_seconds": 3,
    "scale_code": "EQ_60",
    "scale_code_legacy": "EQ_60",
    "scale_code_v2": "EQ_EMOTIONAL_INTELLIGENCE",
    "scale_uid": "66666666-6666-4666-8666-666666666666",
    "pack_id": "EQ_60",
    "dir_version": "v1",
    "content_package_version": "v1",
    "scoring_spec_version": "eq60_spec_2026_v2",
    "report_engine_version": "v1.2"
  }
}
```

This means the staging live attempt did not expose the v5 report payload required for the EQResultV5 renderer.

## 8. Renderer / Page Sections

Result: **NOT VERIFIED IN STAGING - blocked by P0 report payload**

Because `/report` did not return an object report payload, staging page rendering could not be verified as a true live EQResultV5 path for this attempt.

Local/test evidence was used only as a control:

- `pnpm exec vitest run tests/contracts/eq-result-v5-renderer.contract.test.tsx` passed.
- `NEXT_PUBLIC_API_URL=https://staging-api.fermatmind.com pnpm exec playwright test tests/e2e/iq-eq-result-regression.spec.ts --grep "EQ uses option anchors"` passed with canonical EQ v5 fixture mocks.
- `php artisan test --filter=Eq60V5ReportContractTest` passed.

These tests prove the merged code and fixtures can render EQ v5 when the v5 payload exists, but they do not clear the staging live smoke P0.

## 9. Forbidden Terms / Fields

Staging API result:

- `report-access`: **PASS**
- `/report`: **PARTIAL PASS**
- rendered page: **NOT VERIFIED**

Observed API payload did not expose:

- `SKU_EQ_60_FULL_299`
- `EQ_60_FULL`
- `paywall=true`
- `locked=true`
- `blur_others=true`
- paid offers

Rendered page forbidden terms could not be verified because the live report payload remained empty/generating.

## 10. SJT Bridge

Result: **NOT VERIFIED IN STAGING - blocked by P0 report payload**

The live staging `/report` payload did not include `next_module`. Therefore the smoke could not verify:

- `next_module.available=false`
- `next_module.status=planned`
- no clickable SJT entry
- no MSCEIT / ability-test / certified-test claim

Canonical fixture and frontend contract tests cover this path, but staging live smoke did not.

## 11. i18n

| Locale | Status | Evidence |
| --- | --- | --- |
| zh-CN | Partial pass | Questions endpoint returns 60 EQ items and zh-CN anchors; live report payload blocked. |
| en | Partial pass | Questions endpoint returns 60 EQ items and en anchors; live report payload blocked. |

## 12. Low-Confidence Path

Result: **VERIFIED BY CANONICAL FIXTURE / CONTRACT TEST ONLY**

The low-confidence path was not forced on staging. This follows the execution boundary: do not intentionally create abnormal production-like data just to trigger low-confidence.

Evidence:

- `php artisan test --filter=Eq60V5ReportContractTest` passed and covers `low_confidence_result`.
- `pnpm exec vitest run tests/contracts/eq-result-v5-renderer.contract.test.tsx` passed and covers low-confidence rendering without strong formulation claims.

## 13. Issues

### P0

1. **Staging `/report` did not return EQ v5 payload**
   - Evidence: repeated polling returned `generating=true`, `report=[]`.
   - Impact: the smoke cannot verify live EQResultV5 rendering, v5 fields, resolved assets, SJT planned state, or page-level forbidden terms.
   - Gate result: stop and do not continue PR-EQ-V5-05.

### P1

None confirmed. Several P1 checks remain unverified because the P0 report payload blocker prevents page rendering verification.

### P2

1. Submit response summary did not include result detail in the sampled staging response.
   - This is not a blocker by itself because report generation is expected to be checked through report-access/report.

### P3

None.

## 14. Validation Commands / Evidence

Backend control:

```bash
cd /Users/rainie/Desktop/GitHub/fap-api/backend
php artisan test --filter=Eq60V5ReportContractTest
```

Result: passed, 9 tests / 273 assertions.

Frontend control:

```bash
cd /Users/rainie/Desktop/GitHub/fap-web
pnpm exec vitest run tests/contracts/eq-result-v5-renderer.contract.test.tsx
```

Result: passed, 7 tests.

Frontend e2e control:

```bash
cd /Users/rainie/Desktop/GitHub/fap-web
NEXT_PUBLIC_API_URL=https://staging-api.fermatmind.com pnpm exec playwright test tests/e2e/iq-eq-result-regression.spec.ts --grep "EQ uses option anchors"
```

Result: passed, 1 test.

## 15. Continue PR-EQ-V5-05?

Decision: **NO**

Reason: P0 smoke blocker. The staging live `/report` endpoint did not produce the required EQ v5 payload. Per the plan, PR-EQ-V5-05 must not proceed until this is understood or explicitly overridden by the user.

## 16. Resolution / Retest - PR-EQ-V5-04C

Status: **fixed in code, pending staging deployment retest**

Root cause:

- `report-access` correctly returned ready/full/all-free for EQ when a submitted attempt result existed.
- `/report` was still governed by strict snapshot delivery.
- When the report snapshot row was missing or pending, strict snapshot mode returned `generating=true` with an empty `report=[]`.
- EQ did not have a synchronous live-build fallback in that snapshot-missing path, so `report-access` readiness and `/report` deliverability could diverge.

Fix applied:

- Added an EQ-only synchronous compose fallback in `ReportGatekeeper`.
- When scale is `EQ_60`, access is full, and submitted scoring result data exists, `/report` can now build the EQ v5 payload through the existing report composer even if the snapshot is missing or still pending.
- The snapshot path remains in place. The fallback is only a delivery path for snapshot lag or absence.
- Non-EQ strict snapshot behavior is preserved by regression test coverage.

Local retest:

```bash
cd /Users/rainie/Desktop/GitHub/fap-api/backend
php artisan test --filter=Eq60V5ReportDeliveryTest
php artisan test --filter=Eq60V5ReportContractTest
php artisan test --filter=Eq60ReportPaywallTest
php artisan test --filter=Eq60SubmitQualityContractTest
php artisan test --filter=Eq60GoldenCasesTest
php artisan test --filter='BigFiveResultPageV2CoreBodyPreviewTest::test_runtime_paths_have_no_uncommitted_diff'
bash scripts/ci_verify_mbti.sh
```

Result:

- `Eq60V5ReportDeliveryTest`: passed, 3 tests / 64 assertions.
- `Eq60V5ReportContractTest`: passed, 9 tests / 273 assertions.
- `Eq60ReportPaywallTest`: passed, 4 tests / 110 assertions.
- `Eq60SubmitQualityContractTest`: passed, 1 test / 16 assertions.
- `Eq60GoldenCasesTest`: passed, 1 test / 208 assertions.
- Big Five runtime guard: passed, 1 test / 3 assertions.
- `scripts/ci_verify_mbti.sh`: passed.

Staging retest:

- Not fully re-run from this local branch because the fix has not yet been deployed to staging.
- Required post-deploy retest: repeat the same staging attempt flow against `https://staging.fermatmind.com` and `https://staging-api.fermatmind.com`.
- The P0 can be considered closed only after staging `/report` returns `generating=false` and an EQ v5 object payload containing `eq_report_mode`, `scores.dimensions`, `dimension_summary`, `assets` or `asset_refs`, `next_module.available=false`, and `methodology`.

PR-EQ-V5-05 remains blocked until staging retest confirms the fixed `/report` delivery path.
