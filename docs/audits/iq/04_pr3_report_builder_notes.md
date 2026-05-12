# PR3 — IQ Report Builder And Three-Dimension Result Schema

- PR id: `iq-report-builder-three-dimension-result-schema`
- Scope: IQ-only report composition, result payload schema, snapshot composition, and scoped tests.
- Out of scope:
  - paid unlock / SKU / checkout / orders / webhooks
  - frontend take/result/report pages
  - answer-key fabrication for legacy 30 demo items
  - SVG provenance or item bank import

## What changed

- Added `backend/app/Services/Report/IqReportBuilder.php`.
- Routed `IQ_RAVEN` and `IQ_INTELLIGENCE_QUOTIENT` through the IQ-specific report builder in:
  - `backend/app/Services/Report/ReportComposerRegistry.php`
  - `backend/app/Services/Report/ReportSnapshotStore.php`
- Preserved current report-access / unlock behavior:
  - no `¥1.99 / ¥5` implementation
  - no new offer / checkout logic
  - no frontend changes
- Added IQ report contract coverage:
  - `backend/tests/Unit/Services/Report/IqReportBuilderTest.php`
  - `backend/tests/Feature/V0_3/IqReportContractTest.php`

## Runtime contract

- IQ report payload now exposes:
  - `scale_code = IQ_INTELLIGENCE_QUOTIENT`
  - `attempt_id`
  - `summary.raw_score`
  - `summary.iq_estimate`
  - `summary.percentile`
  - `summary.confidence_interval`
  - `dimensions.visual_spatial_insight`
  - `dimensions.visual_spatial_pattern_reasoning`
  - `dimensions.numerical_pattern_reasoning`
  - `quality.level`
  - `quality.flags`
  - `stability.status`
- When runtime remains `blocked_unscored`, the builder keeps that state explicit instead of synthesizing fake IQ output.
- `iq_pro.pdf_payload` and `iq_pro.certificate_payload` are contract placeholders only and are marked `contract_defined_not_implemented`.

## Known external blockers

- `IQ-SIDECAR-COMMERCE-DEFERRED-001`
- `IQ-SIDECAR-NORM-TABLE-DEFERRED-001`
- frontend IQ page source remains external to this repo
