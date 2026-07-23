# AGENTS.md — Repository + Codex Working Contract (MUST FOLLOW)

> This file is binding for any agent/Codex work in this repo.

## A) Hard Rules (User Contract)

### Rule 1 — Small loop first
- Advance exactly ONE demo-able small loop per run/commit.
  Example: `send_code → verify → /me/attempts`
- Do NOT mix multiple loops into one submission.
- Do NOT break `backend/scripts/ci_verify_mbti.sh` core chain:
  - content pack / report / events funnel must stay green.
- Draft PR exception: if `backend/scripts/ci_verify_mbti.sh` is already failing on paths clearly unrelated to the current declared PR scope, an active execution goal supplies the authorization to proceed. Prefer a separate minimal baseline-repair PR when it can restore the required check; otherwise Codex may open a draft PR after:
  - the scoped verification commands for the current PR pass
  - the unrelated failing tests are listed in the PR body
  - the PR body states the PR is not mergeable until those failures are fixed
  - no unrelated files are staged into the PR
- This exception does not permit merging with failed required checks.

### Rule 2 — Fixed change order (must follow)
Changes must be made in this strict order:
1) `backend/routes/api.php`
2) `backend/database/migrations/*`
3) `backend/app/Http/Middleware/FmTokenAuth.php`  
   - must: DB lookup + inject `fm_user_id`
4) Controller/Service layer (controllers, requests, services)
5) Scripts/CI changes LAST (only if needed)

For EACH step, you MUST provide a verification command.

### Rule 3 — Mandatory response format
Every output MUST include:
A. Changed files list (Added vs Modified)
B. Copy-paste blocks (exact insertion position / exact replacement range; no vague instructions)
C. Minimal acceptance commands set:
   - `php artisan route:list`
   - `php artisan migrate`
   - `curl` examples
   - `bash backend/scripts/ci_verify_mbti.sh`

For docs-only, rules-only, and generated-contract-only changes, C may state that runtime commands are not applicable and list the lightweight checks that were actually run.

If A/B/C is missing, the answer is incomplete.

### Extra constraint
Unless explicitly asked, do NOT request more user info.
Prefer a repo-compatible default implementation and mark options as optional.

### Goal execution standing authorization
- FermatMind is a solo-developed project. At all times, treat every concrete end-to-end execution goal as continuous execution mode: make safe, reversible, in-scope decisions with best judgment, record them in the PR/ledger, and continue without waiting for acknowledgements. This repository-wide working rule does not depend on time of day or unattended execution.
- A concrete `/goal` or equivalent instruction that asks Codex to execute or finish an identified implementation scope end to end is standing authorization for the complete normal PR lifecycle for that scope. Do not pause for another authorization before branch creation/switching, scoped edits, checks, explicit staging, commit, push, PR creation, polling, same-scope CI/review fixes, policy-compliant merge, synchronization, or cleanup.
- If the goal names a PR-train item, scan/PR card, task id, title, or otherwise unambiguously identifies the scope, it also authorizes the exact required manifest/state initialization or updates. Do not request a second manifest/state authorization.
- If required validation is blocked by a defect proven to pre-exist on `main` and outside the current PR scope, create and finish a separate minimal ad-hoc baseline-repair PR under the same standing authorization, then resume the original goal. Do not mix that repair into the goal PR and do not ask for another PR authorization.
- Required checks, reviews, branch protection, and merge policies remain mandatory. Diagnose and repair same-scope failures automatically, but never merge with failed required checks.
- Do not mark or report a goal as blocked merely because it needs a missing manifest/state entry, an already-declared dependency, a same-scope CI/review fix, a wait/poll cycle, or an isolated pre-existing baseline repair PR. Resolve those autonomously under this section.
- Stop for user direction only when scope or authority is materially ambiguous, user changes overlap and cannot be isolated, a production/CMS/database write or another separately controlled action is required, an external permission/review is unavailable, or a necessary repair cannot be isolated and validated safely. Ordinary branch/commit/push/PR/merge actions are not blockers.
- This section overrides narrower authorization prompts elsewhere in this file for ordinary PR lifecycle, same-scope fixes, missing manifest/state entries, directly blocking ledger reconciliation, and isolated baseline-repair PRs. Planning-only/read-only requests and controlled production-publish confirmation requirements remain unchanged.

