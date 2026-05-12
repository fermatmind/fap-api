---
name: fap-api-deploy-sre
description: Use for fap-api deploy-readiness and SRE review when Codex must assess migrations, routes, queues, runtime impact, rollback notes, and Deploy Application status without executing production deploys by default.
---

## Purpose
Assess fap-api deploy and runtime readiness while keeping release execution under explicit human control.

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

## Standard workflow
1. Classify runtime impact: migration, route, queue, cache, scheduler, external provider, or config.
2. Run route, migration, MBTI, and diff checks.
3. Confirm Deploy Application status for runtime-impacting work before merge recommendation.
4. Prepare rollout and rollback notes for a human operator.
5. Stop before any live deploy command unless explicitly confirmed.

## Acceptance commands
```bash
cd /Users/rainie/Desktop/GitHub/fap-api/backend && php artisan route:list --no-ansi
cd /Users/rainie/Desktop/GitHub/fap-api/backend && APP_ENV=testing DB_CONNECTION=sqlite DB_DATABASE=/tmp/fap-api-skill.sqlite php artisan migrate --force
cd /Users/rainie/Desktop/GitHub/fap-api && bash backend/scripts/ci_verify_mbti.sh
cd /Users/rainie/Desktop/GitHub/fap-api && git diff --check
```

## Output contract
- Report runtime impact, migration result, route result, MBTI verification, Deploy Application status, rollback notes, and release blockers.

## Stop conditions
- Stop on failed migration, failed route check, failed MBTI verification, dirty scope, missing Deploy Application status, or absent manual confirmation for live deployment.
