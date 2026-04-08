# Night PR Train Rules

## Scope discipline
- One PR = one scope.
- Never combine adjacent PR scopes.
- Never “fix future PRs” inside the current PR.
- Stop immediately if changed files drift outside the declared scope and `stop_if_changed_files_outside_scope` is true.

## Branch discipline
- Always start from the latest `main`.
- Always pull with `git pull --ff-only origin main` before creating a PR.
- Stop if the worktree is dirty before starting a PR.
- Stop if the target branch already exists locally or remotely with unrelated commits.

## Dependency discipline
- A PR may start only when all `depends_on` items are already merged into `main`.
- If a dependency is not merged, mark the current item `blocked_dependency` in `docs/codex/pr-train-state.json` and stop.

## Verification discipline
- Run all local checks listed in the PR manifest before push.
- If local checks fail, do not open a PR.
- Record failed checks in `docs/codex/pr-train-state.json`.
- Never continue to the next PR after a failed check.

## PR discipline
- Open exactly one PR for the current task.
- The PR title must match the PR id and scope from the manifest.
- The PR body must include:
  - what changed
  - why
  - validation commands
  - intentionally deferred items
- If a PR is open and checks are pending, wait; do not start the next PR.

## Merge discipline
- Merge only when the current PR satisfies its `merge_policy`.
- Use squash merge unless the manifest explicitly says otherwise.
- After merge, delete the remote branch.
- If running in a local clone, run `scripts/post_merge_cleanup.sh <branch> [base]`.
- If running outside a local clone, do not claim local cleanup was executed.

## State ledger discipline
- Record every state transition in `docs/codex/pr-train-state.json`.
- Update at minimum:
  - status
  - commit_sha
  - pr_url
  - checks
  - failure_reason
  - merged_at
  - remote_branch_deleted
  - local_cleanup_executed
- Never continue after a failed PR unless the manifest explicitly allows retry.

## Failure policy
- Stop immediately on:
  - preflight failure
  - failed local checks
  - failed required GitHub checks
  - merge block
  - review requirement block
  - ambiguous repository state
- Do not improvise around failures.
- Prefer stopping cleanly over partial progress.

## Local vs cloud execution
- If operating in a cloud-only environment, remote branch deletion is allowed, but local cleanup must be reported as not executed.
- If operating in a local clone, keep the local worktree clean between PRs.

## Truth boundary
- Codex may draft, refactor, and open PRs.
- Laravel/backend or the declared authority layer remains the source of truth where the manifest says so.
- Never replace an authority layer with frontend or CMS fallback logic.