---

## B) Repository Guidelines (Context)

### Project Structure & Module Organization
- `backend/` is the Laravel API implementation. Key areas: `app/` (domain/services), `routes/` (HTTP entrypoints), `config/` (runtime config), `database/` (migrations/seeders), `resources/` (Vite assets), `tests/` (PHPUnit).
- `content_packages/` holds MBTI content packs and assets. `default/` is active content; `_deprecated/` is archived.
- `docs/`, `00-plan/`, `01-api-design/`, `02-db-design/`, `03-env-config/`, `04-analytics/` contain specs, API/DB designs, and analytics docs.
- `tools/` contains helper scripts (for report asset generation).

### Build, Test, and Development Commands
- `cd backend && composer setup` installs deps, creates `.env`, generates key, migrates, and builds Vite assets.
- `cd backend && composer dev` runs the local stack concurrently.
- `cd backend && composer test` runs PHPUnit.
- `cd backend && composer test:content` checks content packages and runs focused tests.
- `make selfcheck MANIFEST=content_packages/.../manifest.json` validates a content pack.
- `cd backend && bash scripts/verify_mbti.sh` runs MBTI E2E; artifacts in `backend/artifacts/verify_mbti/`.
- `cd backend && bash scripts/ci_verify_mbti.sh` is the CI master chain and must remain green.

### Coding Style & Naming Conventions
- EditorConfig in `backend/.editorconfig`: 4-space indent, LF, trim trailing whitespace.
- PHP follows PSR-12/Laravel conventions; format with Pint: `cd backend && ./vendor/bin/pint`.
- Keep API versioned controllers under `backend/app/Http/Controllers/API/V0_2/`.

### Commit & Pull Request Guidelines
- Follow `type(scope): summary` (e.g., `feat(tp5): ...`).
- PR description must include summary + tests ran.

### Local Verification Tiers
- Ordinary scoped PRs default to focused local verification: run the PHPUnit tests that directly cover the changed behavior, Pint or the relevant static check for touched files, and git diff --check.
- Run php artisan route:list when routes or route wiring change.
- Run a fresh SQLite migration when migrations or schema-dependent behavior change.
- Run bash backend/scripts/ci_verify_mbti.sh locally only when the PR manifest, a security/high-risk skill, the changed runtime boundary, or the user explicitly requires it.
- Pull requests still require the repository's complete GitHub required checks. A focused local check never permits merging with a failed or missing required check.

### Deployment Smoke Retry Discipline
- Public scale lookup deployment smoke may retry a complete HTTP and semantic assertion only through `backend/scripts/deploy/verify_scale_lookup.sh`, using the repository-bounded attempt, delay, connect-timeout, and request-timeout limits.
- A recovered transient transport failure does not invalidate an otherwise healthy deploy. Persistent transport failure, malformed JSON, `ok != true`, or a mismatched `primary_slug` remains fail closed.
- Retry behavior must not replace the public authority probe with a loopback-only probe, print private topology or response bodies, or authorize deploy, rollback, restart, unlock, migration, CMS mutation, or Search Channel activity.
- Production health evidence must keep the protected surfaces distinct: the target-node loopback vhost `/api/healthz` requires `200` and `ok=true`, while the non-allowlisted public `/api/healthz` must remain exactly `404`. Public-DNS reachability is proven through the flags API and the zh-CN Big Five Hub Personality API, which must return `200`; the Personality response must also expose a valid 64-character `source_hash`. Neither the production workflow nor Deployer may require public `/api/healthz` to return `200`.
- Sitemap source warming is a derived-cache operation, not content authority or release evidence. Standard deploy runs one bounded attempt through `backend/scripts/deploy/verify_sitemap_source_cache_warm.sh`; timeout, lock contention, malformed/empty output, or command failure is non-blocking by default and must not print raw output or topology. Invalid helper configuration remains fail closed, strict mode may require warm success, and the post-symlink loopback sitemap-source health gate must still return `ok=true`, `count>=1`, and either the full generator source or the safe fallback source. The deploy must not automatically retry the heavy sitemap generator.
- Anonymous org-0 Big Five and Enneagram collection GETs must attempt a fence-safe active projection read before querying the database. A valid active hit is a lock-free, read-only path: it must not acquire a collection write lock, refresh the active pointer, or update the selector registry. The reader must verify a stable fence and pointer around the payload and require the current projection discriminator. Only cache miss/rebuild publication, invalidation, and fence rotation may use collection write locks. Database or projection failure may use only a current-discriminator LKG payload that passes the same stability checks; otherwise the API remains fail closed. This read strategy does not change CMS/backend authority, public content, SEO eligibility, or publication permissions.

