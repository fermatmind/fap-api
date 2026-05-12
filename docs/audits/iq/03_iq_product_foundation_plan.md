# IQ Product Foundation Plan

## Scope

Current worktree initially lacked the `01/02/03` IQ audit files. This PR0-R regenerated the complete IQ audit package from the current repository source.

This document is still scan-only.

- It does not modify business logic.
- It does not modify `content_packages` production content.
- It does not modify `questions.json`.
- It does not modify `scoring_spec.json`.
- It does not modify payment, order, report gate, driver, router, or frontend code.

## Baseline From Regenerated 01/02 Audits

| Area | Current state |
|---|---|
| Public slug | `/tests/iq-test-intelligence-quotient-assessment` |
| Current seeded runtime scale code | `IQ_RAVEN` |
| V2 alias target | `IQ_INTELLIGENCE_QUOTIENT` |
| Runtime item bank | 30 items |
| Stable dimension coverage | `VSPR 20` |
| Stable `VSI` coverage | `not found` |
| Stable `NPR` coverage | `not found` |
| ODD_Q01-10 | `needs_manual_review` |
| Answer key | `not found` |
| IQ scoring mode | `pending_answer_key` |
| Frontend IQ page source | `not found` |

## A. Canonical Identity Plan

### Recommendation

Use `IQ_INTELLIGENCE_QUOTIENT` as the formal production `scale_code`.

Keep `IQ_RAVEN` only as a legacy alias.

### Why

- The public slug is already long-form and productized.
- `backend/config/scale_identity.php` already maps `IQ_RAVEN -> IQ_INTELLIGENCE_QUOTIENT`.
- Current public SEO naming no longer matches a Raven-only prototype identity.

### Cleanup target

| Field | Current | Proposed |
|---|---|---|
| `scale_code` | `IQ_RAVEN` | `IQ_INTELLIGENCE_QUOTIENT` |
| alias | runtime identity | legacy read alias only |
| `public_slug` | `iq-test-intelligence-quotient-assessment` | keep |
| `canonical_path` | split with `/test/iq-raven-demo` | unify to public slug path |
| `pack_id` | `default` | dedicated IQ production pack id |
| `dir_version` | `IQ-RAVEN-CN-v0.3.0-DEMO` | dedicated canonical IQ dir version |

### Mirror pack residue fix

The mirror pack currently keeps legacy fields internally. Production cleanup must rewrite:

- `manifest.json.scale_code`
- `meta/landing.json.scale_code`
- `meta/landing.json.slug`
- `meta/landing.json.canonical_path`
- `scoring_spec.json.scale_code`
- `version.json.dir_version`

## B. Route / SEO Contract Plan

### Formal public slug

Use one public slug only:

- `/tests/iq-test-intelligence-quotient-assessment`

Use one formal localized take path only:

- `/zh/tests/iq-test-intelligence-quotient-assessment/take`

### Legacy route treatment

`/test/iq-raven-demo` should be demoted to legacy semantics:

- legacy
- noindex
- redirect or canonical override toward the formal slug

### SEO conflict rule

Public canonical route data must come from one authority. Pack metadata must not emit a canonical path that conflicts with the scale registry slug.

## C. Item Bank Plan

### Recommendation

Create a formal new production item bank. Do not directly promote the current 30 items into production.

### Legacy policy

Freeze the current 30-item set as `legacy_demo`.

### Why

- all 30 items are still `high risk` because `correct_answer` is missing
- ODD_Q01-10 still lack stable dimension binding
- inline SVG lacks per-item hash
- provenance depends on an external prototype zip

### Required production fields

Each new production item must include:

- `item_id`
- `dimension`
- `dimension_name`
- `item_family`
- `difficulty_level`
- `correct_answer`
- `solution_rule`
- `distractor_logic`
- `assets`
- `asset_hashes`
- `generator_metadata`

### Formal dimensions

| Code | Chinese name |
|---|---|
| `VSPR` | 视觉空间模式推理 |
| `VSI` | 视觉空间洞察 |
| `NPR` | 数字规律推理 |

### Bank layering

| Bank | Role |
|---|---|
| `legacy_demo_30` | frozen audit/reference set only |
| `showcase_12` | curated public showcase beta |
| `beta_50` | larger validation bank |
| production bank | scored formal item bank |

## D. Answer Key / Scoring Plan

### Transition

Move IQ from `pending_answer_key` to `scored`.

### Binding rule

Every item must bind by `item_id`, not only by display order.

Required scoring identity fields:

- `answer_key_version`
- `norm_table_version`
- `scoring_engine_version`
- `bank_id`
- `item_id`

### Scoring outputs

Minimum aggregation contract:

- `raw_score`
- `dimension_scores`
- `iq_estimate`
- `percentile`
- `confidence_interval`
- `result_stability`

### Quality handling

The current driver already surfaces:

- `SPEEDING`
- `STRAIGHTLINING`
- `NO_VALID_ANSWERS`

Production scoring should preserve these and define outcome policy:

- score with caution
- downgrade stability
- suppress invalid results if response quality is unusable

