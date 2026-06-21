# Enneagram Result Page Agent Runbook

Status: `ENNEAGRAM-RESULT-AGENT-CONTROL-PACKET-01`

This runbook defines the backend-only operating contract for the Enneagram result page content asset agent. It does not authorize bulk content generation, CMS import, runtime switching, production import, registry activation, frontend fallback copy, sitemap exposure, or public SEO profile generation.

## Authority Model

- Backend Phase8 candidate contracts are the content authority.
- The current candidate baseline is `a9fd3eb474ea2ca0130d06ad2b1640305d9160ee1a74e559ad4f60bfc4db56c0`.
- The runtime registry manifest contract is `ac5bdaab3c761b0d01a56f92679aa58341110d64de0f47a1fa0062b64f76f97f`.
- Candidate payload count must remain `630`.
- Launch scope is `1R-A` through `1R-H`; `out_of_launch_scope` must contain only `1R-I` and `1R-J`.
- Frontend rendered QA consumes backend candidate payloads later. Frontend-authored fallback interpretation copy is forbidden.

## Agent Responsibilities

The program is split into separate PR scopes:

1. `ENNEAGRAM-RESULT-AGENT-CONTROL-PACKET-01`: this PR; document runbook, schema, gates, validation commands, and read-only audit command.
2. `ENNEAGRAM-RESULT-SOURCE-LEDGER-01`: source ledger and allowed-use/disallowed-use map for every claim family.
3. `ENNEAGRAM-RESULT-VALIDATOR-HARNESS-01`: executable run validator for source mapping, metadata leakage, legacy residuals, FC144 boundaries, hash checks, and payload counts.
4. `ENNEAGRAM-RESULT-PILOT-ASSET-BATCH-01`: explicitly authorized small candidate draft only after the ledger and validator harness pass.
5. `ENNEAGRAM-RESULT-CANDIDATE-EXPORT-QA-01`: Phase8B export QA against a caller-provided candidate directory.
6. `ENNEAGRAM-RESULT-INACTIVE-IMPORT-QA-01`: Phase8D2B inactive import simulation against a caller-provided candidate directory.
7. `ENNEAGRAM-RESULT-WEB-RENDERED-QA-01`: fap-web rendered QA against backend-owned fixtures and candidate payloads.
8. `ENNEAGRAM-RESULT-ACTIVATION-GATE-01`: separate approval gate only; not implied by any earlier pass.

Each task is one PR scope. A later task must not be implemented in an earlier PR.

## Required Inputs

Every run must declare:

- `run_id`;
- task id and branch id;
- target gate;
- candidate directory when Phase8B or Phase8D2B validation is in scope;
- expected candidate manifest SHA;
- expected runtime registry manifest SHA;
- expected payload count;
- allowed output paths;
- forbidden actions;
- validation commands.

Missing inputs block the run. The agent must not synthesize missing source authority, use old `/tmp` artifacts as proof, or treat visible live content as proof that a candidate package is importable.

## Forbidden Actions

- Bulk content generation in the control-packet PR.
- Production import.
- Registry activation.
- Runtime registry switch.
- `content_pack_activations` writes.
- CMS production writes.
- fap-web runtime changes.
- Frontend fallback copy.
- Sitemap, `llms.txt`, `llms-full.txt`, Search Channel, or public profile SEO exposure.
- Diagnostic, clinical, hiring, final-typing, certainty, score-comparison, or “you are this type” claims.

## Stop Conditions

Stop immediately when:

- changed files drift outside the current PR scope;
- a candidate directory is missing required artifacts;
- candidate manifest hash is not the expected baseline or an explicitly approved new baseline;
- payload count is not exactly `630`;
- source mapping, metadata leakage, legacy residual, or FC144 boundary reports contain failures;
- runtime registry hash does not match the expected contract;
- any artifact implies generation, import, activation, runtime, or production readiness before the matching gate.

## First Generation Default

The first generation-capable PR must be a small pilot batch after the source ledger and validator harness have merged. It must still write only candidate draft artifacts, keep `production_use_allowed=false`, and pass export/import dry-run gates before any rendered QA.
