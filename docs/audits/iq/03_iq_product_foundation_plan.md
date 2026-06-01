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

## H. 2026-05 IQ Train Technical Closeout

Date window: 2026-05-12 through 2026-06-01.

This section is the consolidated IQ technical summary for the recent frontend/backend PR train. It supersedes separate one-off summaries: the canonical IQ technical document for this train is this backend product foundation plan.

### H.1 Shipped PR train summary

| Phase | Key PRs | Technical outcome |
| --- | --- | --- |
| Frontend scan and train registration | fap-web #763, #764 | Audited IQ routes, API reuse, SVG renderer readiness, result/report gaps, deferred commerce boundaries, and PR split. |
| Frontend API and identity contracts | fap-web #766 | Added canonical IQ constants, typed IQ API adapters, and explicit compatibility between `IQ_INTELLIGENCE_QUOTIENT` and legacy `IQ_RAVEN`. |
| Structured question rendering | fap-web #767 | Formalized structured SVG rendering through native SVG elements and typed renderer helpers. Raw SVG string rendering remains unsupported. |
| Take-page lifecycle | fap-web #768 | Wired IQ take flow around shared attempt start, submit, result redirect, and localized route behavior. |
| Result rendering | fap-web #769, #869 | Added IQ summary and three-dimension rendering, then hardened dimension array/null handling. |
| Report shell and deferred commerce | fap-web #771, #773 | Added IQ report module shell, locked/deferred-commerce-safe states, mobile layout polish, accessible loading states, and explicit non-inferential empty/error states. |
| Generic take locale hardening | fap-web #919 | Fixed generic scale take request locale propagation, reducing cross-locale drift risk for IQ and other tests. |
| Train registration and reconciliation | fap-web #932, #937, #948, #949 | Reconciled IQ launch train state, final release ledger, and closeout. |
| Original 30-item bank and scoring foundation | fap-api #1782, #1787, #1788, #1789 | Defined the original 30-item beta bank specification, generated structured SVG item data, added provenance/redaction gates, and hardened scoring quality/stability. |
| Claim and SEO launch guard | fap-web #935 | Added IQ claim safety guards for SEO exposure. Official IQ, certified IQ, diagnostic IQ, population percentile, and equivalent Chinese claims remain blocked unless backend authority allows them. |
| CMS landing authority | fap-api #1790 | Kept IQ landing placement and metadata backend/CMS-authoritative instead of frontend editorial fallback content. |
| Production-like smoke | fap-web #936, #947 | Added launch readiness smoke and authenticated operator fixture gates. Defaults remain plan-only unless explicit operator env and mutation approval are supplied. |
| Norm and calibration authority | fap-api #1799, #1802, #1810 | Established backend-only norm authority, dry-run importer validation, and public claim eligibility gates for score/percentile exposure. |
| Paid report entitlement | fap-api #1812, fap-web #943 | Backend owns entitlement contract. Frontend renders locked/full entitlement states from API payloads without becoming payment authority. |
| CMS media authority | fap-api #1817, fap-web #944 | Backend owns IQ media metadata. Frontend renders CMS media references and avoids public static media fallbacks. |
| SEO ramp authority | fap-api #1821, fap-web #946 | SEO ramp is gated by backend `iq_ramp_authority`, claim policy, norm authority, and media authority. Sitemap, llms, llms-full, and JSON-LD exposure cannot expand from frontend-only logic. |
| Observability guardrails | fap-api #1823 | Added aggregate production guards for completion, norm miss, entitlement miss, scoring anomaly, and version drift without logging answer keys, answers, tokens, or paid private fields. |
| Backend deployment closeout | fap-api #1828 | Closed the backend release state needed by the web release ledger and production readiness checks. |

### H.2 Current technical baseline

| Area | Current position |
| --- | --- |
| Public IA | Canonical public slug remains `/tests/iq-test-intelligence-quotient-assessment` with localized `/en` and `/zh` routes. |
| Runtime identity | `IQ_INTELLIGENCE_QUOTIENT` is the formal identity; `IQ_RAVEN` remains compatibility/legacy-only. |
| Question bank | `IQ_BETA_30_ORIGINAL` is FermatMind-original structured SVG content. Third-party IQ item replication remains forbidden. |
| MyIQ.Science boundary | MyIQ.Science or similar sources require a license verification gate before any use. No question, explanation, wording, or structure may be copied into production without that gate. |
| Frontend rendering | Frontend can render structured SVG IQ stems/options, the take flow, summary metrics, dimension cards, locked/full report states, and mobile-safe loading/error states. |
| Scoring and claims | Backend remains source of truth for score, norm, percentile, confidence, claim eligibility, and report entitlement. Frontend must not infer or upgrade score claims. |
| CMS and media | IQ landing placement, editorial metadata, SEO fields, media references, and publication state remain backend/CMS-owned. Frontend must not add static editorial or public image fallbacks. |
| SEO/GEO exposure | IQ sitemap/llms/JSON-LD exposure remains gated by backend authority fields and claim safety. |
| Commerce | IQ commerce unlock remains deferred until backend commerce authority and stable unlock contract exist. |
| Production smoke | Authenticated production smoke requires operator-supplied environment values and explicit mutation approval; repo defaults do not perform live mutations. |

