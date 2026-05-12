---
name: fap-api-queue-job-reliability
description: Use for fap-api queue, scheduler, async job, retry, timeout, idempotency, or background processing reliability work.
---

## Purpose
Improve background processing reliability without duplicate side effects or hidden runtime risk.

## When to use
- Use for queues, jobs, scheduled commands, retries, locks, idempotency, notifications, imports, and cache refresh workers.
- Use when async behavior can affect payments, entitlements, CMS publication, career data, or SEO generation.

## When not to use
- Do not use for synchronous controller changes unless a job boundary is affected.
- Do not use to increase retry volume without checking side effects.

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

## Standard workflow
1. Identify job inputs, side effects, retry behavior, timeout, lock, and failure state.
2. Preserve idempotency for writes, notifications, payments, imports, and publication effects.
3. Confirm migrations and route registration still work.
4. Add focused tests or operational evidence when changing runtime behavior.
5. Document rollout and rollback considerations without embedding executable deploy steps.

## Acceptance commands
```bash
cd /Users/rainie/Desktop/GitHub/fap-api/backend && php artisan route:list --no-ansi
cd /Users/rainie/Desktop/GitHub/fap-api/backend && APP_ENV=testing DB_CONNECTION=sqlite DB_DATABASE=/tmp/fap-api-skill.sqlite php artisan migrate --force
cd /Users/rainie/Desktop/GitHub/fap-api && bash backend/scripts/ci_verify_mbti.sh
cd /Users/rainie/Desktop/GitHub/fap-api && git diff --check
```

## Output contract
- Always report changed files, acceptance commands run, PR URL if a PR was created, CI status, Deploy Application or deploy/runtime status when relevant, merge commit if merged, branch cleanup status when cleanup is requested, revalidation status for security-related work, stop reason when blocked, and confirmation that no unrelated files were touched.
- Report job boundary, idempotency rule, failure behavior, checks, deploy/runtime impact, and monitoring needs.

## Stop conditions
- Stop if active Critical/High/Medium appears during Low/Informational work, required checks fail, Deploy Application or deploy/runtime status regresses where relevant, the worktree is dirty in a way that cannot be isolated, scope drift appears, product/runtime behavior is ambiguous, closure would lack source/test evidence, or production deploy/rollback is requested without explicit manual confirmation.
- Stop if retry behavior can duplicate side effects, runtime impact lacks deploy readiness, or failure handling is unclear.
