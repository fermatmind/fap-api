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
- Dirty worktree is allowed when the current task can stage only explicit scoped paths. Stop only when scoped paths overlap unrelated dirty changes or cannot be isolated cleanly.
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
- For docs-only, rules-only, and generated-contract-only changes, use lightweight validation such as `git diff --check` plus JSON/YAML/focused contract checks when relevant. Do not require full runtime checks unless runtime, API, migration, or scheduler files changed.
- If local checks fail, do not open a PR.
- Record failed checks in `docs/codex/pr-train-state.json`.
- Never continue to the next PR after a failed check.
- Draft PR exception: when a local check fails only on behavior clearly outside the current declared PR scope, and the user explicitly asks to proceed, Codex may open a draft PR for the current scope if all scoped checks pass. The draft PR body must list the failed command, failed tests, why they are outside scope, and state that the PR is not mergeable until required checks are green.
- This exception does not allow merging a PR with failed local or GitHub required checks.

## PR discipline
- Open exactly one PR for the current task.
- For PR-train PRs, the PR title must match the PR id and scope from the manifest.
- The PR body must include:
  - what changed
  - why
  - validation commands
  - intentionally deferred items
- If a PR is open and checks are pending, wait; do not start the next PR.
- Stacked draft PR exception: if the user explicitly asks to split the current task into multiple PRs, Codex may open multiple draft PRs for the same declared task only when each PR has a distinct scope, the dependency order is stated in every dependent PR body, and no PR contains files from another PR's scope.
- This exception does not allow merging dependent PRs out of order or bypassing required checks.

## Ad-hoc PR discipline
- Not every PR needs a PR-train id.
- Only PR-train work requires a PR id and PR-train metadata.
- Ordinary scoped PRs, such as repository rule updates, documentation summaries, cleanup-only changes, CI fixes, and small emergency repairs, may be opened without a train id.
- Ad-hoc PRs must not modify `docs/codex/pr-train.yaml` or `docs/codex/pr-train-state.json` unless the user explicitly asks for PR-train metadata updates.

## Merge discipline
- Merge only when the current PR satisfies its `merge_policy`.
- Use squash merge unless the manifest explicitly says otherwise.
- After merge, delete the remote branch.
- After merging a PR-train PR, close its state as `merged` in the same workflow whenever possible.
- If branch protection prevents direct ledger closeout, use one ledger-only follow-up PR with no new train id.
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
- Do not create a new PR-train task just to mark the previous task as `merged`.
- Never continue after a failed PR unless the manifest explicitly allows retry.

## Failure policy
- Stop immediately on:
  - preflight failure
  - failed local checks, except for the documented draft PR exception above
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
- Controlled Codex-assisted new article publish is allowed only through the backend `articles:publish-controlled` command after exact user confirmation, passing preflight gates, required claim-warning acknowledgement, and audit logging.
- Controlled Codex-assisted promotion of an already-published article's existing SEO update working revision is allowed only through the backend `articles:promote-existing-working-revision` command after exact article/revision/route locks, authenticated preview QA acknowledgement, downstream schema/hreflang/search/sitemap/llms/revalidation holds, passing preflight gates, and audit logging.
- Codex must not use generic CMS UI publish clicks or uncontrolled publish endpoints as the default production publishing mechanism.
- Homepage, tests hub, test category, career center, CTA text, module ordering, featured items, and landing SEO must be managed through `landing_surfaces` / `page_blocks`.
- Help, policy, company, brand, careers, about, charter, foundation, privacy, terms, refund, support, and similar static-content pages must be managed through `content_pages`.
- Career guides, career jobs, career recommendations, personality profiles, topic pages, FAQ, sections, and SEO must be managed through backend CMS resources and public APIs.
- Mutable editorial, marketing, social, article, landing page, and SEO images must be uploaded to Media Library and referenced by CMS metadata or generated variants.
- Public APIs must not emit historical Tencent/COS media URLs or ad hoc raw media URLs for CMS-backed surfaces.

## DailyGiving proof handling rules
- Original charity donation receipt/proof images may be uploaded as the public proof media asset when the operator explicitly approves that original image for public use. A separate redacted derivative is not required for DailyGiving public proof.
- Raw private storage paths, redaction notes, backend-only ledger fields, tokens, private URLs, secrets, and system credentials must never be exposed by public APIs, frontend rendering, sitemap, llms, social distribution, or search submission.
- `proof_public_url` is the only public proof media field. It must point to the operator-approved public media URL for the original charity donation proof image and must pass the backend proof gate before a DailyGiving record can be public.
- Backend authority may not be bypassed by frontend hardcoded URLs or CMS fallback copy.
- DailyGiving records with `is_public=true` must remain `is_indexable=false` until a separate indexability gate explicitly approves sitemap and llms inclusion. Trust badges, official partnership/endorsement claims, and guaranteed-impact claims remain blocked unless separately source-backed and approved.

## Final V4 backend protocols
- `content_baselines` may exist only for new environment initialization, DB recovery, baseline imports, disaster recovery, and dry-run validation. They must not be used as runtime page-rendering authority.
- Large content imports must include schema validation and dry-run support before import, especially career DOCX conversion, slugs, sections, SEO fields, media references, and publication state.
- CMS/API contracts must support frontend local development against local API, test/staging API, or mock API flows without requiring production CMS access.
- Experimental surfaces, SBTI, and heavily interactive product experiences may remain product-code-side unless explicitly converted into operational content.
- High-traffic CMS-backed entry pages must be served through an API/cache strategy that supports CMS/API content, stale last-known-good cache, then minimal shell behavior in the frontend. Do not rely on frontend hardcoded editorial copy as fallback.
- Business priority is fixed as L1 MBTI, L2 Big Five, and L3 SBTI/articles/topics/career recommendations/non-core tests. API resource isolation, throttling, cache refresh, and degradation policies must preserve this order.
- Long-term API resource isolation should separate lookup/questions read paths, auth/start/submit/result write paths, and non-core CMS/API paths.
