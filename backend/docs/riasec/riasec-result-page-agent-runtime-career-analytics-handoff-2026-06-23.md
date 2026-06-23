# RIASEC Result Page Agent Runtime, Career Graph, And Analytics Handoff

Date: 2026-06-23

Task package: `RIASEC Result Page Agent Strategic Work Package`

Scope: planning and handoff packet only. This document does not execute production import, enable the runtime wrapper, write CMS data, submit search URLs, change environment variables, or change public runtime behavior.

## Strategic Position

RIASEC is FermatMind's career-interest interpretation hub and safe career-graph bridge. It should help users understand their Realistic, Investigative, Artistic, Social, Enterprising, and Conventional interest structure, then move safely into major and career exploration.

RIASEC must not become a deterministic career recommendation engine. Occupation and major examples are exploration prompts only. They cannot be framed as admissions, hiring, salary, performance, success, fit, or ability predictions.

Current strategic state:

- Route, API, report-access, PDF, share, rendered preview, and leak boundaries are `PASS` for handoff planning.
- One-flagship/two-form rule is preserved:
  - canonical landing: `holland-career-interest-test-riasec`
  - supported forms: `riasec_60`, `riasec_140`
- Career bridge boundaries are `PASS` for policy planning.
- Production import, runtime enablement, CMS writes, and search submission remain `HOLD`.

## Evidence Inputs

| Evidence | Path / Reference | Use In This Packet |
| --- | --- | --- |
| Rendered preview handoff report | `backend/content_assets/riasec/result_page_v2/rendered_preview/handoff_v0_1/handoff_report.json` | Surface list and fap-web rendered preview readiness boundary. |
| Post-deploy staging/pilot evidence | `backend/docs/riasec/riasec-result-page-v2-post-deploy-staging-pilot-evidence-2026-06-22.md` | API smoke, staging agent audit, staging import dry-run, ops staging runner, all-surface pilot QA, negative guarantees. |
| Authorized snapshot dry-run | `backend/docs/riasec/riasec-result-page-v2-production-import-gate-dry-run-authorized-snapshot-2026-06-22.md` | Import gate dry-run state and import/rollout separation. |
| Production import command | `backend/app/Console/Commands/RiasecResultPageV2ProductionImportCommand.php` | Controlled command exists, but this packet does not run it. |
| Import executor | `backend/app/Services/Riasec/RiasecResultPageV2ProductionImportExecutor.php` | Hash, approval, scope, safety, and readback guard design. |
| Source ledger | `backend/content_assets/riasec/result_page_v2/source_ledger/v0_1/riasec_result_source_ledger_v0_1.json` | Construct and claim boundaries for future content and graph bridge work. |

## Packet 1: Runtime QA Handoff

### Objective

Prepare RIASEC Result Page V2 for a dedicated Runtime QA Agent handoff without enabling production runtime. Runtime QA should verify that existing pass evidence remains true when the runtime wrapper is reviewed or staged later.

### Handoff Surfaces

| Surface | QA Contract | Required Boundary |
| --- | --- | --- |
| Route | Canonical public landing remains `holland-career-interest-test-riasec`. | No parallel RIASEC route stack; no legacy 36Q local result authority. |
| Report API | Report payload remains backend-authoritative and public-safe by projection. | No raw score, raw vector, percentile, selector trace, internal source, or editor metadata in public payload. |
| Report-access API | Access decision remains fail-closed. | Locked/free redaction must not infer private full-report content. |
| PDF / private print | PDF assertions use reviewed handoff fixtures. | No private payload export, share block leak, or raw history vector. |
| Share | Public summary is share-safe only. | No full report blocks, private URLs, raw scores, or hidden selector data. |
| Private route noindex | Private result/report/history/payment-linked surfaces remain noindex/private. | No sitemap, llms, canonical promotion, JSON-LD, hreflang, or search submit from private result URLs. |
| Leak boundary | Reuse forbidden public field scan from import and staging evidence. | Fail if `attempt_id`, `user_id`, `raw_score`, `raw_scores`, `score_vector`, `dimension_vector`, `percentile`, `selector_trace`, `share_block`, `token`, or `secret` appears in public artifacts. |

### Runtime QA Inputs

Runtime QA Agent should consume:

