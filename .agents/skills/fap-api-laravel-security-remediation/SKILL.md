---
name: fap-api-laravel-security-remediation
description: Use for scoped fap-api Laravel security fixes involving middleware, validation, authorization, sessions, rate limits, request handling, or sensitive data exposure.
---

## Purpose
Repair Laravel security boundaries in fap-api without broad refactors or weakened controls.

## When to use
- Use for authorization, authentication, validation, session, CSRF, rate-limit, serialization, or sensitive response issues.
- Use when a security finding needs a minimal Laravel-side fix and targeted proof.

## When not to use
- Do not use for frontend-only security behavior.
- Do not use for speculative cleanup without a concrete security boundary.

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
1. Identify the exact route, controller, request, middleware, policy, or resource boundary.
2. Preserve existing authentication, authorization, validation, and throttling conventions.
3. Apply the smallest safe fix and add or update focused tests when the task includes code changes.
4. Verify no public response exposes sensitive internals or exploit-ready detail.
5. Run common acceptance commands and any focused Laravel test for the touched boundary.

## Acceptance commands
```bash
cd /Users/rainie/Desktop/GitHub/fap-api/backend && php artisan route:list --no-ansi
cd /Users/rainie/Desktop/GitHub/fap-api/backend && APP_ENV=testing DB_CONNECTION=sqlite DB_DATABASE=/tmp/fap-api-skill.sqlite php artisan migrate --force
cd /Users/rainie/Desktop/GitHub/fap-api && bash backend/scripts/ci_verify_mbti.sh
cd /Users/rainie/Desktop/GitHub/fap-api && git diff --check
```

## Output contract
- Always report changed files, acceptance commands run, PR URL if a PR was created, CI status, Deploy Application or deploy/runtime status when relevant, merge commit if merged, branch cleanup status when cleanup is requested, revalidation status for security-related work, stop reason when blocked, and confirmation that no unrelated files were touched.
- Report affected boundary, fix summary, evidence, tests, residual risk, and any deployment/runtime impact.

## Stop conditions
- Stop if active Critical/High/Medium appears during Low/Informational work, required checks fail, Deploy Application or deploy/runtime status regresses where relevant, the worktree is dirty in a way that cannot be isolated, scope drift appears, product/runtime behavior is ambiguous, closure would lack source/test evidence, or production deploy/rollback is requested without explicit manual confirmation.
- Stop if the fix requires changing unrelated auth flows, lowers an existing guard, lacks evidence, or creates a higher severity concern.
