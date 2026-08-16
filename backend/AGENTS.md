# Night PR Train Rules

## Delivery risk applicability

- Fast and Product lane work follows the root `AGENTS.md` one-loop, one-PR, focused-validation defaults. Controlled lane work preserves the applicable backend preflight/apply, receipt, approval, and fail-closed boundaries.
- PR-train manifest/state and ledger rules apply only when the task explicitly identifies PR-train work. Ordinary backend PRs must not add process-only train metadata.

## Scope discipline
- One PR = one scope.
- Never combine adjacent PR scopes.
- Never “fix future PRs” inside the current PR.
- Stop immediately if changed files drift outside the declared scope and `stop_if_changed_files_outside_scope` is true.

## Goal execution standing authorization
- FermatMind is a solo-developed project. At all times, treat every concrete end-to-end execution goal as continuous execution mode: make safe, reversible, in-scope decisions, record them, and continue without waiting for acknowledgements. This repository-wide working rule does not depend on time of day or unattended execution.
- A concrete `/goal` or equivalent end-to-end execution instruction for an identified scope is standing authorization for its normal PR lifecycle: branch, scoped implementation, checks, explicit staging, commit, push, PR creation, polling, same-scope CI/review fixes, policy-compliant merge, synchronization, and cleanup. Do not ask again for those actions.
- The same goal authorizes exact manifest/state initialization for an explicitly named PR-train task/card and declared dependency completion in dependency order.
- When a required check is blocked by a defect proven to pre-exist on `main` outside the current PR scope, report the evidence and keep the current PR isolated. Do not create a sidecar baseline-repair PR unless the user explicitly requests it or a Controlled lane boundary makes the separate repair necessary.
- Required checks and reviews remain mandatory. Stop only for materially ambiguous scope/authority, non-isolatable user changes, separately controlled production/CMS/database actions, unavailable external permission/review, or a repair that cannot be isolated and validated safely.
- Do not mark a goal blocked merely because an explicitly applicable manifest/state entry, declared dependency, same-scope CI/review fix, or wait/poll cycle is needed. Resolve those autonomously.
- This section overrides narrower repeat-authorization requirements below, but not planning-only/read-only instructions or controlled production-publish confirmations.

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
- If a dependency is not merged, do not start the dependent PR. Under an active execution goal, complete or wait for the declared dependency, then continue automatically. Mark `blocked_dependency` and stop only when the dependency requires unavailable external authority or cannot be completed safely.

## Verification discipline
- For explicit PR-train work, run all local checks listed in the PR manifest before push. Ordinary work follows the root delivery lane and focused verification rules.
- For docs-only, rules-only, and generated-contract-only changes, use lightweight validation such as `git diff --check` plus JSON/YAML/focused contract checks when relevant. Do not require full runtime checks unless runtime, API, migration, or scheduler files changed.
- If local checks fail, do not open a PR.
- For explicit PR-train work, record a material unresolved check failure in `docs/codex/pr-train-state.json`. Ordinary PRs must not create a ledger entry for a failed check.
- Never continue to the next PR after a failed check.
- Draft PR exception: when a local check fails only on behavior clearly outside the current declared PR scope, an active execution goal supplies the required authorization to proceed. Keep the current scope isolated and do not create a baseline-repair sidecar without an explicit request or Controlled lane necessity. Codex may open a draft PR for the current scope if all scoped checks pass; the body must list the failed command, failed tests, why they are outside scope, and state that the PR is not mergeable until required checks are green.
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
- If branch protection prevents direct ledger closeout, report the verified merge and cleanup facts and leave that closeout pending. Do not update the previous item from the next task or open a standalone ledger-only follow-up unless the user explicitly requests a separate reconciliation task.
- If running in a local clone, run `scripts/post_merge_cleanup.sh <branch> [base]`.
- If running outside a local clone, do not claim local cleanup was executed.

## State ledger discipline
- This section applies only to explicit PR-train work. Ordinary backend PRs must not touch `docs/codex/pr-train-state.json` merely to record process.
- Update only the current task, normally once before PR creation and once after final merge. Add an intermediate update only for a material failure, hold, or externally visible state that must survive the run.
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
- Never advance to the next PR after a failed PR unless the manifest permits it. Under an active execution goal, diagnose, fix, and rerun the current scoped PR without a new user prompt; record only a material unresolved failure or the final result.

