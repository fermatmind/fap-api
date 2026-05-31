# EQ-SJT 16 Validation Telemetry and QA Contract

## 1. Purpose

This document records the validation, telemetry, and QA boundary for the planned EQ-SJT 16 scenario module and the future integrated EQ report.

EQ-SJT 16 remains a scenario-based emotional judgment module. It supplements EQ-60 self-report signals; it does not replace EQ-60, does not create a true emotional ability score, and is not positioned as MSCEIT, a certified emotional intelligence assessment, a clinical assessment, or a hiring-screening instrument.

## 2. Current Release State

- EQ-60 remains the only public EQ result surface.
- EQ-SJT 16 has scorer-ready internal fixtures, but no public take entry is enabled by this PR.
- Integrated EQ report composition exists as a backend draft contract, but no user-visible integrated report is enabled by this PR.
- Stable validation claims are explicitly blocked until expert calibration, localization review, pilot data, telemetry review, and rendered QA evidence exist.

## 3. Telemetry Events

### `eq_sjt16_scored`

Internal event envelope for a scored EQ-SJT 16 result.

Allowed metadata:
- `scale_code`
- `measurement_type`
- `answer_mode`
- `score_method`
- `score_pct`
- `band`
- `quality_level`
- `quality_flags`
- `top_strategy`
- `lowest_strategy`
- `content_version`
- `rubric_version`
- `validation_status`
- `stable_validation_claim_allowed`
- `claim_boundary`

Forbidden metadata:
- raw answers
- selected response options
- response option text
- scenario stems
- user-facing interpretation copy
- paid or unlock fields

### `eq_integrated_report_composed`

Internal event envelope for the draft integrated EQ report composer.

Allowed metadata:
- `scale_code`
- `eq_report_mode`
- `measurement_type`
- `gap_count`
- `pressure_pattern_id`
- `scenario_script_count`
- `integrated_action_duration_days`
- `report_version`
- `validation_status`
- `public_runtime_enabled`
- `frontend_integrated_report_visible`
- `stable_validation_claim_allowed`
- `claim_boundary`

Forbidden metadata:
- full report prose
- raw answers
- selected options
- any ability, MSCEIT, certified, hiring, clinical, or job-performance claim

## 4. QA Gate

The internal QA gate may return `pass_for_internal_qa_only`. That status does not allow public release.

Public release remains blocked until all required evidence exists:
- expert rubric calibration
- locale bias review
- scenario item pilot statistics
- strategy score reliability review
- integrated report rendered QA

The gate must return `blocked` if:
- integrated report runtime visibility is enabled early
- frontend integrated report visibility is enabled early
- validation status overclaims beyond `draft_not_yet_validated`
- forbidden claim language is present
- claim-boundary flags are missing

## 5. Required Scenario Coverage

Validation fixtures must continue covering:
- balanced effective path
- boundary gap path
- low-effectiveness / low-quality path
- zh-CN locale
- en locale
- all-free, no locked / blur / paywall / SKU
- no raw technical tags in user-visible report contracts

## 6. Claim Boundary

The following claims remain prohibited:
- measures true emotional ability
- MSCEIT-like
- certified emotional intelligence
- hiring suitable
- clinical assessment
- predicts job performance

Safe wording:
- scenario-based emotional judgment
- likely response under emotional and relational situations
- supplements EQ-60 self-report
- draft internal validation status
- not for clinical, hiring, or high-stakes decisions

## 7. PR-EQ-SJT-05 Validation Evidence

Local validation target:
- `EqSjtValidationTelemetryContractTest`
- `EqSjt`
- `Eq60`
- `Report`
- `git diff --check`
- `git diff --cached --check`

Known external broad `Report` filter failures unrelated to EQ-SJT are recorded in `docs/codex/pr-train-state.json` as sidecar items when reproduced outside this PR scope.

## 8. Follow-up Before Public Runtime

Before EQ-SJT can become public, a future PR must provide:
- user-facing take-flow release gate
- public route authorization
- SJT attempt lifecycle and persistence
- telemetry dashboard or query contract
- expert review rubric evidence
- locale and culture bias review
- rendered QA screenshots for zh-CN and en
- production smoke QA

Until then, EQ-SJT and integrated EQ stay planned / unavailable.
