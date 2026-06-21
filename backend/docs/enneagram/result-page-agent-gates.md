# Enneagram Result Page Agent Stage Gates

Status: `ENNEAGRAM-RESULT-AGENT-CONTROL-PACKET-01`

These gates define how Enneagram result page asset-agent work moves from readiness documentation to eventual rendered QA. They do not authorize production import, runtime switch, or activation.

## Gate 1: Control Packet

Entry: explicit PR authorization.

Pass criteria:

- runbook, artifact protocol, safety policy, and validation commands exist;
- read-only `enneagram:result-page-agent audit` command exists;
- no candidate payloads are generated;
- no runtime, CMS, frontend, production, sitemap, or `llms` files are changed;
- focused unit test, syntax checks, JSON/YAML parse, and `git diff --check` pass.

Exit state: control packet documented; generation still forbidden.

## Gate 2: Source Ledger

Entry: control packet merged.

Pass criteria:

- every source batch and claim family has source id, checksum, permitted use, limitation, and disallowed use;
- FC144 language is framed as a second lens, never a more accurate or final type;
- no generated result-page copy is created.

Exit state: sources traceable; generation still forbidden.

## Gate 3: Validator Harness

Entry: source ledger merged.

Pass criteria:

- validator can inspect a candidate/run directory without mutating runtime state;
- hash, source mapping, metadata leakage, legacy residual, forbidden claim, FC144 boundary, and payload-count checks fail closed;
- focused harness tests pass.

Exit state: validation executable; generation still forbidden.

## Gate 4: Pilot Candidate Draft

Entry: validator harness merged and explicit pilot PR authorization.

Pass criteria:

- only a small declared pilot slice is drafted;
- no bulk generation;
- all public payloads remain staging-only and private-field-safe;
- source ledger and validator reports are preserved.

Exit state: pilot draft can be reviewed, but cannot be imported or activated.

## Gate 5: Candidate Export QA

Entry: candidate draft or recovered candidate directory is provided.

Pass criteria:

- `enneagram:export-production-equivalent-candidate-payloads` passes;
- total payload count is `630`;
- source mapping failure count is `0`;
- metadata leak count is `0`;
- legacy residual count is `0`;
- FC144 boundary violation count is `0`;
- candidate manifest hash and runtime registry hash match the approved baseline or an explicitly approved new baseline.

Exit state: candidate package is render/export QA-ready, not import-ready.

## Gate 6: Inactive Import QA

Entry: export QA passed.

Pass criteria:

- `enneagram:import-inactive-candidate-release` passes;
- inactive release metadata and private storage artifact are created only in the test/output context;
- no activation row is created;
- runtime resolver remains unchanged before explicit activation;
- report states `activation_happened=false` and `production_import_happened=false`.

Exit state: candidate package is inactive-import QA-ready, not activated.

## Gate 7: fap-web Rendered QA

Entry: inactive import QA passed.

Pass criteria:

- fap-web consumes backend-owned candidate fixtures or inactive-release artifacts;
- no frontend fallback copy is added;
- result page, PDF, share, history, compare, locked/free redaction, low resonance, partial resonance, diffuse convergence, close call, scene localization, and FC144 recommendation states are rendered safely.

Exit state: rendered QA evidence exists.

## Gate 8: Activation Gate

Entry: rendered QA passed and explicit approval exists.

Pass criteria:

- rollback plan is current;
- hashes and expected contract are final;
- activation command is run only in the approved release context;
- production monitoring and rollback owner are named.

Exit state: activation may be considered. No earlier gate authorizes it.