## Failure policy
- Do not merge the current PR or advance to an unrelated next PR while any of the following remains unresolved:
  - preflight failure
  - failed local checks, except for the documented draft PR exception above
  - failed required GitHub checks
  - merge block
  - review requirement block
  - ambiguous repository state
- Do not improvise around failures.
- Under an active execution goal, exhaust safe in-scope diagnosis, retry, same-PR repair, and declared dependency completion before stopping. Do not create a baseline-repair sidecar without an explicit request or Controlled lane necessity; otherwise prefer a clean, evidence-backed hold.

## Local vs cloud execution
- If operating in a cloud-only environment, remote branch deletion is allowed, but local cleanup must be reported as not executed.
- If operating in a local clone, keep the local worktree clean between PRs.

## Truth boundary
- Codex may draft, refactor, and open PRs.
- Laravel/backend or the declared authority layer remains the source of truth where the manifest says so.
- Never replace an authority layer with frontend or CMS fallback logic.

## Content authority rules
- CMS/backend is the source of truth for publishable content, operational metadata, mutable media references, public SEO fields, and publishing state.
- The only editable private Big Five result-body authority is `content_packs/BIG5_OCEAN/v2/registry`, with English assets under its existing `en/` subtree; its `v2` path is stable, quality/tone, composite, facet, norm-evidence, and action copy must remain backend-structured, compiled packages and immutable database releases are derived artifacts, and public Personality/CMS content remains a separate authority.
- Article content, article SEO, covers, categories/tags, related placement, and publication state must be managed through backend Article resources and APIs.
- Controlled Codex-assisted new article publish is allowed only through the backend `articles:publish-controlled` command after exact user confirmation, passing preflight gates, required claim-warning acknowledgement, and audit logging.
- Controlled Codex-assisted promotion of an already-published article's existing SEO update working revision is allowed only through the backend `articles:promote-existing-working-revision` command after exact article/revision/route locks, authenticated preview QA acknowledgement, downstream schema/hreflang/search/sitemap/llms/revalidation holds, passing preflight gates, and audit logging.
- Internal human review uses the backend-authoritative `solo_owner` policy: the configured owner/operator may be author, submitter, reviewer, attester, requester, and approver, and a second internal reviewer is not required. The historical Big Five ZH6 `solo_operator` label remains cohort evidence for exact `admin_user:1`; it does not override or narrow the global `solo_owner` policy. Compact attestations must bind an exact deterministic target set and expand into immutable per-target private evidence. This does not create or replace external evidence and does not weaken automated checks, exact-SHA production authorization, preflight/readback, audit, permissions, TOTP/step-up, or separate publish/promotion/execute transitions. Human review completion never authorizes production execution. Personality public content media readiness is retired: Big Five and Enneagram `PersonalityPublicContentAsset` records permanently support no hero, inline, OG, or Twitter image fields.
- New or modified backend manual review/approval gate code must declare `@review-surface <surface_id>` with an ID present in `ReviewPolicyRegistry`; the architecture gate fails closed when the declaration is missing or unknown.
- Big Five readiness artifacts and working revisions are non-public inputs. They do not change the published projection and cannot substitute for revision-bound review, authenticated preview approval, live fingerprint/rollback gates, or an explicitly authorized pointer-safe promotion.
- Exact exception: the operator-authorized `personality:big-five-authority-v2-zh-content-publish` command may publish only the fixed 112-row `zh-CN` Big Five Authority V2 content cohort. For this command only, author/reviewer/source/date/revision-fingerprint/preview/rollback completeness are non-blocking; the 52 Personality rows must contain no media field or deferred marker, while the remaining 60 CMS rows continue to record `media_deferred_by_operator`. Media Library and frontend fallback writes remain zero, English rows remain untouched, and exact-count transactional public readback is mandatory. This exception does not alter any other publishing workflow.
- The Big Five zh-CN V3 authority release is restricted to the deterministic 52-page package compiled from source content SHA `056b10d3f640d0cf7da35ec7bc99b009408049e75c1e25aa8e760eb8641ea8d5` and package file SHA `83536987f7edc73d668f481942c94f6bf549abf23a0e498941f47bc56726490d`. `personality:big-five-zh-v3-content-publish` is read-only by default and may write only after both exact hashes and `admin_user:1` are confirmed; it must update the 52 existing zh-CN `PersonalityPublicContentAsset` rows in one transaction, create immutable package-bound revisions, perform transaction-wide readback, remain idempotent, preserve CMS hreflang, and leave English, media, search submission, and all non-target authority rows unchanged. Legacy alias database assets must be absent before the publisher can run; any exact or alias-shaped row that reappears fails closed and remains outside the 52 descriptors. Production execution remains separately controlled after deployment and read-only readiness verification.
- The Big Five English EN52 authority release is restricted to the deterministic 52-page package compiled from source content SHA `056b10d3f640d0cf7da35ec7bc99b009408049e75c1e25aa8e760eb8641ea8d5`, cohort SHA `94449467281cffaccc295bab3bbbb574cf817e461ee2fbae8288eedd9a988b3a`, and runtime package file SHA `91f3c1e94894cfe59ce17ee00e5046d26a9cafc9113fe1eeb4488e4951e4940a`. `personality:big-five-en52-content-publish` is read-only by default and may write only after all three exact hashes, exact deployed SHA/release, `admin_user:1`, and a verified `big-five-en52-production-backup.v1` manifest are confirmed; the manifest must bind the exact live 52-row asset/revision/review checksums and the approved zh-CN, non-target Personality, and Search Channel fingerprints. The publisher must revalidate that backup under the transaction lock, update exactly 52 existing `en` canonical rows in one transaction, create immutable package-bound revisions, perform transaction-wide readback, remain idempotent, require exact CMS-owned en/zh-CN hreflang pairs, and leave zh-CN, media, search submission, and all non-target authority unchanged. All twenty locale-specific legacy alias database assets must be absent. Runtime discoverability verification counts only the exact 104 canonical paths from `BigFiveCanonicalRouteCatalog`; independent ContentPage paths under the same URL prefix do not enter that cohort, while any reviewed legacy alias on sitemap, llms, or llms-full surfaces remains a hard failure. A successful alias hard purge must close out the exact en/zh collection, twenty alias detail, sitemap-source fresh/stale, sitemap XML/ETag, and frontend llms/llms-full caches. Cache failure after commit is a non-rollback `PARTIAL_CACHE_CLOSEOUT`; `--cache-closeout-only` may retry only that locked cache set after separately controlled authorization and must perform zero database writes. Production execution remains separately controlled after deployment and read-only readiness verification.
- The twenty historical Big Five `high-*`, `low-*`, and `emotional-stability` en/zh-CN identities are URL-only aliases, never CMS/database content assets. They may exist only in the backend-locked redirect catalog and fap-web deterministic single-hop 301 routes. Physical database removal is allowed only through `personality:big-five-legacy-aliases-purge`, which is read-only by default and requires an exact twenty-row boundary, zero attestation target references, a verified row-count/checksum backup manifest, explicit confirmation, and a positive operator admin id before execute. Execute must cascade only the target revisions/reviews, preserve all 104 canonical rows and every non-target Personality fingerprint, and fail closed on partial, unknown, mixed, or reappearing alias state. Production purge remains a separately authorized database action.
- Codex must not use generic CMS UI publish clicks or uncontrolled publish endpoints as the default production publishing mechanism.
- Homepage, tests hub, test category, career center, CTA text, module ordering, featured items, and landing SEO must be managed through `landing_surfaces` / `page_blocks`.
- Help, policy, company, brand, careers, about, charter, foundation, privacy, terms, refund, support, and similar static-content pages must be managed through `content_pages`.
- Career guides, career jobs, career recommendations, personality profiles, topic pages, FAQ, sections, and SEO must be managed through backend CMS resources and public APIs.
- Mutable editorial, marketing, social, article, landing page, and SEO images must be uploaded to Media Library and referenced by CMS metadata or generated variants.
- Big Five and Enneagram `PersonalityPublicContentAsset` pages are the explicit text-only exception: their database, CMS/import contract, public API, section content, and SEO projection must not support hero, inline, OG, Twitter, Markdown, or HTML images. Historical revision media keys are audit-only and never runtime authority.
- Public APIs must not emit historical Tencent/COS media URLs or ad hoc raw media URLs for CMS-backed surfaces.

