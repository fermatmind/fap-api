# AGENTS.md — Repository + Codex Working Contract (MUST FOLLOW)

> This file is binding for any agent/Codex work in this repo.

## A) Hard Rules (User Contract)

### Rule 1 — Small loop first
- Advance exactly ONE demo-able small loop per run/commit.
  Example: `send_code → verify → /me/attempts`
- Do NOT mix multiple loops into one submission.
- Do NOT break `backend/scripts/ci_verify_mbti.sh` core chain:
  - content pack / report / events funnel must stay green.
- Draft PR exception: if `backend/scripts/ci_verify_mbti.sh` is already failing on paths clearly unrelated to the current declared PR scope, and the user explicitly asks to proceed, Codex may open a draft PR after:
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

### PR Train Manifest Discipline
- If a requested PR train item is missing from `docs/codex/pr-train.yaml`, stop and report the gap unless the user explicitly authorizes updating the train manifest and state ledger.
- This stop rule applies only when the user requested a PR-train item. It must not block an explicitly requested ad-hoc PR whose scope does not modify PR-train metadata.
- Only PR-train work requires a PR id and PR-train metadata. Ordinary scoped PRs may be opened without a train id and must not touch PR-train metadata unless explicitly requested.
- If the user explicitly authorizes proceeding with a missing PR train item, Codex may add the missing `docs/codex/pr-train.yaml` and `docs/codex/pr-train-state.json` entries first, then continue under the declared scope.
- Never invent a PR id or scope that is not either:
  - already present in the manifest, or
  - explicitly provided by the user.
- For scan/planning-only tasks, Codex must anticipate PR-train execution. If it proposes a future PR that is not already in `docs/codex/pr-train.yaml`, the scan output must include:
  - proposed PR train id
  - proposed PR title
  - proposed scope and files likely touched
  - required local checks
  - dependency assumptions
  - exact manifest/state entries that would need user authorization before implementation
  - a follow-up execution prompt that explicitly asks for manifest/state authorization
- Scan/planning-only tasks must not modify `docs/codex/pr-train.yaml` or `docs/codex/pr-train-state.json` unless the user explicitly authorizes manifest/state updates in that same turn.
- If the user provides a concrete `/goal` or equivalent execution request with an explicit PR id, title, and scope, Codex may treat those as user-provided manifest details. If the id is missing from the manifest, Codex may add the manifest/state entry before implementation only when the user also explicitly authorizes updating both files.
- After merging a PR-train PR, close its state as `merged` in the same workflow whenever possible.
- If branch protection prevents direct ledger closeout, use one ledger-only follow-up PR with no new train id.

### Controlled CMS Publish Discipline
- Controlled Codex-assisted article publish is permitted only through the backend `articles:publish-controlled` command after exact user confirmation, successful preflight, explicit boundary-context claim-warning acknowledgement when needed, and audit logging.
- Codex must not use generic CMS UI publish clicks, uncontrolled API publish endpoints, or production content mutation outside that controlled SOP.
