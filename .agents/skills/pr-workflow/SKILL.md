---
name: pr-workflow
description: Standard PR workflow for fap-api following the AGENTS.md fixed change order. Covers route registration, migrations, middleware, controller/services, and CI verification.
---

# PR Workflow

## Purpose
Create backend changes following the AGENTS.md mandatory fixed change order: routes → migrations → middleware → controllers/services → scripts/CI. Every step requires verification before proceeding. This is the primary skill for any fap-api backend PR.

## When to Use
- Adding a new API endpoint
- Adding a database migration
- Modifying auth middleware
- Creating or changing controllers/services
- Any PR that touches `backend/routes/api.php`, `backend/database/migrations/`, `backend/app/`

## When Not to Use
- For content pack changes only — use `validate-content-pack` instead
- For CI-only changes — use `ci-verify` instead
- For career canonical rollout operations — use `canonical-rollout` instead
- For frontend fap-web changes — use fap-web skills

## Hard Invariants
- **Do not** skip the fixed change order: routes → migrations → middleware → controllers/services → scripts/CI
- **Do not** mix multiple independent features into one PR.
- **Do not** break `backend/scripts/ci_verify_mbti.sh`.
- **Do not** merge with failing required checks.
- **Do not** change `backend/routes/api.php` without verifying with `php artisan route:list`.

## Standard Workflow

### Step 1 — Route Registration
```bash
# Add route in backend/routes/api.php FIRST
cd backend
php artisan route:list --path=api --except-vendor

# Verify the new route appears
php artisan route:list --path=api --except-vendor | grep <endpoint_name>
```

### Step 2 — Database Migration (if needed)
```bash
cd backend
php artisan make:migration <migration_name>
# Edit migration file

php artisan migrate
php artisan migrate:status | grep <migration_name>
```

### Step 3 — Auth Middleware (if needed)
- Must implement DB lookup + inject `fm_user_id`
- File: `backend/app/Http/Middleware/FmTokenAuth.php`

### Step 4 — Controller / Service Layer
```bash
# Create controller
cd backend
php artisan make:controller API/V0_3/<ControllerName>

# Test with curl
curl -s http://localhost:8000/api/v0.3/<endpoint>
```

### Step 5 — Scripts / CI (last, only if needed)
- Add acceptance/verify scripts matching existing naming conventions
- Do not modify CI workflows without explicit approval

### Step 6 — Full CI Verification
```bash
cd backend
./vendor/bin/pint --test
php artisan test
bash scripts/ci_verify_mbti.sh
php artisan route:list --path=career
git diff --check
```

## Mandatory Response Format
Every output must include:
1. **Changed files list** (Added vs Modified)
2. **Exact insertion position / replacement range** (no vague instructions)
3. **Acceptance commands**:
   - `php artisan route:list`
   - `php artisan migrate`
   - `curl` examples
   - `bash backend/scripts/ci_verify_mbti.sh`

## Acceptance Commands
```bash
cd backend
php artisan route:list --path=api --except-vendor
php artisan migrate:status
php artisan test --filter=<RelatedTest>
bash scripts/ci_verify_mbti.sh
./vendor/bin/pint --test
git diff --check
```

## Output Contract
- All route registrations must appear in `route:list` output
- All migrations must appear in `migrate:status` output
- `ci_verify_mbti.sh` must return exit code 0
- `php artisan test` must pass all relevant tests
- `pint --test` must return exit code 0

## Stop Conditions
- Route not appearing in `route:list` output
- Migration not appearing in `migrate:status`
- Any PHPUnit test failure
- `ci_verify_mbti.sh` non-zero exit code
- Pint style violations
- `git diff --check` whitespace errors