### Production Verify-Only Discipline
- The generic backend production verify-only workflow is a read-only evidence authority, not a deploy path. It requires an exact SHA/release approval on `main`, may read only the current release identity, internal/public health, business APIs, schema and process state, and may create only a sanitized runner-side artifact.
- Verify-only release directory identifiers accept ASCII letters in either case, digits, dots, underscores, and hyphens so existing UTC-stamped production names with `T` and `Z` remain verifiable; path separators, whitespace, shell metacharacters, and names longer than 128 characters remain rejected.
- Verify-only must not deploy, migrate, publish, restart, unlock, write remote files, inspect raw logs, submit search URLs, or mutate CMS/database state. Any failed check stops and reports without repairing production.

### Immutable Staged Release Candidate Discipline
- A standard backend production deployment targets an immutable candidate SHA with an exact successful staging run; it does not chase a moving latest `main`. The candidate may trail `main` only while it remains an ancestor of current `main`, its staging run is for that exact SHA, and the operator approval explicitly binds the exact SHA/release while excluding all newer main commits.
- Immediately before any remote mutation, the production workflow must revalidate that the candidate remains reachable from current `main`, read the current production `REVISION`, and prove that production is a strict ancestor of the candidate. Divergence, rollback, an unresolvable revision, an already-deployed candidate, or non-exact staging evidence fails closed.
- The sole audited divergence exception is the exact isolated Runtime 46 production baseline `bc0ed833bc9aae1473ab37f1dead2517e1aff618` converging to a main-reachable candidate descended from audited bridge `49038deb50cda789e4365ea42068832ed28d6023`. The chosen candidate must have its own exact successful staging evidence under the normal immutable-candidate gate. The exception is allowed only when the production commit has one parent, that parent is an ancestor of the candidate, its diff is exactly the locked six Runtime 46 paths with locked add/modify statuses, and every resulting production blob is byte-identical to the candidate blob. It is not reusable for a candidate outside the audited bridge history or for any other production SHA, path, status, rename, deletion, or content delta.
- Newer main commits remain outside the bounded deployment and are listed in the production release record. Main advancement alone never invalidates an otherwise safe staged candidate, and it never authorizes deploying newer commits.
- The exact staged Big Five EN52 bridge candidate `49038deb50cda789e4365ea42068832ed28d6023` may use only its reviewed candidate recipe plus the runner-only immutable sitemap control wrapper and warm helper whose SHA-256 values are locked in the production workflow. The wrapper may replace only `seo:warm-sitemap-source-cache` and add the safe `healthcheck:sitemap-source` prerequisite; it must stream the helper over stdin, leave the candidate tree and remote release untouched, preserve the exact candidate `REVISION`, and emit a sanitized control receipt. Any candidate, staging-run, recipe, wrapper, helper, tree, or control-SHA drift fails closed. This exception never authorizes a production deploy or any CMS/database/content write.

### Inactive Candidate Materialization Discipline
- A backend release needed for candidate-exact offline verification must use the protected `candidate_only` production mode with the exact latest `main` SHA, exact successful staging run, current active `REVISION`, release id, and the workflow-defined exact authorization phrase.
- `candidate_only` may materialize the managed release, install its exact dependencies, link the existing runtime environment/storage, and build release-local framework caches. It must finish successfully without `deploy:publish`, symlink activation, migration, queue reload, CMS/database authority mutation, publication, discoverability mutation, Career public-cache repair, or active-runtime health/restart actions.
- The workflow must verify the candidate revision, runner SHA, managed release identity, inactive state, unchanged active revision, and absent deploy lock, then upload only a sanitized receipt. Candidate creation must never rely on an intentionally failed deployment to leave an inactive release.

