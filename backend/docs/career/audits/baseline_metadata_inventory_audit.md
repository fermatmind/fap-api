# Career Baseline Metadata Inventory Audit

AUDIT-4 adds the read-only baseline/display metadata inventory layer for the Career 2786 canonical eligibility audit train.

## Purpose

The auditor consumes normalized AUDIT-2 public-resolution plan rows and verifies that each canonical slug has enough baseline/display metadata to support future eligibility rows.

It checks:

- `content_baselines/career_jobs/career_jobs.zh-CN.json`
- `content_baselines/career_jobs/career_jobs.en.json`
- planner/workbook display metadata preserved by AUDIT-2
- existing Career batch manifests that expose canonical English titles
- canonical slug title derivation as a documented fallback

## Non-Goals

AUDIT-4 does not:

- query or mutate the database
- backfill Occupations or display assets
- audit Occupation entity inventory
- audit index state
- audit runtime projection/truth
- audit SEO/GEO or live surfaces
- run rollout, apply, rollback, quarantine, deployment, or production validation

## Input Contract

The primary input is `CareerPublicResolutionPlan`, produced by AUDIT-2. Each row contributes:

- `canonical_slug`
- optional `batch_id` as `source_scope`
- raw planner metadata preserved by AUDIT-2

The baseline auditor loads JSON sources from explicit paths supplied by the caller, or from the repo default baseline and manifest paths.

REPAIR-BASELINE-1 adds a read-only fallback for planner/workbook display metadata. If a zh baseline JSON row is absent but the plan row carries `title_zh` plus zh SEO title and description fields under the top-level row, `raw`, or `seo`, the auditor can satisfy the baseline display metadata source from that planner row. This removes false `zh_baseline_missing` and `required_display_field_missing` blockers for workbook-derived rows without generating new content or mutating DB state.

REPAIR-TITLE-1 adds a read-only English title source for planner/workbook rows. If no English baseline row or batch manifest title exists, the auditor accepts an authorized plan-row `title_en` / workbook `EN_Title` value before falling back to canonical-slug title derivation. This removes false `en_title_derivation_required` warnings where workbook English titles are already present.

The default required display metadata fields are:

- `title`
- `subtitle`
- `excerpt`
- `seo_meta`

Full article/body content such as `body_md` is not treated as required metadata in this layer. Missing body/content material remains a content remediation concern, not a DB/backfill authorization.

Supported source row envelopes:

- `jobs`
- `members`
- `occupations`
- `rows`
- `assets`
- `workbook.rows`
- slug-keyed objects

## Row Output

Each `CareerBaselineMetadataInventoryRow` serializes as:

- `canonical_slug`
- `zh_baseline_exists`
- `title_zh`
- `title_en`
- `title_en_source`
- `baseline_status`
- `missing_display_fields`
- `source_scope`
- `evidence`
- `issues`

`title_en_source` is one of:

- `en_baseline`
- `batch_manifest`
- `planner_workbook`
- `canonical_slug_derived`
- `missing`

## Issue Reasons

AUDIT-4 emits these structured reasons:

- `zh_baseline_missing`
- `en_title_missing`
- `en_title_derivation_required`
- `required_display_field_missing`
- `baseline_json_invalid`
- `baseline_source_missing`

`en_title_derivation_required` is a warning-level continuation signal. Missing zh baselines, invalid JSON, missing source files, and required display-field gaps block the baseline layer.

## AUDIT-1 Layer Status

Rows expose an AUDIT-1-compatible `CareerCanonicalEligibilityLayerStatus` with:

- `layer=baseline`
- `status=pass|warning|blocked`
- `source=career_baselines`
- `reasons` copied from baseline inventory issue reasons
- `evidence` containing slug, zh baseline availability/source, and English title source

## Consumption by AUDIT-5+

Future audit layers should consume this result as a baseline-layer input only. AUDIT-5 must not infer index state from baseline metadata. AUDIT-6 must not infer runtime publish state from baseline metadata. AUDIT-9 will compose this result into the full command.

## Readiness Warning

AUDIT-4 is schema and read-only inventory logic only. It does not claim 2786 readiness and does not prove publication eligibility on its own.
