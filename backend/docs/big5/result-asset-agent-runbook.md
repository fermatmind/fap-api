# Big Five Result Page V2 Asset Agent Runbook

Status: `BIG5-RESULT-ASSET-AGENT-RUNBOOK-01`

This runbook defines the backend-only operating contract for the Big Five Result Page V2 content asset agent. It does not authorize asset generation, CMS import, runtime wiring, pilot access, or production rollout.

## Authority Model

The agent must reuse the RIASEC projection, deep-slot, and fail-closed pattern:

- backend projection and selector contracts are the only content authority;
- frontend inference and frontend-authored interpretation fallback are forbidden;
- missing, invalid, pending, or unsafe slots are hidden or degraded by backend policy;
- public payloads are allowlisted and must not expose private scoring, route, QA, or editor metadata;
- stage promotion requires explicit evidence artifacts, not the presence of generated copy.

The agent is dedicated to Big Five Result Page V2 private result surfaces. It must not be replaced by or merged with a public personality profile SEO agent because private result pages include score vectors, norm availability, paid/free redaction, share/PDF/history/compare payloads, and route selector risks that public profile pages do not carry.

## Agent Responsibilities

The full program is split into eight PR-train tasks:

1. `BIG5-RESULT-ASSET-AGENT-RUNBOOK-01`: document runbook, protocol, forbidden actions, and gates.
2. `BIG5-RESULT-SOURCE-LEDGER-01`: create source ledger templates and first evidence ledger.
3. `BIG5-RESULT-ASSET-VALIDATOR-HARNESS-01`: build the agent run validator harness.
4. `BIG5-RESULT-EXISTING-ASSET-GAP-AUDIT-01`: audit existing package, selector, route, and golden-case gaps.
5. `BIG5-RESULT-SELECTOR-QA-REPAIR-01`: repair selector QA policy issues while keeping assets staging-only.
6. `BIG5-RESULT-ASSET-FACTORY-PILOT-BATCH-01`: dry-run a narrow `share_safety_registry` pilot batch.
7. `BIG5-RESULT-ROUTE-MATRIX-GOLDEN-CASE-QA-01`: validate route matrix and golden-case selector readiness.
8. `BIG5-RESULT-RENDER-PREVIEW-HANDOFF-01`: hand off backend fixtures and expected assertions to fap-web rendered preview QA.

Each task is one PR scope. A later task must not be implemented in an earlier PR.

## Subagent Roles

The orchestrator may call bounded subagents, but the output boundary remains this backend artifact protocol:

- Source Ledger Agent: tracks every claim to source, reference, limitation, permitted use, and disallowed use.
- Asset Factory Agent: emits only `fap.big5.result_page_v2.selector_asset.v0.1` candidate assets when a generation PR explicitly allows it.
- Selector Contract QA Agent: validates selector contract, slot, trigger, priority, mutual exclusion, reading mode, fallback, and public payload allowlist.
- Safety and Claim QA Agent: blocks deterministic type, diagnosis, therapy, hiring, success prediction, ability measurement, unsupported percentile, raw score, vector, and share leak claims.
- Route Matrix and Golden Case Agent: checks 3125 route rows, canonical profiles, O59, selector references, and conflict resolution.
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
- no private score, raw score, vector, percentile, or share leak.

## Forbidden Actions

All Big Five Result Page V2 asset-agent tasks forbid:

- modifying public runtime APIs unless the active PR explicitly declares a runtime contract scope;
- importing or mutating CMS data;
- setting `production_use_allowed=true`;
- enabling pilot or production flags;
- changing `BigFiveResultPageV2RuntimeWrapper` rollout behavior;
- generating frontend fallback interpretation copy;
- adding sitemap, llms, search queue, public SEO page, or public personality profile output;
- exporting private user identifiers, attempt ids, raw scores, domain vectors, facet vectors, percentiles, editor notes, QA notes, import policy, or internal metadata into public payloads;
- using BFI-2 item text, proprietary report copy, competitor result copy, or internal drafts as copy-paste sources.

## Stop Conditions

Stop immediately when:

- the working tree contains unrelated changes that cannot be isolated from the current PR scope;
- a dependency PR is not merged into `main`;
- changed files drift outside the manifest allowlist;
- local manifest checks fail;
- selector validator reports critical errors;
- safety QA reports a forbidden public payload or share payload leak;
- route matrix or golden case QA cannot prove current-count invariants;
- any artifact implies runtime, pilot, CMS import, or production readiness before the matching gate.

## First Pilot Default

The first generation-capable dry run is limited to `share_safety_registry`. O59, low-quality, and norm-unavailable combined generation remains deferred until share-safe public payload boundaries pass in isolation.