### Production Ops Queue Topology Discipline
- Approval execution uses the dedicated `ops` queue. A production deployment that classifies approval runtime paths must verify the `fap-queue-ops` Supervisor program exists and is already `RUNNING` before `deploy:symlink`; checking only that `supervisorctl` exists is insufficient.
- The canonical one-worker config is `deploy/supervisor/fap-queue-ops.conf.template`. Production preflight/apply must use the protected `Backend Production Ops Queue Control` workflow, secrets-only routing, exact latest control-plane SHA, exact active revision, exact template/rendered hashes, an immutable successful preflight receipt, and the workflow-defined authorization phrase.
- Preflight is read-only and must prove zero pending `ops` jobs and no deploy lock/process. A running worker may be classified only as `CURRENT` or `STALE_MANAGED_RELEASE`: the latter must resolve to one direct, safe-named child of the managed releases root with a valid revision, while its user, argv, config, and process age remain exact. Unknown working directories fail closed. Apply may install that one exact config and restart/start that one worker only; after apply its working directory must resolve to the active release. It must not deploy code, move the active symlink, migrate, or change CMS/database authority, publication, sitemap, llms, search, or PR23 state.

### Career Candidate-Exact Cache Bootstrap Discipline
- The inactive Career candidate bootstrap uses only the candidate's own code, a 50-row offline batch, a 5,000ms build budget, and at most one 500ms retry for an explicit build-budget or transient database-read classification. The public HTTP warmer remains on its separate 2,000ms budget.
- Every write authorization must bind a successful immutable v2 verify-only artifact by run id/attempt, exact control-plane and runner SHAs, exact active/candidate/release/staging identities, exact missing count, and the row-level coverage fingerprint. A v1 packet, fingerprint drift, count drift, or any intervening bootstrap run fails closed.
- Bootstrap receipts may expose only safe stage/category, build timing, counts, and deterministic hashes. Target identity, SQL, exception text, cache keys, SSH hosts/users/ports/paths, and known-hosts material are prohibited from logs and artifacts.
- The Career cache repair workflow may read production routing only from protected environment secrets. It must not use Actions-variable fallback or job-level routing environment. The retired candidate `88dedb58f341e6c92d07754eac7862fa3454dc7c` must never be used again.
- The workflow remains cache-only and candidate-inactive: no active worker/queue, deployment, symlink activation, migration, CMS/database authority, publication, indexability, sitemap, llms, Search Channel, or automatic rollback action is permitted.

### PR Train Manifest Discipline
- Under a concrete execution goal, add an exact missing goal-supplied PR-train manifest/state entry under the standing authorization and continue. Outside an execution goal, stop and report the gap unless the user asks to update the train manifest and state ledger.
- This stop rule applies only when the user requested a PR-train item. It must not block an explicitly requested ad-hoc PR whose scope does not modify PR-train metadata.
- Only PR-train work requires a PR id and PR-train metadata. Ordinary scoped PRs may be opened without a train id and must not touch PR-train metadata unless explicitly requested.
- When an execution goal identifies the missing item, add the corresponding `docs/codex/pr-train.yaml` and `docs/codex/pr-train-state.json` entries first, then continue without a second authorization prompt.
- Never invent a PR id or scope that is not either:
  - already present in the manifest, or
  - explicitly provided by the user.
- For scan/planning-only tasks, Codex must anticipate PR-train execution. If it proposes a future PR that is not already in `docs/codex/pr-train.yaml`, the scan output must include:
  - proposed PR train id
  - proposed PR title
  - proposed scope and files likely touched
  - required local checks
  - dependency assumptions
  - exact manifest/state entries that would be required before implementation
  - a follow-up execution prompt that names the exact item and scope; issuing it supplies standing authorization
- Scan/planning-only tasks must not modify `docs/codex/pr-train.yaml` or `docs/codex/pr-train-state.json` unless the user explicitly authorizes manifest/state updates in that same turn.
- If the user provides a concrete `/goal` or equivalent execution request with an explicit PR id, title, and scope, treat those as user-provided manifest details and add missing manifest/state entries without a second authorization prompt.
- After merging a PR-train PR, close its state as `merged` in the same workflow whenever possible.
- If branch protection prevents recording final merge facts in the implementation PR, report the verified PR, merge SHA, origin/main containment, and cleanup facts in the final response. Do not automatically open a standalone ledger-only PR.
- Reconcile stale state in the next same-repository PR that already modifies docs/codex. Under an active execution goal, if stale state blocks dependency resolution and no natural follow-up exists, create one minimal ad-hoc ledger reconciliation PR under the standing authorization; never create a new train id solely for reconciliation.

