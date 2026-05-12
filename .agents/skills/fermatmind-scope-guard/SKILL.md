---
name: fermatmind-scope-guard
description: Use for fap-api scope control when Codex must verify changed files, detect unrelated dirty work, and stop work that drifts outside an approved PR or task boundary.
---

## Purpose
Guard fap-api work so Codex changes only the declared files and behavior for the current task.

## When to use
- Use before editing, staging, committing, pushing, or preparing a PR in fap-api.
- Use when a dirty worktree, broad diff, generated artifact, or accidental source change may affect scope.

## When not to use
- Do not use as permission to expand a task.
- Do not use to justify staging unrelated files.

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
1. Capture the declared scope in concrete paths and behavior.
2. Inspect current branch, upstream, and `git status --short`.
3. Compare changed files against the declared scope before staging.
4. Treat generated artifacts, package files, workflows, configs, routes, controllers, and services as out of scope unless explicitly named.
5. Stage only path-limited files after all checks pass.
6. Report any unrelated dirty files without touching them.

## Acceptance commands
```bash
cd /Users/rainie/Desktop/GitHub/fap-api/backend && php artisan route:list --no-ansi
cd /Users/rainie/Desktop/GitHub/fap-api/backend && APP_ENV=testing DB_CONNECTION=sqlite DB_DATABASE=/tmp/fap-api-skill.sqlite php artisan migrate --force
cd /Users/rainie/Desktop/GitHub/fap-api && bash backend/scripts/ci_verify_mbti.sh
cd /Users/rainie/Desktop/GitHub/fap-api && git diff --check
```

## Output contract
- Report declared scope, changed files, unrelated dirty files, staged files, checks run, and any stop reason.

## Stop conditions
- Stop when changed files drift outside scope, path-limited staging cannot isolate the work, or repository state is ambiguous.
