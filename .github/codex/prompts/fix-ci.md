You are fixing a failing PR CI run for the fap-api repository.

Repository facts
- Main workflow name: CI
- Workflow file to inspect first: .github/workflows/ci.yml
- PR checks exposed in GitHub UI: hygiene, verify-mbti-legacy, verify-mbti-v2
- Backend working directory: backend/
- PHP version in CI: 8.4
- Composer tool in CI: composer v2
- Node version in CI: 20
- Core CI script: backend/scripts/ci_verify_mbti.sh

Primary goal
Make the current failed PR CI run pass with the smallest safe change.

Required reading order
1. Read .github/workflows/ci.yml first.
2. Read .github/codex-context/metadata.txt if present.
3. Read .github/codex-context/failed-run.log if present.
4. Read backend/scripts/ci_verify_mbti.sh if the failure is in verify-mbti-legacy or verify-mbti-v2.
5. Read only the smallest set of source files needed to reproduce and fix the failure.

Execution rules
- Work from the current failed PR context only.
- Treat .github/workflows/ci.yml and backend/scripts/ci_verify_mbti.sh as the execution contract.
- Reproduce only the smallest relevant failing check.
- If the failure is in hygiene, run only the smallest hygiene command or commands required.
- If the failure is in verify-mbti-legacy or verify-mbti-v2, run only the smallest relevant subset first. Do not default to the full backend/scripts/ci_verify_mbti.sh unless narrower reproduction is not possible.
- Prefer targeted fixes over refactors.
- Do not change deployment, release, queue-worker, infra, secrets, branch workflow, or main-only logic unless required for the current failing check.
- Do not introduce compatibility wrappers, fallback bridges, hidden aliases, or broad cleanup unrelated to the failing contract.
- Keep the patch reviewable and safe to push back to the same PR branch.

Validation
- Re-run only the smallest relevant validation command or commands needed to verify the fix.
- Use backend/ as the working directory for Laravel, Composer, PHPUnit, and script commands.

Final summary must include
1. root cause
2. files changed
3. commands run
4. why the patch is minimal
5. any remaining risk or follow-up
