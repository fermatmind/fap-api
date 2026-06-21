# RIASEC Result Page Asset Agent Runbook

Status: `RIASEC-RESULT-ASSET-AGENT-RUNBOOK-01`

This runbook defines the backend-only operating contract for the Holland/RIASEC result page content asset agent. It authorizes documentation, protocol, and gate definition only. It does not authorize asset generation, CMS import, runtime wiring, pilot access, or production rollout.

## Authority Model

The agent must reuse the existing RIASEC backend projection, deep-slot, and fail-closed pattern:

- backend projection and slot contracts are the only authority for result interpretation payloads;
- frontend inference and frontend-authored interpretation fallback are forbidden;
- missing, invalid, pending, or unsafe slots are hidden or degraded by backend policy;
- public payloads are allowlisted and must not expose private scoring, route, QA, source, or editor metadata;
- stage promotion requires explicit evidence artifacts, not merely generated copy existing in the repository.

The agent is dedicated to private Holland/RIASEC result page assets. It must not be merged with public profile, article SEO, or CMS publication agents because result pages carry attempt-scoped score state, quality flags, share/PDF/history/compare payloads, and route selector risks that public SEO surfaces do not carry.

## Agent Responsibilities

The full program is split into eight PR-train tasks:

1. `RIASEC-RESULT-ASSET-AGENT-RUNBOOK-01`: document runbook, protocol, forbidden actions, and gates.
2. `RIASEC-RESULT-SOURCE-LEDGER-01`: create source ledger templates and first evidence ledger.
3. `RIASEC-RESULT-ASSET-VALIDATOR-HARNESS-01`: build the agent run validator harness.
4. `RIASEC-RESULT-EXISTING-ASSET-GAP-AUDIT-01`: audit existing result page assets, deep slots, route/selector, and golden-case gaps.
5. `RIASEC-RESULT-SELECTOR-QA-REPAIR-01`: repair selector/content QA policy issues while keeping assets staging-only.
6. `RIASEC-RESULT-ASSET-FACTORY-PILOT-BATCH-01`: dry-run a narrow share-safety pilot batch.
7. `RIASEC-RESULT-ROUTE-MATRIX-GOLDEN-CASE-QA-01`: validate route matrix and golden-case selector readiness.
8. `RIASEC-RESULT-RENDER-PREVIEW-HANDOFF-01`: hand off backend fixtures and expected assertions to fap-web rendered preview QA.

Each task is one PR scope. A later task must not be implemented in an earlier PR.

## Subagent Roles

The orchestrator may call bounded subagents, but the output boundary remains this backend artifact protocol:

- Source Ledger Agent: tracks every claim to source, reference, limitation, permitted use, and disallowed use.
- Asset Factory Agent: emits only RIASEC result page selector/content candidate assets when a generation PR explicitly allows it.
- Selector Contract QA Agent: validates slot, trigger, priority, mutual exclusion, reading mode, fallback, and public payload allowlist.
- Safety and Claim QA Agent: blocks deterministic person typing, diagnosis, therapy, hiring, success prediction, ability measurement, unsupported percentile, raw score, vector, and share leak claims.
- Route Matrix and Golden Case Agent: checks route rows, canonical profiles, selector references, and conflict resolution.
- Render Preview and Surface QA Agent: creates backend fixtures and expected assertions for result page, PDF, share, history, compare, and locked/free redaction previews.
- Release Guard Agent: separates draft, validation, staging import candidate, rendered preview, pilot allowlist, production import gate, and production rollout.

## Required Inputs

Every run must declare:

- `run_id`;
- task id and branch id;
- target gate: `scan`, `ledger`, `validate`, `gap_audit`, `qa_repair`, `draft`, `route_qa`, or `preview_handoff`;
- locale set, if content is in scope;
- input inventory snapshot paths;
- source ledger path, when claim evaluation is in scope;
- allowed output paths;
- forbidden actions;
- validator and QA commands to run.

When an input is missing or invalid, the agent must fail closed and write a blocked report. It must not synthesize missing source authority or use frontend fallback copy.

## Required Outputs

Runs that create artifacts must follow the protocol in `result-asset-agent-schema.md`. Docs-only tasks may omit run artifacts, but they must keep the same negative guarantees:

- `runtime_use=staging_only`;
- `production_use_allowed=false`;
- `ready_for_runtime=false`;
- `ready_for_production=false`;
- no CMS writes;
- no runtime wrapper enablement;
- no frontend fallback;
- no private score, raw score, vector, percentile, route, or share leak.

## Forbidden Actions

All RIASEC result page asset-agent tasks forbid:

- modifying public runtime APIs unless the active PR explicitly declares a runtime contract scope;
- importing or mutating CMS data;
- setting `production_use_allowed=true`;
- enabling pilot or production flags;
- changing RIASEC result runtime wrapper or projection behavior outside an explicit runtime PR;
- generating frontend fallback interpretation copy;
- adding sitemap, llms, search queue, public SEO page, or article/profile output;
- exporting private user identifiers, attempt ids, raw scores, dimension vectors, percentile fields, editor notes, QA notes, import policy, or internal metadata into public payloads;
- presenting Holland/RIASEC as a diagnosis, ability measurement, hiring screen, guaranteed career fit, success prediction, salary prediction, or official employment suitability decision;
- copying proprietary report copy, competitor result copy, or internal drafts as user-facing prose sources.

## Stop Conditions

Stop immediately when:

- the working tree contains unrelated changes that cannot be isolated from the current PR scope;
- a dependency PR is not merged into `main`;
- changed files drift outside the manifest allowlist;
- local manifest checks fail;
- selector/content validator reports critical errors;
- safety QA reports a forbidden public payload or share payload leak;
- route matrix or golden case QA cannot prove current-count invariants;
- any artifact implies runtime, pilot, CMS import, or production readiness before the matching gate.

## First Pilot Default

The first generation-capable dry run defaults to `share_safety`. `low_quality cautious copy` may replace it only if the repository scan proves it is narrower and safer. Combined generation across share safety, low quality, and route-specific scenarios remains deferred until the first narrow gate passes.
