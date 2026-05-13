---
name: fap-api-deploy-sre
description: Use for fap-api deploy-readiness and SRE review when Codex must assess migrations, routes, queues, runtime impact, rollback notes, and Deploy Application status without executing production deploys by default.
---

## Purpose
Assess fap-api deploy and runtime readiness while keeping release execution under explicit human control.

Use `backend/docs/ops/production-deploy-targeting-sop.md` as the repository-backed deploy target authority.

## When to use
- Use when a PR affects migrations, routes, queues, cache, scheduler, environment assumptions, or runtime operations.
- Use before advising whether a deploy-impacting PR is ready for human release review.

## When not to use
- Do not use to execute a live deploy without explicit manual confirmation in the current conversation.
- Do not use to bypass failed checks, unclear rollback state, or unresolved runtime impact.

## Hard invariants
- Do not modify unrelated files.
- Do not stage unrelated dirty files.
- Do not process Informational findings unless explicitly requested.
- Do not expose exploit-ready details in public PR titles/bodies.
- Do not merge unless required checks pass and scope is clean.
- Do not close security findings unless source/test evidence proves fixed.
- Stop if active Critical/High/Medium appears during Low/Informational work.
- Do not weaken previously fixed security boundaries.
- Required checks for fap-api are hygiene, verify-mbti-v2, and verify-mbti-legacy.
- Deploy Application must remain green for deploy or runtime-impacting PRs.
- Do not run production deploy commands unless the user explicitly confirms the exact operation.
- Do not reinterpret a PR number, branch name, or short SHA as permission to deploy the latest `origin/main`.
- Do not deploy an older target SHA after newer commits have reached `origin/main` unless the user explicitly approves a bounded exact-SHA deploy and the deploy command path is verified to pin that exact SHA.
- Do not run post-deploy production mutation commands, including content import/upsert commands, until the active production release is verified to contain the required target SHA.

## Production deploy targeting protocol
Before asking for or acting on any production deploy approval, resolve and report:

- requested target PR number, if any
- requested target commit as a full 40-character SHA
- current `origin/main` SHA
- latest merged PRs between the current production SHA and `origin/main`
- current production release SHA, using read-only SSH or another read-only production signal
- whether the deploy would include newer merged PRs beyond the requested target
- the exact deploy command shape and whether it deploys `origin/main` or an exact pinned revision

Classify the deploy decision as one of:

- `no_deploy_needed`: production already contains the requested full target SHA.
- `deploy_latest_main`: the requested target SHA equals current `origin/main`; deploying main is allowed after explicit confirmation.
- `bounded_exact_sha_required`: the requested target SHA is behind `origin/main`; stop unless the user explicitly confirms a bounded exact-SHA deploy and the deployment path can pin that SHA.
- `blocked_ambiguous_target`: the user references a PR number, branch, short SHA, or release name that does not uniquely resolve to one full target SHA.
- `blocked_tooling_gap`: the user requests a bounded exact-SHA deploy but the available deploy command can only deploy `origin/main`.

If `origin/main` contains newer merged PRs after the requested target SHA, the readiness output must name those newer PRs/commits as included or excluded. Never hide this behind "latest main".

Required confirmation phrases:

```text
I explicitly approve backend production deploy for exact SHA <40-character-sha> release <release-name>.
```

For a target behind `origin/main`, require this stronger phrase instead:

```text
I explicitly approve bounded backend production deploy for exact SHA <40-character-sha>, excluding newer main commits <short-sha/pr-list>, release <release-name>.
```

Use a separate confirmation for any production data mutation after deploy, for example:

```text
I explicitly approve production article baseline upsert for slugs <slug-list> after verifying production contains SHA <40-character-sha>.
```

The approval phrase must match the resolved target SHA. If the user changes the target after readiness, rerun readiness and request a new phrase.

## Standard workflow
1. Classify runtime impact: migration, route, queue, cache, scheduler, external provider, or config.
2. Run route, migration, MBTI, and diff checks.
3. Confirm Deploy Application status for runtime-impacting work before merge recommendation.
4. Prepare rollout and rollback notes for a human operator.
5. Resolve the exact production deploy target using the production deploy targeting protocol.
6. Stop before any live deploy command unless explicitly confirmed with the matching target-SHA phrase.
7. After deploy, verify production contains the approved SHA before running any post-deploy content import, upsert, queue, or cache mutation.

## Acceptance commands
```bash
cd /Users/rainie/Desktop/GitHub/fap-api/backend && php artisan route:list --no-ansi
cd /Users/rainie/Desktop/GitHub/fap-api/backend && APP_ENV=testing DB_CONNECTION=sqlite DB_DATABASE=/tmp/fap-api-skill.sqlite php artisan migrate --force
cd /Users/rainie/Desktop/GitHub/fap-api && bash backend/scripts/ci_verify_mbti.sh
cd /Users/rainie/Desktop/GitHub/fap-api && git diff --check
```

## Output contract
- Always report changed files, acceptance commands run, PR URL if a PR was created, CI status, Deploy Application or deploy/runtime status when relevant, merge commit if merged, branch cleanup status when cleanup is requested, revalidation status for security-related work, stop reason when blocked, and confirmation that no unrelated files were touched.
- Report runtime impact, migration result, route result, MBTI verification, Deploy Application status, rollback notes, and release blockers.
- For deploy readiness, report target SHA, origin/main SHA, production SHA, latest merged PRs considered, deploy decision classification, included/excluded commits, exact confirmation phrase required, and whether post-deploy mutation is allowed or still blocked.

## Stop conditions
- Stop if active Critical/High/Medium appears during Low/Informational work, required checks fail, Deploy Application or deploy/runtime status regresses where relevant, the worktree is dirty in a way that cannot be isolated, scope drift appears, product/runtime behavior is ambiguous, closure would lack source/test evidence, or production deploy/rollback is requested without explicit manual confirmation.
- Stop on failed migration, failed route check, failed MBTI verification, dirty scope, missing Deploy Application status, absent manual confirmation for live deployment, ambiguous deploy target, target-SHA mismatch, deploy tooling that cannot honor a requested bounded SHA, or any request to run production mutation before production is verified on the required SHA.
