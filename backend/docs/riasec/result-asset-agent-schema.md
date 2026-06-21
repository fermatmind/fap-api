# RIASEC Result Page Asset Agent Artifact Protocol

Status: `RIASEC-RESULT-ASSET-AGENT-RUNBOOK-01`

This protocol defines the artifact shape that later Holland/RIASEC result page asset-agent PRs must use. This document is a contract only; it does not generate assets.

## Directory Contract

Generated or audited run artifacts must live under a run-scoped directory:

```text
backend/content_assets/riasec/result_page_v2/agent_runs/<run_id>/
```

The directory is allowed only in PRs whose scope explicitly includes agent run artifacts. Docs-only PRs must not create it.

## Required Artifact Names

When a run reaches the matching stage, it must use these stable filenames:

```text
input_inventory.json
source_ledger.json
raw_draft_assets.jsonl
repaired_draft_assets.jsonl
final_assets.jsonl
validation_report.json
safety_report.json
route_matrix_report.json
golden_case_report.json
render_preview_fixture_manifest.json
go_no_go.md
```

The file may be omitted only when the declared gate is earlier than that artifact. Omitted artifacts must be listed in `go_no_go.md` with the reason.

## Shared Metadata

Every JSON artifact must include:

- `schema_version`;
- `task_id`;
- `run_id`;
- `created_at`;
- `runtime_use: "staging_only"`;
- `production_use_allowed: false`;
- `ready_for_runtime: false`;
- `ready_for_production: false`;
- `content_authority: "backend"`;
- `cms_write_performed: false`;
- `runtime_change_performed: false`;
- `frontend_fallback_allowed: false`;
- `private_payload_exported: false`.

Artifacts that reference source files must use repository-relative paths and sha256 checksums. They must not expose machine-local private paths in public reports.

## Candidate Asset Contract

The candidate asset schema for generation-capable tasks is:

```text
fap.riasec.result_page_v2.selector_asset.v0.1
```

If the current repository uses a content-slot schema instead of a selector schema for a specific RIASEC surface, the harness must record the local schema name and prove the same safety fields and public payload boundary before promotion from raw draft to repaired draft or final staging candidate.

Required governance fields include:

- `content_source`;
- `provenance`;
- `replacement_policy`;
- `forbidden_public_fields`;
- `review_status`;
- `required_evidence_level`;
- `evidence_level`;
- `safety_level`;
- `shareable`;
- `shareable_policy`;
- `fallback_policy`;
- `public_payload`;
- `internal_metadata`.

## Public Payload Boundary

`public_payload` is an allowlisted reader payload. It must not contain:

- raw scores or raw score labels;
- RIASEC dimension vectors;
- percentile, normal-curve, or norm claims when norm state is unavailable;
- `attempt_id`, user id, private URL, private path, editor notes, QA notes, import policy, selection guidance, or internal metadata;
- deterministic person type claims, fixed official type labels, or user-confirmed identity claims;
- diagnosis, treatment, therapy, hiring-screen, employment suitability, success prediction, ability measurement, admission, salary, or performance claims.

`internal_metadata` may contain backend QA details only when downstream reports keep it private and checksums prove it did not leak into public payloads.

## Run Reports

`validation_report.json` must include selector/content validator status, per-asset errors, per-registry counts, reading-mode counts, slot coverage, trigger coverage, mutual exclusion conflicts, fallback policy findings, and checksums for raw, repaired, and final files.

`safety_report.json` must include forbidden term scans, share-safe payload scans, claim-boundary findings, low-quality checks, and pass/block status.

`route_matrix_report.json` must include row count, shard count when sharded, R/I/A/S/E/C band or route coverage, selector/content reference resolution, canonical profile status, and conflict-resolution status.

`golden_case_report.json` must include golden case count, canonical profile coverage, group distribution, expected selector/content refs, and mismatch list.

`render_preview_fixture_manifest.json` must include only backend fixture references and expected assertions. It must not contain frontend fallback body copy.

## State Transitions

Allowed artifact states are:

- `draft_package`;
- `backend_dry_run`;
- `validated`;
- `approved_for_staging_review`;
- `staging_import_candidate`;
- `render_preview_ready`;
- `pilot_allowlist_candidate`;
- `production_import_gate_candidate`.

No state implies the next state. `production_import_gate_candidate` is still not production authorization.