## E. Report Schema Plan

### Unlock tiers

| Offer | Price | Intent |
|---|---|---|
| Adaptive IQ | `¥1.99` | essential interpreted result |
| IQ Pro | `¥5.00` | full narrative, PDF, certificate payload |

### Required result dimensions

The formal result contract must expose:

- `visual_spatial_insight`
- `visual_spatial_pattern_reasoning`
- `numerical_pattern_reasoning`

### Why `GenericReportBuilder` is not enough

Current generic payload only returns:

- generic summary title
- final score
- severity
- type code
- generic scores blob

That is insufficient for IQ because IQ needs:

- three-dimension structure
- percentile
- confidence interval
- result stability
- adaptive/pro unlock split
- dedicated PDF payload
- certificate payload

### Builder plan

Add `IqReportBuilder` and IQ-specific read contracts for:

- result payload
- report payload
- PDF payload
- certificate payload

## F. Commerce / Unlock Plan

### Current conflict

Current IQ seed uses `price_tier=FREE`, but the report-access stack is built around unlock states and benefit-code-driven access.

### Production SKU plan

| SKU | Price | Benefit code | Scope | Benefit type |
|---|---|---|---|---|
| `IQ_ADAPTIVE_199` | `199` cents | `IQ_ADAPTIVE_REPORT` | attempt | `report_unlock` |
| `IQ_PRO_500` | `500` cents | `IQ_PRO_REPORT` | attempt | `report_unlock` |

### Rules

- no credits
- no bundle offsets
- no membership package
- no complex discount semantics

### Report-access target states

- `locked`
- `unlocked_adaptive`
- `unlocked_pro`

## G. SVG Provenance Plan

### Current risk

Runtime SVG is static inline JSON, but the bank was generated from an external prototype zip through `backend/scripts/iq/build_iq30_questions_from_prototype.php`.

Missing today:

- `generator_version`
- `theme_version`
- `params_hash`
- `seed`
- per-item `asset_hashes`

### Legacy freeze plan

Freeze the current 30 items as `legacy_demo`:

- no silent redraw
- no silent option reorder
- no silent answer insertion without re-registration
- no production promotion without a new item schema record

### Production provenance plan

Each new item should persist:

- `generator_version`
- `theme_version`
- `template_id`
- `params_hash`
- `seed`
- `asset_hashes`

### Verify script plan

Future verification should check:

1. every production item has `item_id`
2. every production item has `correct_answer`
3. every referenced asset exists
4. every stored hash matches asset content
5. dynamic-generation metadata exists when required
6. no production item remains `legacy_demo`

## H. Frontend Gap Plan

The repo-local frontend IQ page source is still `not found`.

Observed frontend files:

- `fap-web/app/robots.ts`
- `fap-web/app/sitemap.ts`

Implication:

- this repo can define backend/content/scoring/report/commerce contracts
- this repo cannot finish end-to-end IQ page implementation alone

Required follow-up:

- find the real frontend repository
- or separately scope a new IQ frontend implementation

## I. Implementation PR Order

### PR1

| Field | Value |
|---|---|
| proposed PR train id | `iq-identity-and-metadata-cleanup` |
| title | `fix(iq): align canonical identity and public metadata` |
| scope | canonicalize `IQ_INTELLIGENCE_QUOTIENT`, keep `IQ_RAVEN` as alias, align slug/canonical_path/pack metadata |
| files likely touched | `backend/config/scale_identity.php`, `backend/database/seeders/ScaleRegistrySeeder.php`, IQ metadata files, identity tests |
| acceptance commands | `php artisan route:list`, `php artisan migrate`, `curl -s http://127.0.0.1:18002/api/v0.3/scales/lookup?slug=iq-test-intelligence-quotient-assessment`, `bash backend/scripts/ci_verify_mbti.sh` |
| rollback risk | medium |
| should not touch | questions, scoring, payment, frontend |

### PR2

| Field | Value |
|---|---|
| proposed PR train id | `iq-item-schema-answer-key-scoring-spec` |
| title | `feat(iq): add formal item schema answer key and scoring contract` |
| scope | add formal item schema, answer key binding, scoring spec contract, dimension mapping, validation |
| files likely touched | IQ schema validators, answer-key files, scoring adapter/tests |
| acceptance commands | `php artisan route:list`, `php artisan migrate`, `curl -s http://127.0.0.1:18002/api/v0.3/scales/IQ_INTELLIGENCE_QUOTIENT/questions`, `bash backend/scripts/ci_verify_mbti.sh` |
| rollback risk | medium |
| should not touch | checkout provider logic, frontend |

### PR3

| Field | Value |
|---|---|
| proposed PR train id | `iq-report-builder-three-dimension-result-schema` |
| title | `feat(iq): add IQ report builder and three-dimension result schema` |
| scope | add `IqReportBuilder`, three-dimension result contract, adaptive/pro output, PDF/certificate payloads |
| files likely touched | `backend/app/Services/Report/*`, IQ result/report tests |
| acceptance commands | `php artisan route:list`, `php artisan migrate`, `curl -s http://127.0.0.1:18002/api/v0.3/attempts/<ATTEMPT_ID>/report-access`, `bash backend/scripts/ci_verify_mbti.sh` |
| rollback risk | medium |
| should not touch | question art, payment callback providers, frontend |

