---
name: ci-verify
description: Run the CI verification pipeline for fap-api: pint code style, PHPUnit tests, and the ci_verify_mbti master chain. Used before pushing commits or opening PRs.
---

# CI Verification Pipeline

## Purpose
Run the local CI verification pipeline to ensure code passes style checks, unit/feature tests, and the MBTI content pack integrity chain before pushing commits or opening PRs. This is the same pipeline that runs as required GitHub Actions checks.

## When to Use
- Before committing and pushing any backend change
- Before opening a PR
- When fixing CI failures reported on a PR
- When validating that local changes don't break required checks

## When Not to Use
- For frontend-only changes (fap-web) — use the fap-web CI skill
- When MySQL is not running locally — tests use SQLite by default but some commands require MySQL
- For content pack validation only — use `make selfcheck` instead

## Hard Invariants
- **Do not** push to main or open a PR if any required check fails.
- **Do not** skip `ci_verify_mbti.sh` — it is the master chain.
- **Do not** modify tests to make them pass; fix the source code.
- **Do not** change CI workflow files without explicit approval.
- **Do not** suppress failing tests with `@group` or `--filter` exclusions.

## Standard Workflow

### Step 1 — Code Style
```bash
cd backend
./vendor/bin/pint --test
```

Fix any issues with: `./vendor/bin/pint`

### Step 2 — Unit & Feature Tests
```bash
# Run all tests
php artisan test

# Or run specific test suites
php artisan test --filter=CanonicalBatchPromotionExecutor
php artisan test --filter=CareerExecuteCanonicalRolloutBatch
```

### Step 3 — CI Master Chain
```bash
cd backend
bash scripts/ci_verify_mbti.sh
```

This runs:
- Content pack validation (MBTI + BigFive)
- Report generation verification
- Events funnel verification
- Runtime freeze classifier (checks changed files)

### Step 4 — Additional Checks
```bash
cd backend
php artisan route:list --path=career
php artisan migrate:status
git diff --check
```

## Runtime Freeze Classifier
When the `ci_verify_mbti.sh` pipeline detects changed files under `backend/app/`, these must be in allowlists:
- `isCareerConsoleCommandFile` — regex `Career[A-Za-z0-9_]*\.php$`
- `isCareerRuntimePublishProjectionFile` — explicit list of Career Expansion/Publish files

Add new Career files to the appropriate allowlist in:
`tests/Unit/Services/BigFive/ResultPageV2/BigFiveResultPageV2CoreBodyPreviewTest.php`

## Acceptance Commands
```bash
cd backend
./vendor/bin/pint --test
php artisan test
bash scripts/ci_verify_mbti.sh
php artisan route:list --path=career
git diff --check
```

## Output Contract
All commands must return exit code 0. Failures must include:
- Which test/check failed
- The failing file and line number
- The expected vs actual values

## Stop Conditions
- Pint reports style issues
- Any PHPUnit test fails
- `ci_verify_mbti.sh` returns non-zero exit code
- `git diff --check` reports whitespace errors
- Runtime freeze classifier detects changed files not in allowlists
