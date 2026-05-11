---
name: pr-lifecycle
description: Create, review, merge, and deploy pull requests for fap-api. Covers branch creation, commit conventions, PR drafting, CI verification, admin merge, and post-merge cleanup.
---

# Pull Request Lifecycle

## Purpose
Manage the full PR lifecycle for fap-api: branch creation, targeted commits, PR drafting with correct body format, CI verification, force-merge (admin override) when needed, and post-merge branch cleanup.

## When to Use
- Creating a new PR for any backend change
- Force-merging a PR when required checks are failing on unrelated paths
- Cleaning up branches after merge
- Checking PR status and CI results

## When Not to Use
- For fap-web PRs — use the fap-web equivalent
- When a merge would break production — verify deploy readiness first
- When required checks are failing on paths RELATED to your changes

## Hard Invariants
- **Do not** merge with failing required checks unless the failures are on clearly unrelated paths AND the user explicitly approves admin override.
- **Do not** merge a draft PR with partial promotion or incomplete work.
- **Do not** push to main directly — always use a feature branch and PR.
- **Do not** include unrelated dirty files in the PR.
- **Do not** skip `git diff --check`.
- **Do not** delete the remote branch before the PR is merged.

## Standard Workflow

### Step 1 — Branch
```bash
git checkout main
git pull --ff-only origin main
git checkout -b <type>/<scope>-<description>
```

Branch naming: `fix/career-`, `feat/api-`, `codex/`, etc.

### Step 2 — Commit
- One logical change per commit
- Format: `type(scope): summary` (e.g., `fix(api): persist rollout state`)
- Run local CI before committing: `php artisan test`, `./vendor/bin/pint --test`

### Step 3 — Push
```bash
git push -u origin <branch_name>
```

### Step 4 — Create PR
```bash
gh pr create \
  --title "<type>(<scope>): <summary>" \
  --body "$(cat <<'EOF'
## Summary
...

## Tests
...
EOF
)"
```

PR body must include:
- What changed and why
- Files changed
- Tests run and results
- Verification commands

### Step 5 — Check CI
```bash
gh pr checks <PR_NUMBER>

# Wait for checks to complete
for i in $(seq 1 10); do
  sleep 20
  gh pr checks <PR_NUMBER>
done
```

### Step 6 — Merge
```bash
# Normal merge
gh pr merge <PR_NUMBER> --squash

# Admin override (only when failures are unrelated and user approves)
gh pr merge <PR_NUMBER> --squash --admin --subject "..."
```

### Step 7 — Cleanup
```bash
git checkout main
git pull --ff-only origin main
git branch -d <branch_name>
git push origin --delete <branch_name>
```

## Draft PR Exception
Per AGENTS.md: if `backend/scripts/ci_verify_mbti.sh` is already failing on paths clearly unrelated to the current PR scope, and the user explicitly asks to proceed, a draft PR may be opened if:
1. Scoped verification commands for the PR pass
2. Unrelated failing tests are listed in the PR body
3. PR body states the PR is not mergeable until those failures are fixed
4. No unrelated files are staged into the PR

This exception does NOT permit merging with failed required checks.

## Acceptance Commands
```bash
gh pr view <PR_NUMBER> --json state,mergeStateStatus,reviews
gh pr checks <PR_NUMBER>
gh run view <RUN_ID> --log-failed
git log --oneline main..<branch>
git diff --check
```

## Output Contract
- PR URL
- Branch name and commit SHA
- CI check results (hygiene, verify-mbti-legacy, verify-mbti-v2)
- Merge status
- Files changed count

## Stop Conditions
- Required CI checks fail on paths related to the PR
- Merge conflicts exist and cannot be cleanly resolved
- PR includes files outside declared scope
- `mergeStateStatus` is `UNSTABLE` and admin override is not approved
