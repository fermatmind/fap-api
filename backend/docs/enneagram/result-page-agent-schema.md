# Enneagram Result Page Agent Artifact Protocol

Status: `ENNEAGRAM-RESULT-AGENT-CONTROL-PACKET-01`

This protocol defines the artifact shape that later Enneagram result page asset-agent PRs must use. This document is a contract only; it does not generate content assets.

## Directory Contract

Read-only readiness runs write to a run-scoped directory:

```text
backend/artifacts/enneagram_result_page_agent/<run_id>/
```

Generated candidate assets are forbidden in this control-packet PR. Future generation-capable PRs must use a separately authorized run directory and must not store large candidate payloads in the repository.

## Required Readiness Artifact Names

The read-only command writes:

```text
control_packet.json
readiness_inventory.json
validation_commands.json
safety_policy.json
go_no_go.md
```

## Shared Metadata

Every JSON artifact must include:

- `schema_version`;
- task name;
- `runtime_use: "staging_only"` where applicable;
- `production_use_allowed: false`;
- `ready_for_generation: false`;
- `ready_for_import: false`;
- `ready_for_runtime: false`;
- `ready_for_production: false`;
- `cms_write_performed: false`;
- `runtime_change_performed: false`;
- `activation_happened: false`;
- `bulk_content_generation_happened: false`;
- `frontend_fallback_allowed: false`.

Artifacts that reference source files must use repository-relative or redacted paths. Public reports must not expose machine-local private paths.

## Candidate Package Contract

Future candidate package validation must require:

- `candidate_manifest.json`;
- `candidate_hashes.json`;
- `rollback_plan.md`;
- `import_diff_summary.json`;
- `replacement_additive_map.json`;
- `source_mapping_report.json`;
- `legacy_residual_scan.json`;
- `fc144_boundary_report.json`;
- `phase8b_summary.json`;
- `candidate_payloads_manifest.json`;
- `candidate_payload_hashes.json`;
- `candidate_payload_source_mapping.json`;
- `candidate_payloads/`.

Required values:

- candidate manifest SHA: `a9fd3eb474ea2ca0130d06ad2b1640305d9160ee1a74e559ad4f60bfc4db56c0`;
- runtime registry manifest SHA: `ac5bdaab3c761b0d01a56f92679aa58341110d64de0f47a1fa0062b64f76f97f`;
- payload count: `630`;
- `out_of_launch_scope`: `["1R-I", "1R-J"]`;
- `production_import_happened=false`;
- `full_replacement_happened=false`;
- `activation_happened=false`.

## Public Payload Boundary

Public payloads must not contain:

- attempt id, user id, private URL, private path, editor notes, QA notes, source-selection notes, or internal metadata;
- raw scores, raw score vectors, private scoring internals, or private report state;
- diagnostic, clinical, treatment, therapy, hiring, employment suitability, success prediction, salary, or performance claims;
- final type certainty, “you are this type” framing, retyping claims, or fixed-type language;
- FC144-as-more-accurate claims;
- E105 and FC144 direct score comparison claims.

## Run Reports

Future run reports must include:

- source mapping failure counts;
- metadata leakage counts;
- legacy residual counts;
- FC144 boundary violation counts;
- payload count and payload hash checks;
- candidate manifest hash and runtime registry hash checks;
- rollback plan status;
- explicit negative guarantees.

## State Transitions

Allowed states:

- `control_packet_only`;
- `source_ledger_ready`;
- `validator_harness_ready`;
- `draft_candidate`;
- `candidate_export_qa_passed`;
- `inactive_import_qa_passed`;
- `rendered_qa_ready`;
- `activation_gate_candidate`.

No state implies the next state. `activation_gate_candidate` is still not activation approval.
