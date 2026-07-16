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
- Big Five ZH6 readiness may use `solo_operator` only for exact `admin_user:1`, explicit self-review, immutable cohort/package hashes, and complete external-human audit evidence. It does not relax global reviewer separation. Exactly one authority-complete Media Library asset with both Hub hero and OG variants is required before working-revision preparation; zero or multiple candidates fail closed.
- A readiness package, working pointer, HTTP success, or generated SHA never proves publication or authorizes promotion. Personality promotion remains separately gated by exact revision-bound review, authenticated preview approval, live fingerprints, rollback readiness, and explicit controlled authorization.
- Exact exception: the operator-authorized `personality:big-five-authority-v2-zh-content-publish` command may publish only the fixed 112-row `zh-CN` Big Five Authority V2 content cohort. For this command only, author/reviewer/source/date/revision-fingerprint/preview/rollback completeness and hero/OG/inline media are non-blocking; every row must record `media_deferred_by_operator`, Media Library and frontend fallback writes remain zero, English rows remain untouched, and exact-count transactional public readback is mandatory. This exception does not alter any other publishing workflow.
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
- The current 116-page Enneagram Authority V2 release maps every authority asset to exactly `hero=null`, `inline=[]`, and `og=null`.
- This release must not upload media, write Media Library records, add frontend media fallback, or hardcode public image URLs.
- Any future Enneagram authority image requires a separate backend-authoritative, rights-reviewed PR before it can enter a release package.
