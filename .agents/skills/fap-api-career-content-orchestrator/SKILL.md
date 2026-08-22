---
name: fap-api-career-content-orchestrator
description: Use for bounded fap-api Career content batch orchestration that coordinates approved inputs, dry compilation, diff and QA evidence, release-authority routing, trunk delivery, and deploy tracking without becoming a content producer, publisher, deployer, or SEO control plane.
---

# Career Content Orchestrator

Coordinate one explicit, reversible Career content batch. This skill routes work; it does not create a second content, release, deploy, or discoverability authority.

## Inputs and output

For Career Content Agent execution, require a machine-valid `career.content_agent.request.v1` and read [references/content-agent-execution-contract.md](references/content-agent-execution-contract.md). The request locks slug, locale, market, jurisdiction, risk, content scope, output root, research date, source policy, and execution limits before any work. Produce `career.content_agent.receipt.v1`; it is evidence only and grants no release permission.

Do not treat a local state file, candidate PASS, package generation, database record, HTTP 200, or completed QA as production publication.

## Sequence

1. Validate and hash the locked request with `scripts/validate_content_agent_contract.py`.
2. Follow the non-skippable five-gate state machine in [references/gates-risk-lifecycle.md](references/gates-risk-lifecycle.md). Only a gate PASS advances; WARN, BLOCKED, manual review, or budget exhaustion stops the chain.
3. Use the existing research producer, editorial QA, C3.6A-R evidence adapter, and canonical-builder dry compile. Never repair or rewrite a failed candidate automatically.
4. Validate the final receipt against its schema and the original request. A receipt remains a candidate handoff and cannot call release authority, publisher, deploy, CMS, database/cache, or discoverability systems.
5. Execute the locked state machine with `scripts/run_career_content_agent.py`; read [references/agent-harness.md](references/agent-harness.md) for its command and checkpoint contract.
6. If this contract itself changes in the repository, use `fap-api-deploy-sre` only to follow the pushed exact SHA and classifier-selected deploy-skip receipt. This is delivery observation, never an Agent gate or deploy authorization.

## Change routing

- Source or candidate preparation only: dry compile and evidence handoff; no repository or runtime write unless explicitly requested.
- Skill/rule/test only: focused validation, direct trunk push, exact-SHA CI, and deploy-skip.
- Approved Current package change: release-authority checks plus classifier-selected CI/deploy/publisher/readback.
- Runtime/API/compiler change: focused product tests and the normal product lane.
- URL inventory or metadata-surface change: use the existing SEO/GEO authority after the production surface change is proven; body-only work does not authorize search actions.

## Prohibited behavior

- Do not write CMS, database, cache, Current package, or production state directly.
- Do not invoke a publisher, SSH deployment, or manual workflow.
- Do not use local desktop task-runner paths as runtime authority.
- Do not require legacy readiness labels, chat acknowledgements, recurring schedules, or automatic rewrite loops.
- Do not equate QA or Skill PASS with production.
- This Skill may own exactly one controlled Agent profile at `agents/openai.yaml`. Do not create a second Career Content Agent, publishing Agent, automation Agent, release guard, workflow, SEO submission path, or frontend template.
- Do not modify the approved 1046 zh-CN master or `career-en-translation` unless the task explicitly scopes that authority.
- Do not infer locale, market, or jurisdiction from one another. Do not use observation timestamps, latency, token usage, or cost in deterministic business artifact hashes.

## Handoff contract

Report the exact cohort, locale, market, jurisdiction, risk, request/inventory/source-policy hashes, five gate states, lifecycle summary, deterministic candidate/evidence/dry-compile hashes, resource observations, and any real blocker. State `publication_authorized=false`, `current_replacement_authorized=false`, `deploy_authorized=false`, and `search_submission_authorized=false` plus every content, publisher, CMS/database/cache, and discoverability mutation intentionally not performed.
