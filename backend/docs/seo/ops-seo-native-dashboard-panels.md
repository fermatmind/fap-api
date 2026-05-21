# OPS-SEO-NATIVE-DASH-03 Issue Queue and Search Channel Detail Panels

## Purpose

`/ops/seo` now exposes safer native detail panels for the two operational queues that matter for SEO observability:

- Issue Queue detail panel
- Search Channel Queue detail panel

These panels remain read-only and reuse normalized fields from the existing `seo_intel` read model.

## Read-only Filter Dimensions

Interactive Livewire filters are intentionally deferred because this PR does not modify the page class. The current PR exposes read-only filter dimensions through aggregate buckets:

- Issue Queue: `issue_type`, `severity`, `status`
- Search Channel Queue: `channel`, `approval_state`, `execution_state`

## Safe Detail Columns

Issue Queue detail panel:

- canonical path
- locale
- page entity type
- issue type
- severity
- source system
- source engine
- status
- lifecycle state
- detected at
- updated at

Search Channel Queue detail panel:

- canonical path
- locale
- page entity type
- source authority
- channel
- eligibility state
- approval state
- execution state
- indexability state
- claim boundary state
- private flow
- created at
- updated at

## Forbidden

- No payload drilldown.
- No raw JSON.
- No `metadata_json`, `attributes_json`, `reason_codes`, or `event_payload` display.
- No approve/retry/submit buttons.
- No mutation controls.
- No scheduler controls.
- No collector controls.
- No Metabase iframe/proxy/exposure.
- No external search API calls.

Next task: `OPS-SEO-NATIVE-DASH-04`
