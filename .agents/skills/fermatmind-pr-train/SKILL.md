---
name: fermatmind-pr-train
description: Use for one scoped FermatMind PR-train item in fap-api when Codex must verify manifest scope, dependencies, local checks, PR body, merge readiness, and ledger updates without merging prematurely.
---

## Purpose
Run exactly one fap-api PR-train item with strict scope, dependency, verification, and ledger discipline.

## When to use
- Use when the user names a PR-train item, manifest entry, train state update, or PR cleanup workflow for fap-api.
- Use when Codex must decide whether a fap-api train item can proceed, is blocked, or needs a corrective commit.

## When not to use
- Do not use for broad feature work outside the declared train item.
- Do not use to skip dependency, check, review, deployment, or ledger requirements.

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
- One PR equals one manifest scope; do not pull future train work forward.

## Standard workflow
1. Confirm the requested PR id exists in the manifest and state ledger.
2. Confirm dependencies are merged into `main`.
3. Confirm the working tree can isolate the declared scope.
4. Start from latest `main` and create or reuse only the matching PR branch.
5. Make only scoped changes and update the ledger for every state transition.
6. Run the required local acceptance commands.
7. Open or update one PR with changed files, reason, validation, deferred items, and repository rule impact.
8. Stop before merge unless checks, deploy status when relevant, reviews, and scope state are all clean.

## Acceptance commands
```bash
cd /Users/rainie/Desktop/GitHub/fap-api/backend && php artisan route:list --no-ansi
cd /Users/rainie/Desktop/GitHub/fap-api/backend && APP_ENV=testing DB_CONNECTION=sqlite DB_DATABASE=/tmp/fap-api-skill.sqlite php artisan migrate --force
cd /Users/rainie/Desktop/GitHub/fap-api && bash backend/scripts/ci_verify_mbti.sh
cd /Users/rainie/Desktop/GitHub/fap-api && git diff --check
```

## Output contract
- Report PR id, branch, changed files, validation commands, check results, ledger status, PR URL, and merge blockers.
- State whether deploy/runtime impact exists and whether Deploy Application must be checked.

## Stop conditions
- Stop on missing manifest entry, unmet dependency, dirty scope that cannot be isolated, failed local check, failed required GitHub check, review block, deploy block, or ambiguous ledger state.
