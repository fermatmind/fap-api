# Night PR Train Rules

## Scope discipline
- One PR = one scope.
- Never combine adjacent PR scopes.
- Never “fix future PRs” inside the current PR.
- Stop immediately if changed files drift outside the declared scope and `stop_if_changed_files_outside_scope` is true.

## Branch discipline
- Always start from the latest `main`.
- Always pull with `git pull --ff-only origin main` before creating a PR.
- A dirty worktree does not automatically block a PR start if unrelated changes are clearly isolated from the current PR.
- “Clearly isolated” means at least one of:
  - the unrelated changes are in files outside the declared PR scope, and the current PR can avoid touching them
  - the current PR can be staged with an explicit path-limited file list
  - the unrelated changes are already committed on another branch and are not part of the current branch diff
- Stop if the worktree is dirty and the current PR scope cannot be isolated cleanly from those existing changes.
- If scoped changes were made on `main` before a PR branch was created, Codex may still create the correct PR branch immediately, provided:
  - the changes are fully within the declared scope
  - the worktree contains no unrelated modifications
  - the branch is created before commit, push, or PR creation
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

## Content authority rules
- CMS/backend is the source of truth for publishable content, operational metadata, mutable media references, public SEO fields, and publishing state.
- Article content, article SEO, covers, categories/tags, related placement, and publication state must be managed through backend Article resources and APIs.
- Homepage, tests hub, test category, career center, CTA text, module ordering, featured items, and landing SEO must be managed through `landing_surfaces` / `page_blocks`.
- Help, policy, company, brand, careers, about, charter, foundation, privacy, terms, refund, support, and similar static-content pages must be managed through `content_pages`.
- Career guides, career jobs, career recommendations, personality profiles, topic pages, FAQ, sections, and SEO must be managed through backend CMS resources and public APIs.
- Mutable editorial, marketing, social, article, landing page, and SEO images must be uploaded to Media Library and referenced by CMS metadata or generated variants.
- Public APIs must not emit historical Tencent/COS media URLs or ad hoc raw media URLs for CMS-backed surfaces.

## Final V4 backend protocols
- `content_baselines` may exist only for new environment initialization, DB recovery, baseline imports, disaster recovery, and dry-run validation. They must not be used as runtime page-rendering authority.
- Large content imports must include schema validation and dry-run support before import, especially career DOCX conversion, slugs, sections, SEO fields, media references, and publication state.
- CMS/API contracts must support frontend local development against local API, test/staging API, or mock API flows without requiring production CMS access.
- Experimental surfaces, SBTI, and heavily interactive product experiences may remain product-code-side unless explicitly converted into operational content.
- High-traffic CMS-backed entry pages must be served through an API/cache strategy that supports CMS/API content, stale last-known-good cache, then minimal shell behavior in the frontend. Do not rely on frontend hardcoded editorial copy as fallback.
- Business priority is fixed as L1 MBTI, L2 Big Five, and L3 SBTI/articles/topics/career recommendations/non-core tests. API resource isolation, throttling, cache refresh, and degradation policies must preserve this order.
- Long-term API resource isolation should separate lookup/questions read paths, auth/start/submit/result write paths, and non-core CMS/API paths.