## English parity exact-package importer rules
- W1 MBTI English comparison dry-run import is bound to the immutable package SHA recorded by `EN-PARITY-W1-MBTI-COMPARISON-ASSETS-01`. The importer must recompute the manifest file chain and reject unknown, mismatched, or rebuilt packages.
- Dry-run is the only mode supported by `EN-PARITY-W1-MBTI-COMPARISON-IMPORTER-01`. It may emit a redacted deterministic plan, but it must not write CMS/database rows, publish, activate, release indexability, update sitemap/llms, submit search URLs, or expose reader copy or local paths in its receipt.
- Locale pairing is fixed as read-only `zh-CN` source authority to `en` target authority for the exact seven comparison slugs. A dry run must never overwrite or mutate the `zh-CN` authority row.
- Actual inactive/draft import is a separate controlled PR and requires the exact frozen package SHA plus its own authorization. A successful dry run is not an import, W9 approval, editorial approval, publication, activation, or SEO release receipt.
- `EN-PARITY-W1-MBTI-COMPARISON-DRAFT-IMPORT-01` may upsert only the exact seven `org_id=0`, `locale=en` comparison identities after revalidating package SHA `deecc8175fb43ba3730d6513b496a0ab6834459108e3b24e25550bbf40e001a2` and human-operator approval artifact SHA `42853f27ff4e921f0d91e8e50210620dd212fddf6fab7763ae82544087d02a8b`. The write must be transactional, collision-safe, strictly idempotent, W9-bound, and inactive/draft only; advanced editorial or indexability state is a collision, not an overwrite. This artifact keeps staging and production import authorization false, so its command must reject both environments. zh-CN, public state, active pointers, indexability, sitemap, llms, Search Channel, caches, and deploy state must remain unchanged.
- `EN-PARITY-W1-MBTI-RESULT-DRAFT-IMPORT-01` may materialize only the exact 46-row result reconciliation bound to package SHA `9325013b870fd2496efc0882656240f91ce28ff4faaf1da42fb3dde3577b0ed3` and human-operator approval artifact SHA `17ae71733abe77bd7e75f4492374879c1888c4f5f3f671f53b003b6b878152e2`. Exactly 21 candidate assets may enter the unregistered `MBTI.global.en.default` inactive draft authority; 24 controls and the synthetic PDF fixture remain no-write references. The import must be collision-safe, atomic, strictly idempotent, private-field-free, and local/testing-only. It must not alter the pack manifest, commercial runtime file, fallback registry, active pointer, private attempt/report data, activation, publication, indexability, sitemap, llms, Search Channel, or deploy state.
- W1 MBTI English result dry-run import is separately bound to the immutable result-package SHA recorded by `EN-PARITY-W1-MBTI-RESULT-ASSETS-01`. It must validate the complete 46-row reconciliation, the exact English canonical-section and entitlement identities, and the synthetic private-safe PDF fixture access identity from the same verified package bytes.
- `EN-PARITY-W1-MBTI-RESULT-IMPORTER-01` is no-write and no-activation only. It must not read an attempt, report, private URL, user score, answer, entitlement owner, order, payment, recovery record, or production PDF payload; change an active package pointer; or expose reader copy, filesystem paths, or private identifiers in its receipt.
- The result dry-run plan must preserve the exact split of 24 unchanged controls, 21 inactive English candidate assets, and one W9 fixture-only PDF row. The absent English content-pack authority remains a fail-closed prerequisite for the separately controlled inactive-content import; dry-run success never materializes that authority.
- W4 RIASEC importer dry-runs may accept only the exact CONTROL qa_pass package SHA and independent W9 report bound in `backend/content_assets/en-content-parity/W4-riasec/external_package_evidence.json`.
- W4 exact-package authority must retain a physical, non-symlinked, non-hardlinked nine-segment JSONL snapshot for all 1550 rows, verify every payload SHA and identity, persist the exact English reader payload plus identity in the backend release authority, and reject CJK from reader-visible English payloads. The redacted dry-run receipt must not expose those copies or local paths.
- W4 dry-run validation must preserve the 14 logical groups, 1550 atomic rows, 15 normalized pairs, and 3/2/2 safe-surface reconciliation; direct `--write` remains fail-closed. Only the trusted exact-package promotion executor may perform separately authorized draft import, publication, readback, live QA, and bounded rollback; it must not change indexability, sitemap, llms, or Search Channel state.

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
