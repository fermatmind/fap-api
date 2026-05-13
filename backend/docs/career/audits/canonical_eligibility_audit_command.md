# Career Canonical Eligibility Audit Command

AUDIT-9 wires the AUDIT-1 schema and AUDIT-2 through AUDIT-8 audit layers into a read-only artisan command:

```bash
php artisan career:audit-canonical-eligibility --scope=slugs --slugs=actuaries --locales=en --json
```

The command is an integration shell for the canonical eligibility stack. It does not apply rollout, backfill data, mutate DB state, fetch production HTML, deploy, or claim 2786 readiness.

## Options

- `--scope=all|batch|slugs`
- `--slugs=`
- `--locales=`
- `--public-resolution-plan=`
- `--entity-context=`
- `--index-state-context=`
- `--surface-context=`
- `--projection=`
- `--truth=`
- `--ledger=`
- `--json`
- `--output=`
- `--context-output=`
- `--include-surfaces`
- `--include-live-html`
- `--base-url=`

`scope=slugs` can run from explicit slugs. `scope=all` and `scope=batch` require a public-resolution plan path and report a structured `public_resolution_plan_missing` reason when it is absent.

`--entity-context`, `--index-state-context`, and `--surface-context` are consumer-only JSON artifact inputs. They satisfy entity, index, and surface context without querying/exporting production DB context or crawling live HTML. The artifacts must be produced separately by approved read-only workflows.

## Read-Only Contract

Every JSON payload includes:

```json
{
  "read_only": true,
  "writes_database": false,
  "audit_command": "career:audit-canonical-eligibility"
}
```

AUDIT-9 does not write DB rows. `--output` may write the JSON artifact to a caller-specified local file.
`--context-output` writes only the run-context requirements artifact and does not mutate DB state.

## Layer Orchestration

CMD-FIX-1 makes the command call the completed audit layer services instead of emitting one generic row for every slug and locale:

- public-resolution planner rows are loaded through `CareerPublicResolutionPlanResolver`
- entity inventory uses `CareerOccupationEntityInventoryAuditor`
- baseline/display metadata uses `CareerBaselineMetadataInventoryAuditor`
- index-state authority uses `CareerIndexStateAuthorityAuditor`
- runtime projection/truth uses `CareerRuntimeProjectionTruthEligibilityAuditor` when `--projection` and `--truth` are supplied
- SEO/GEO readiness uses `CareerSeoGeoReadinessAuditor` from plan/backend artifact fields
- surface readiness uses `CareerSurfaceReadinessAuditor` when `--surface-context`, `--include-surfaces`, or `--include-live-html` is requested

SEO/GEO readiness distinguishes absent source evidence from explicit not-ready release policy. For example, a planner row with
`Ready_For_Sitemap=false` now reports `sitemap_expected_not_ready` instead of `sitemap_missing`, while absent sitemap evidence
still reports `sitemap_missing`. The planner mapper also reads nested workbook/export fields under `raw` and `seo` so existing
SEO titles, descriptions, target queries, and structured source fields can satisfy source availability without implying that
sitemap, LLMS, or LLMS-full publication has happened.

When supplied, entity and index artifact rows bypass DB-backed entity/index auditors:

- `--entity-context=/path/to/entity_context.json` supplies `career_entity_context.v1`
- `--index-state-context=/path/to/index_state_context.json` supplies `career_index_state_context.v1`
- `--surface-context=/path/to/surface_context.json` supplies `career_surface_context.v1`

Missing or malformed artifact paths produce structured reasons such as `entity_context_file_missing`, `entity_context_json_invalid`, `index_context_file_missing`, `index_context_json_invalid`, `surface_context_file_missing`, and `surface_context_json_invalid`; the command does not silently fall back to another context source when an explicit artifact path is invalid.

## Context Handling

The command separates real blockers from missing verifier context. Missing required or optional context now uses context-specific reasons and sidecars instead of the previous all-row `validator_context_missing` pattern:

- `entity_db_context_missing`
- `index_state_context_missing`
- `runtime_projection_context_missing`
- `runtime_truth_context_missing`
- `surface_context_missing`
- `surface_context_file_missing`
- `surface_context_row_missing`
- `surface_live_html_context_missing`

Missing runtime projection/truth artifacts mark the runtime layer as `unverified`; they do not fabricate runtime pass/fail results. Missing surface artifacts or missing surface rows mark the surface layer as unverified instead of reporting one blanket `surface_context_missing` blocker when `--surface-context` is supplied. Real surface mismatches from artifact evidence, such as API canonical mismatch or noindex state, remain blocked. Optional live HTML validation requires `--include-live-html` and `--base-url`; if live verification context is absent, the surface layer is unverified and the report includes a sidecar. The command remains read-only and does not fetch live HTML by itself.

## Run Context Contract

REPAIR-RUN-CTX-1 adds top-level `context_summary` and `run_context` sections. They explain which inputs were supplied, which contexts are missing, which missing contexts block 80-readiness planning, which contexts require explicit approval, and what artifact/input is needed for a meaningful rerun.

The context layer intentionally groups row-wide context blockers. For example, `runtime_projection_context_missing` across 5,572 slug/locale rows is one missing `--projection` artifact requirement, not 5,572 separate data defects.

See `backend/docs/career/audits/audit_run_context.md` for rerun modes and approval gate templates.

## Non-Goals

AUDIT-9 does not:

- run rollout apply/backfill/rollback/quarantine
- publish new occupations
- run production DB queries
- deploy
- fetch live production HTML
- generate 80/300/800/2786 manifests
- claim full 2786 readiness

AUDIT-10 should consume this command output to build the 80-cohort readiness plan.