### H.3 Authority checklist after PR train

| Gate | Required production position | Current status |
| --- | --- | --- |
| Norm authority | Norm authority remains backend-only. Frontend and CMS must not infer formal IQ estimates or population percentiles. | Satisfied by `IQ-NORM-01` through `IQ-NORM-03`; real production norm import remains separately gated. |
| Claim policy | Public IQ estimate and percentile claims require backend claim-eligible norm authority. | Satisfied; SEO expansion remains gated. |
| Question provenance | FermatMind original IQ item bank only. MyIQ.Science remains behind license verification gate before any use. | Satisfied; no third-party IQ question replication. |
| Paid report | Entitlement is backend-defined; frontend only renders locked/full states from API payloads. | Satisfied for entitlement state rendering; checkout/unlock remains deferred. |
| CMS media | Mutable marketing/editorial media must come from CMS media metadata. | Satisfied; no public static media fallback. |
| SEO ramp | Sitemap, llms, llms-full, and JSON-LD exposure must not bypass backend claim policy, norm authority, or IQ ramp authority. | Satisfied; full expansion remains controlled by backend flags. |
| Smoke safety | Authenticated live submit/result/report checks require operator-provided env variables. | Satisfied; CI/local default remains plan-only. |
| Observability safety | Events may contain aggregate guard status only. | Satisfied; no answer keys, answer text, tokens, real user data, or paid report private fields. |

### H.4 External sidecar incident

| Incident | Status | Train impact |
| --- | --- | --- |
| `api.fermatmind.com` TLS/SNI reset on Web Node1 path | Registered as fap-web issue #955. API node health, apex `/api`, and public IQ pages remain healthy. | External cloud/network incident; not introduced by IQ PRs and does not block IQ train continuation while known-good apex `/api` path remains healthy. |

## I. Next-Phase IQ Plan

The next IQ train should not reopen the completed launch baseline. It should be split into focused PRs that preserve backend/CMS authority and expand production capability only when the controlling backend contract exists.

### I.1 Paid report deepening

| Workstream | Required authority | Notes |
| --- | --- | --- |
| Paid report content depth | fap-api report service and entitlement contract | Add deeper paid sections only from backend report payloads. Frontend renders sections but does not generate paid interpretation locally. |
| PDF/certificate delivery | backend report file service | Must remain entitlement-gated and must not expose answer keys, raw answers, tokens, or private paid fields. |
| Unlock UX | backend commerce authority plus stable frontend contract | Resume `IQ-FE-7` only after backend commerce unlock is merged. Until then, keep deferred-commerce-safe copy. |
| Refund/support hooks | backend order/support authority | Frontend may link or render support state only from backend/public API fields. |

### I.2 CMS media assets

| Workstream | Required authority | Notes |
| --- | --- | --- |
| IQ landing imagery | CMS Media Library | Upload mutable marketing/editorial images to CMS media and reference them from CMS metadata. Do not add new public static image assets for publishable IQ content. |
| Social preview variants | backend media metadata and SEO authority | OG/Twitter images should enumerate from CMS media variants, with missing media producing a safe empty/minimal state. |
| Report visual assets | backend report/media contract | Product-code-only icons are allowed; mutable report illustrations must come from CMS/media metadata or backend report payloads. |
| Asset validation | backend import/validation tooling | Enforce dimensions, MIME type, alt text, locale metadata, and publication state before SEO exposure. |

### I.3 SEO ramp

| Workstream | Required authority | Notes |
| --- | --- | --- |
| Sitemap expansion | backend `iq_ramp_authority` and `sitemap_eligible` | Add or expand IQ URLs only when backend flags, claim policy, norm authority, and media authority all pass. |
| `llms.txt` / `llms-full.txt` | backend public enumeration | Do not enumerate IQ pages from frontend local content. |
| JSON-LD exposure | backend `jsonld_eligible` and claim policy | SoftwareApplication or test schema must stay disabled if official score, percentile, diagnostic, or certification claims are not backend-approved. |
| GEO/search snippets | CMS SEO metadata | Keep wording claim-safe: original reasoning practice, beta/internal validation, raw score, nullable IQ estimate only when backend marks it eligible. |
| Monitoring | backend observability and web smoke | Track indexation, organic entry quality, norm miss, scoring anomaly, entitlement miss, and media fallback rate without logging private payloads. |