- rendered preview fixture manifest and expected assertions;
- post-deploy staging/pilot evidence;
- all-surface pilot QA report;
- import gate dry-run report only as a negative boundary input;
- current route/report/report-access/PDF/share contract tests;
- production import command tests as evidence that import execution remains explicit and separated from rollout.

### Runtime QA Required Assertions

- `riasec_60` and `riasec_140` remain the only supported public RIASEC forms.
- RIASEC route and report access do not create a second flagship stack.
- Result, PDF, share, history, compare, locked/free, low-quality, and fallback surfaces fail closed or render only reviewed public projections.
- Runtime wrapper remains disabled unless a later PR explicitly changes runtime gate configuration.
- Production runtime remains blocked even if production import artifacts exist.
- Runtime QA must not wait for staging deploy unless a future task explicitly makes deploy part of its scope.

### Runtime QA Stop Conditions

Stop Runtime QA if any of these are observed:

- public payload exposes private score/vector/percentile/selector fields;
- RIASEC starts using local frontend fallback copy as authority;
- `riasec_60` or `riasec_140` route/report behavior diverges from the shared flagship pattern;
- private result or report URLs become indexable;
- production runtime or rollout is enabled without separate exact authorization.

## Packet 2: Career Graph Bridge Policy

### Objective

Define the safe bridge from RIASEC result interpretation into career and major exploration. The bridge may use reviewed public projection inputs only and must keep examples non-deterministic.

### Allowed Inputs

The Career Graph bridge may consume only reviewed public projection fields such as:

- public RIASEC dimension labels and order;
- public top-code / profile-shape summary;
- public confidence or caution state;
- public low-quality or norm-unavailable state;
- public method boundary copy;
- reviewed public occupation or activity examples from backend-owned assets;
- locale and form code, limited to `zh-CN`, `riasec_60`, and `riasec_140` unless separately approved.

### Forbidden Inputs

The bridge must not consume or expose:

- raw item answers;
- raw scores;
- score vectors;
- percentiles;
- selector trace;
- private attempt ID or user ID;
- payment, report-access, or order state;
- unreviewed CMS text;
- frontend fallback copy;
- hidden editor notes, source notes, QA metadata, or repair drafts.

### Allowed Output Language

Allowed bridge phrasing:

- "examples to explore";
- "work activities that may be worth comparing";
- "career areas to learn about first";
- "majors or roles that often involve similar activity patterns";
- "use this as a starting point, not a decision."

### Forbidden Output Language

Forbidden bridge phrasing:

- "best career for you";
- "guaranteed fit";
- "you should choose";
- "you will succeed";
- "hire / do not hire";
- "admissions decision";
- "salary prediction";
- "performance prediction";
- "ability measurement";
- "official Holland type determines your career";
- "low score means cannot do this."

### Occupation Example Rules

- Occupation examples are examples-only and must be labeled as exploration prompts.
- Examples should connect to activity patterns, not destiny or predicted success.
- Examples must not rank users, occupations, or majors as objectively better.
- Examples must preserve method boundary copy that RIASEC measures interests, not ability, values, mental health, salary potential, or success probability.
- Career Graph may use RIASEC to seed exploration filters, but it must not finalize recommendations without additional user-driven exploration context.

### Career Graph Bridge Readiness

Current bridge policy status: `READY_FOR_POLICY_HANDOFF`.

Current bridge runtime status: `HOLD`.

Next safe implementation task should be a policy/contract PR that adds fixture-level bridge assertions only. It should not add runtime graph recommendations, CMS writes, SEO pages, sitemap entries, or search submission.

## Packet 3: Analytics Handoff

### Objective

Prepare analytics planning for RIASEC result-page behavior without changing runtime instrumentation. This packet defines event boundaries and smoke exclusions only.

### Candidate Event Contract

| Event | Trigger | Allowed Properties | Forbidden Properties |
| --- | --- | --- | --- |
| `riasec_result_view` | User views result page shell or reviewed projection. | `scale_code`, `form_code`, `locale`, `surface`, `projection_version`, `quality_state`, `is_full_report_unlocked`. | raw scores, score vector, percentile, attempt token, user email, payment IDs, private URL. |
| `riasec_full_report_view` | User views unlocked full report. | `scale_code`, `form_code`, `locale`, `surface`, `access_state`, `projection_version`. | raw full report text, private score payload, order details, selector trace. |
| `riasec_report_module_view` | User views a result module. | `module_id`, `module_slot`, `surface`, `locale`, `quality_state`. | module source notes, hidden QA flags, raw trait/vector data. |
| `riasec_career_exploration_click` | User clicks from result into career or major exploration. | `bridge_entry`, `public_dimension_code`, `example_kind`, `locale`, `form_code`. | deterministic recommendation reason, raw score, percentile, private attempt ID. |
| `riasec_share_summary_view` | Public share summary is viewed. | `surface`, `locale`, `share_summary_version`, `redaction_state`. | private result URL, raw scores, full report modules, user identifiers. |