### Controlled CMS Publish Discipline
- Controlled Codex-assisted article publish is permitted only through the backend `articles:publish-controlled` command after exact user confirmation, successful preflight, explicit boundary-context claim-warning acknowledgement when needed, and audit logging.
- Controlled Codex-assisted promotion of an already-published article's existing SEO update working revision is permitted only through the backend `articles:promote-existing-working-revision` command after exact article/revision/route locks, authenticated preview QA acknowledgement, downstream schema/hreflang/search/sitemap/llms/revalidation holds, successful preflight, and audit logging.
- Internal human review uses the backend-authoritative `solo_owner` policy: the configured owner/operator may be author, submitter, reviewer, attester, requester, and approver, and a second internal reviewer is not required. Compact attestations must be bound to an exact deterministic target set and expanded into immutable per-target private evidence. This does not create or replace external evidence and does not weaken automated checks, exact-SHA production authorization, preflight/readback, audit, permissions, TOTP/step-up, or separate publish/promotion/execute transitions. Human review completion never authorizes production execution. Big Five and Enneagram `PersonalityPublicContentAsset` records permanently support no hero, inline, OG, or Twitter image fields.
- New or modified manual review/approval gate code must declare `@review-surface <surface_id>` with an ID present in `ReviewPolicyRegistry`; the architecture gate fails closed when the declaration is missing or unknown.
- A readiness package, working pointer, HTTP success, or generated SHA never proves publication or authorizes promotion. Personality promotion remains separately gated by exact revision-bound review, authenticated preview approval, live fingerprints, rollback readiness, and explicit controlled authorization.
- Exact exception: the operator-authorized `personality:big-five-authority-v2-zh-content-publish` command may publish only the fixed 112-row `zh-CN` Big Five Authority V2 content cohort. For this command only, author/reviewer/source/date/revision-fingerprint/preview/rollback completeness are non-blocking; the 52 Personality rows must contain no media field or deferred marker, while the remaining 60 CMS rows continue to record `media_deferred_by_operator`. Media Library and frontend fallback writes remain zero, English rows remain untouched, and exact-count transactional public readback is mandatory. This exception does not alter any other publishing workflow.
- The Big Five zh-CN V3 authority release is restricted to the deterministic 52-page package compiled from source content SHA `056b10d3f640d0cf7da35ec7bc99b009408049e75c1e25aa8e760eb8641ea8d5` and package file SHA `83536987f7edc73d668f481942c94f6bf549abf23a0e498941f47bc56726490d`. `personality:big-five-zh-v3-content-publish` is read-only by default and may write only after both exact hashes and `admin_user:1` are confirmed; it must update the 52 existing zh-CN `PersonalityPublicContentAsset` rows in one transaction, create immutable package-bound revisions, perform transaction-wide readback, remain idempotent, preserve CMS hreflang, and leave English, media, search submission, and all non-target authority rows unchanged. Legacy alias database assets must be absent before the publisher can run; any exact or alias-shaped row that reappears fails closed and remains outside the 52 descriptors. Production execution remains a separately controlled action after code deployment and read-only readiness verification.
- The Big Five English EN52 authority release is restricted to the deterministic 52-page package compiled from source content SHA `056b10d3f640d0cf7da35ec7bc99b009408049e75c1e25aa8e760eb8641ea8d5`, cohort SHA `94449467281cffaccc295bab3bbbb574cf817e461ee2fbae8288eedd9a988b3a`, and runtime package file SHA `91f3c1e94894cfe59ce17ee00e5046d26a9cafc9113fe1eeb4488e4951e4940a`. `personality:big-five-en52-content-publish` is read-only by default and may write only after all three exact hashes, exact deployed SHA/release, `admin_user:1`, and a verified `big-five-en52-production-backup.v1` manifest are confirmed; the manifest must bind the exact live 52-row asset/revision/review checksums and the approved zh-CN, non-target Personality, and Search Channel fingerprints. The publisher must revalidate that backup under the transaction lock, update exactly 52 existing `en` canonical rows in one transaction, create immutable package-bound revisions, perform transaction-wide readback, remain idempotent, require exact CMS-owned en/zh-CN hreflang pairs, and leave zh-CN, media, search submission, and all non-target authority unchanged. All twenty locale-specific legacy alias database assets must be absent. Runtime discoverability verification counts only the exact 104 canonical paths from `BigFiveCanonicalRouteCatalog`; independent ContentPage paths under the same URL prefix do not enter that cohort, while any reviewed legacy alias on sitemap, llms, or llms-full surfaces remains a hard failure. A successful alias hard purge must close out the exact en/zh collection, twenty alias detail, sitemap-source fresh/stale, sitemap XML/ETag, and frontend llms/llms-full caches. Cache failure after commit is a non-rollback `PARTIAL_CACHE_CLOSEOUT`; `--cache-closeout-only` may retry only that locked cache set after separately controlled authorization and must perform zero database writes. Production execution remains a separately controlled action after deployment and read-only readiness verification.
- The twenty historical Big Five `high-*`, `low-*`, and `emotional-stability` en/zh-CN identities are URL-only aliases, never CMS/database content assets. They may exist only in the backend-locked redirect catalog and fap-web deterministic single-hop 301 routes. Physical database removal is allowed only through `personality:big-five-legacy-aliases-purge`, which is read-only by default and requires an exact twenty-row boundary, zero attestation target references, a verified row-count/checksum backup manifest, explicit confirmation, and a positive operator admin id before execute. Execute must cascade only the target revisions/reviews, preserve all 104 canonical rows and every non-target Personality fingerprint, and fail closed on partial, unknown, mixed, or reappearing alias state. Production purge remains a separately authorized database action.
- Generic Filament release actions and direct `releaseRecord`/`releaseItem` entry points are prohibited for Article, CareerGuide, and CareerJob. A content type without an approved controlled publisher must remain read-only and fail closed on the release workspace.
- Codex must not use generic CMS UI publish clicks, uncontrolled API publish endpoints, or production content mutation outside that controlled SOP.