### PR4

| Field | Value |
|---|---|
| proposed PR train id | `iq-commerce-unlock-199-500` |
| title | `feat(iq): add adaptive and pro IQ report unlock offers` |
| scope | define IQ SKUs, benefit codes, attempt-scoped unlock, report-access state mapping |
| files likely touched | scale registry seed data, offer resolver inputs, commerce tests |
| acceptance commands | `php artisan route:list`, `php artisan migrate`, `curl -s http://127.0.0.1:18002/api/v0.3/attempts/<ATTEMPT_ID>/report-access`, `bash backend/scripts/ci_verify_mbti.sh` |
| rollback risk | medium to high |
| should not touch | discounts, bundles, membership packages |

### PR5

| Field | Value |
|---|---|
| proposed PR train id | `iq-svg-asset-freeze-hash-verification` |
| title | `chore(iq): freeze legacy SVG assets and add provenance verification` |
| scope | freeze legacy SVG bank, add asset hashes and provenance verification tooling |
| files likely touched | IQ assets, validators, verification scripts, manifests |
| acceptance commands | `php artisan route:list`, `php artisan migrate`, `curl -s http://127.0.0.1:18002/api/v0.3/scales/IQ_INTELLIGENCE_QUOTIENT/questions`, `bash backend/scripts/ci_verify_mbti.sh` |
| rollback risk | low to medium |
| should not touch | payment/order logic, unrelated visual assets |

### PR6

| Field | Value |
|---|---|
| proposed PR train id | `iq-showcase12-beta50-item-bank-import` |
| title | `feat(iq): import showcase-12 and beta-50 item banks` |
| scope | import new formal IQ item banks and bind answer keys under the new schema |
| files likely touched | new item bank files, answer keys, scoring inputs, tests |
| acceptance commands | `php artisan route:list`, `php artisan migrate`, `curl -s http://127.0.0.1:18002/api/v0.3/scales/IQ_INTELLIGENCE_QUOTIENT/questions`, `bash backend/scripts/ci_verify_mbti.sh` |
| rollback risk | medium |
| should not touch | MBTI/BIG5 runtime flows, frontend if still missing |

## PR Train Authorization Gap

The current `docs/codex/pr-train.yaml` and `docs/codex/pr-train-state.json` do not contain the above IQ PR entries.

Before implementation starts, user authorization is still required to add those manifest/state entries.

### Exact manifest entries that would need authorization

```yaml
  - id: iq-identity-and-metadata-cleanup
    repo: fap-api
    branch: codex/iq-identity-and-metadata-cleanup
    title: "fix(iq): align canonical identity and public metadata"
  - id: iq-item-schema-answer-key-scoring-spec
    repo: fap-api
    branch: codex/iq-item-schema-answer-key-scoring-spec
    title: "feat(iq): add formal item schema answer key and scoring contract"
  - id: iq-report-builder-three-dimension-result-schema
    repo: fap-api
    branch: codex/iq-report-builder-three-dimension-result-schema
    title: "feat(iq): add IQ report builder and three-dimension result schema"
  - id: iq-commerce-unlock-199-500
    repo: fap-api
    branch: codex/iq-commerce-unlock-199-500
    title: "feat(iq): add adaptive and pro IQ report unlock offers"
  - id: iq-svg-asset-freeze-hash-verification
    repo: fap-api
    branch: codex/iq-svg-asset-freeze-hash-verification
    title: "chore(iq): freeze legacy SVG assets and add provenance verification"
  - id: iq-showcase12-beta50-item-bank-import
    repo: fap-api
    branch: codex/iq-showcase12-beta50-item-bank-import
    title: "feat(iq): import showcase-12 and beta-50 item banks"
```

### Exact state entries that would need authorization

```json
{
  "items": {
    "iq-identity-and-metadata-cleanup": { "repo": "fap-api", "status": "planned" },
    "iq-item-schema-answer-key-scoring-spec": { "repo": "fap-api", "status": "planned" },
    "iq-report-builder-three-dimension-result-schema": { "repo": "fap-api", "status": "planned" },
    "iq-commerce-unlock-199-500": { "repo": "fap-api", "status": "planned" },
    "iq-svg-asset-freeze-hash-verification": { "repo": "fap-api", "status": "planned" },
    "iq-showcase12-beta50-item-bank-import": { "repo": "fap-api", "status": "planned" }
  }
}
```

## Clear Recommendation

Do not continue IQ generator expansion first.

Repair the product foundation first.

Reason:

- identity is still split
- answer key is still missing
- ODD_Q01-10 still need manual review
- stable `VSI` and `NPR` production dimensions are not yet present
- report contract is still generic
- commerce contract is not yet IQ-specific
- SVG provenance is not production-safe
- frontend source authority is missing from this repo
