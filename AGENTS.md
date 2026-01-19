# AGENTS.md — Repository + Codex Working Contract (MUST FOLLOW)

> This file is binding for any agent/Codex work in this repo.

## A) Hard Rules (User Contract)

### Rule 1 — Small loop first
- Advance exactly ONE demo-able small loop per run/commit.
  Example: `send_code → verify → /me/attempts`
- Do NOT mix multiple loops into one submission.
- Do NOT break `backend/scripts/ci_verify_mbti.sh` core chain:
  - content pack / report / events funnel must stay green.

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