---
name: fermatmind-codex-security-revalidate
description: Use for fap-api security revalidation when Codex must verify reported findings against source and tests, preserve severity boundaries, and avoid closing issues without evidence.
---

## Purpose
Revalidate fap-api security findings with source-backed and test-backed evidence before proposing remediation or closure.

## When to use
- Use when the user asks to recheck, verify, triage, or close a security finding in fap-api.
- Use when severity, exploitability, tenant impact, auth impact, payment impact, or CMS write impact is in question.

## When not to use
- Do not use for non-security refactors or routine style fixes.
- Do not use to produce exploit walkthroughs or public issue text with attack steps.

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
1. Identify the finding, claimed severity, affected routes, data boundary, and expected control.
2. Read only the relevant source, policy, middleware, request validation, model, migration, and tests.
3. Determine whether the finding is reproducible, already fixed, not applicable, or needs remediation.
4. If changing code is requested, keep the patch to the vulnerable boundary and add targeted tests when in scope.
5. Redact exploit-ready details from public PR text while preserving enough evidence for reviewers.
6. Run the common acceptance commands and any focused security tests required by the touched area.

## Acceptance commands
```bash
cd /Users/rainie/Desktop/GitHub/fap-api/backend && php artisan route:list --no-ansi
cd /Users/rainie/Desktop/GitHub/fap-api/backend && APP_ENV=testing DB_CONNECTION=sqlite DB_DATABASE=/tmp/fap-api-skill.sqlite php artisan migrate --force
cd /Users/rainie/Desktop/GitHub/fap-api && bash backend/scripts/ci_verify_mbti.sh
cd /Users/rainie/Desktop/GitHub/fap-api && git diff --check
```

## Output contract
- Report finding status, evidence files, tests or checks run, residual risk, and whether closure is supported.
- Keep public wording concise and non-operational.

## Stop conditions
- Stop when evidence is missing, severity expands beyond scope, a higher severity active issue appears, tests cannot prove the fix, or remediation would cross the declared PR scope.