### CMS Administrator MFA Discipline
- When TOTP is required, an unenrolled administrator may access only enrollment, recovery/challenge, and logout surfaces; ordinary Ops and CMS routes fail closed.
- TOTP is enabled by default and may be explicitly disabled through `OPS_ADMIN_TOTP_ENABLED=false`; production must honor that runtime configuration rather than force TOTP unconditionally. Recovery codes are single-use, time-limited, and audited; their use requires credential rotation.
- Owner bootstrap passwords must use hidden interactive input and the configured strength policy; plaintext password command options are prohibited.

### DailyGiving Proof Handling Discipline
- Original charity donation receipt/proof images may be uploaded as the public proof media asset when the operator explicitly approves that original image for public use. A separate redacted derivative is not required for DailyGiving public proof.
- Raw private storage paths, redaction notes, backend-only ledger fields, tokens, private URLs, secrets, and system credentials must never be exposed by public APIs, frontend rendering, sitemap, llms, social distribution, or search submission.
- `proof_public_url` is the only public proof media field. It must point to the operator-approved public media URL for the original charity donation proof image and must be approved through the backend proof gate before a DailyGiving record can be public.
- Backend authority may not be bypassed by frontend hardcoded URLs or CMS fallback copy.
- DailyGiving records with `is_public=true` must remain `is_indexable=false` until a separate indexability gate explicitly approves sitemap and llms inclusion. Trust badges, official partnership/endorsement claims, and guaranteed-impact claims remain blocked unless separately source-backed and approved.

### Enneagram Authority V2 Media Boundary
- Big Five and Enneagram public content backed by `PersonalityPublicContentAsset` is permanently text-only. The database, CMS/import contract, public API, section content, and SEO projection must not support hero, inline, OG, Twitter, Markdown, or HTML images.
- Historical immutable revisions and release evidence may retain old media keys for audit only; they must never be projected to runtime or used to authorize a future media write.
- This boundary does not change MBTI product illustrations, test/results media, Articles, Topics, Landing Surfaces, or other CMS media resources.