### Smoke Exclusion Rules

Analytics smoke must exclude:

- private raw scores and vectors;
- full payload snapshots;
- selector traces;
- CMS draft text;
- payment/order identifiers;
- private result links;
- search crawler or SEO submission behavior.

### Analytics Handoff Status

Current analytics status: `READY_FOR_CONTRACT_HANDOFF`.

Runtime instrumentation status: `HOLD`.

Next safe task should create analytics contract tests or documentation only. It must not add event emission runtime until a separate instrumentation PR is explicitly authorized.

## Packet 4: Production Import Blocker Summary

### Current Production Import State

Production import remains `HOLD` for this strategic package.

Important distinction:

- Authorized snapshot dry-run has passed for `riasec_result_page_v2_prod_approved_2026_06_22_01`.
- Controlled production import command exists and is dry-run by default.
- This packet does not execute production import.
- Import approval is not rollout approval.
- Runtime wrapper enablement remains separate from import.
- Search submission remains separate from runtime and import.

### Current Blockers

| Blocker | Status | Required Next Condition |
| --- | --- | --- |
| Production import execution | `HOLD` | Exact operator action must run the controlled command with the expected `--execute` token and exact hashes. |
| CMS write | `HOLD` | Must occur only through controlled import execution after exact authorization and command guards pass. |
| Runtime wrapper enablement | `HOLD` | Separate runtime PR and gate authorization required after import readback. |
| Pilot allowlist | `HOLD` | Separate pilot preflight and allowlist gate required after import evidence. |
| Production rollout | `HOLD` | Separate rollout approval packet required; import approval cannot be reused. |
| Search submission | `HOLD` | Separate SEO/search policy task required; private result surfaces remain noindex and out of sitemap/llms. |

### Required Evidence Before Any Future Import Execution Claim

A future production import execution packet must include:

- command invocation with `--execute`;
- exact `--confirm-execute` token;
- approved snapshot id and SHA256;
- approval evidence id and SHA256;
- authorized dry-run artifact SHA256;
- scope guard values for tenant, form, locale, and allowlist;
- rollback and kill-switch confirmation;
- post-import readback evidence;
- explicit statement that no rollout was performed.

## Go / No-Go

| Area | Decision |
| --- | --- |
| Runtime QA handoff planning | `GO` |
| Career Graph bridge policy planning | `GO` |
| Analytics handoff planning | `GO` |
| Production import execution | `NO-GO` |
| Runtime wrapper enablement | `NO-GO` |
| CMS write | `NO-GO` |
| Production rollout | `NO-GO` |
| Search submission | `NO-GO` |

## Suggested Next PRs

1. `RIASEC-RESULT-PAGE-RUNTIME-QA-HANDOFF-PACKET-01`
   - Scope: docs/artifact-only runtime QA handoff packet and assertions list.
   - Checks: `git diff --check`; JSON validation only if artifacts are added.

2. `RIASEC-CAREER-GRAPH-BRIDGE-POLICY-CONTRACT-01`
   - Scope: docs/contract-only policy for safe public projection inputs and examples-only career graph bridge.
   - Checks: `git diff --check`; focused contract tests only if added.

3. `RIASEC-RESULT-PAGE-ANALYTICS-HANDOFF-CONTRACT-01`
   - Scope: analytics event contract and privacy exclusion documentation.
   - Checks: `git diff --check`; no runtime instrumentation.

4. `RIASEC-RESULT-V2-PRODUCTION-IMPORT-EXECUTION-RUN-01`
   - Scope: only after explicit operator execution authorization and only if the controlled import command is actually run.
   - Checks: command dry-run, execute readback, no rollout evidence.

This package recommends completing the Runtime QA, Career Graph bridge policy, and Analytics handoff tasks before revisiting production import execution.
