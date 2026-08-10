---
name: fap-api-deploy-sre
description: Verify, deploy, diagnose, and assess rollback for FermatMind fap-api releases through protected GitHub exact-SHA workflows. Use for Alibaba production API or staging readiness, migrations, routes, queues, Scheduler, Redis/RDS dependencies, staging evidence, production deployment receipts, bounded smoke budgets, or read-only deployment incidents. Verify-only by default; production application and data mutations remain separately controlled.
---

# fap-api Deploy SRE

Operate backend releases through the repository control plane. Do not replace it with local Deployer or direct SSH deployment.

## Start here

1. Read repository `AGENTS.md` and inspect `git status --short --branch`.
2. Run `fermatmind-task-status` and `fermatmind-heavy-guard check` before full PHPUnit, Composer, or MBTI verification.
3. Read [control-plane.md](references/control-plane.md) for authoritative workflows and Environment boundaries.
4. Read [incident-and-rollback.md](references/incident-and-rollback.md) only when a release fails or becomes ambiguous.
5. Select one mode: `readiness`, `staging`, `production`, `verify-only`, `incident`, or `rollback-assessment`.

## Invariants

- Bind one full 40-character SHA, release ID, staging run, workflow run/attempt, and receipt.
- Use an isolated worktree from current `origin/main`; do not disturb active workspaces or user changes.
- Discover required checks from the active main ruleset and exact-SHA runs. Never hard-code historical check names in this Skill.
- Use `Deploy Application` for staging and `Deploy API Production` for production.
- Never run local `vendor/bin/dep deploy production`, copy retired Tencent releases, edit `current`, or build on production.
- Production connection values come only from the GitHub `production` Environment; staging values come only from `staging`.
- Never print secrets, passwords, private endpoints, SSH key material, or raw Environment values.
- Keep application deployment separate from migrations outside workflow policy, CMS/content publication, baseline import, database/Redis changes, DNS, restart, unlock, and process termination.
- Do not automatically retry failed production mutation.
- Treat read-only verification as evidence, not authorization to repair.

## Readiness

1. Fetch `origin`; record requested SHA and current `origin/main`.
2. Prove the candidate is contained in `main` and resolve its merged PR.
3. Query the main ruleset and require every active required check to be successful for the exact SHA.
4. Classify changed paths: code-only, runtime/config, schema, authority/content, workflow-only, or unknown.
5. Require a successful exact-SHA `Deploy Application` staging run and its parity/receipt evidence.
6. Confirm no conflicting production mutation workflow is active.
7. With explicit read-only SSH permission, compare active production revision and verify Nginx, PHP-FPM, Supervisor workers, Scheduler, RDS dependency health, and local production Redis.
8. Resolve the production workflow inputs and exact approval phrase from `.github/workflows/deploy-production.yml` at the candidate SHA.

## Staging

- Let a push to `main` trigger `Deploy Application`; manual dispatch must remain exact-SHA and workflow-policy compliant.
- Require the CI parity receipt and workflow attestation before deployment.
- Verify migrations/schema policy, API health, guest/auth smoke, scale authority, queue behavior, and staging revision.
- A staging success is eligibility evidence only; it does not authorize production.

## Production

For the standard lane, require the exact workflow-defined phrase. Current form:

```text
I explicitly approve bounded backend production deploy for exact SHA <SHA>, excluding all newer main commits, release <RELEASE_ID>.
```

Dispatch `deploy-production.yml` once with the exact candidate SHA, release ID, staging run when required, approval phrase, and declared deploy mode. Never infer or widen deploy mode.

The workflow must own:

- exact-SHA/main-containment and required-check validation;
- exact successful staging evidence;
- immutable release materialization and activation;
- allowed migration policy;
- queue reload and cache guards;
- incident receipt on failure;
- bounded public health evidence.

Do not dispatch another run while the first is pending, running, or has an unresolved promotion boundary.

## Post-deploy verification

Verify:

- active `REVISION` and release ID equal the approved target;
- Nginx and PHP-FPM are active, listen queue is zero, and 5xx is not sustained;
- all expected Supervisor workers run and Scheduler remains active;
- application uses Alibaba RDS and the approved local production Redis topology;
- `/up`, flags, public scale authority, MBTI, Big Five, Enneagram, and RIASEC representative checks pass;
- migration/schema, public-content, and runtime-authority checks required by the workflow pass;
- no unapproved CMS, database, Redis, publication, DNS, or search-discoverability mutation occurred.

Use fixed timeouts and bounded retries. Ordinary contract 4xx fails immediately; retry only the transport/status classes explicitly allowed by repository helpers.

## Failure handling

- Staging failure blocks production; repair in a scoped PR.
- Pre-activation production failure stops without retry.
- Ambiguous activation or SSH disconnect switches to read-only incident mode.
- A failed smoke does not authorize restart, unlock, rollback, migration, cache repair, or content mutation.
- Use the workflow incident receipt and immutable checkpoints before deciding the next action.

## Skill validation

```bash
python3 "$HOME/.codex/skills/.system/skill-creator/scripts/quick_validate.py" \
  .agents/skills/fap-api-deploy-sre
git diff --check
```

## Output

Report exact SHA/PR, active ruleset checks, changed-path classification, staging run and receipt, production run/release, active revision, service/worker/Scheduler/RDS/Redis health, smoke results, performed/skipped actions, rollback readiness, and residual risks. Do not print private topology or secret values.
