---
name: fap-api-career-content-orchestrator
description: Use for bounded fap-api Career content batch orchestration that coordinates approved inputs, dry compilation, diff and QA evidence, release-authority routing, trunk delivery, and deploy tracking without becoming a content producer, publisher, deployer, or SEO control plane.
---

# Career Content Orchestrator

Coordinate one explicit, reversible Career content batch. This skill routes work; it does not create a second content, release, deploy, or discoverability authority.

## Inputs and output

Require an explicit cohort or slug list, locale scope, source/evidence identity, intended change type, and acceptance condition. Produce a bounded handoff containing input hashes, dry-compile receipt, package diff, QA result, changed paths, and the next repository route.

Do not treat a local state file, candidate PASS, package generation, database record, HTTP 200, or completed QA as production publication.

## Sequence

1. Confirm the batch scope and preserve approved source copy outside the task.
2. Use `fap-api-career-canonical-builder` for deterministic dry compile and package diff.
3. Use `fermatmind-career-editorial-qa` only when the task creates content, changes authorized content, translates content, updates evidence, or performs SEO/editorial optimization.
4. Stop the candidate on real source, evidence, schema, locale, component, link, or hash blockers. Report the blocker; do not start an automatic rewrite cycle.
5. Route an approved Current package or Career release change to `fap-api-career-release-authority`.
6. Deliver the scoped repository change through the normal trunk contract. Use `fap-api-deploy-sre` to follow the exact SHA; the repository classifier decides deploy-skip versus the applicable deployment path.

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
- Do not create another release guard, workflow, SEO submission path, agent definition, or frontend template.
- Do not modify the approved 1046 zh-CN master or `career-en-translation` unless the task explicitly scopes that authority.

## Handoff contract

Report the exact cohort and locale, source/evidence/package hashes, dry-compile and QA results, package diff, changed paths, selected release lane, exact commit SHA, CI/deploy receipt, and any real blocker. State every content, publisher, CMS/database/cache, and discoverability mutation that was intentionally not performed.
