---
name: validate-content-pack
description: Validate MBTI and Big Five content packages for correctness. Covers manifest validation, selfcheck, content store hot reload verification, and pack fallback checks.
---

# Validate Content Pack

## Purpose
Validate MBTI and Big Five content packages to ensure manifest integrity, content correctness, and store reliability. This skill covers manifest selfcheck, content store hot reload, and pack fallback validation.

## When to Use
- Adding or modifying content in `content_packages/`
- Updating manifest files
- Changing content pack assets or reports
- Before releasing a new content pack version
- Validating pack integrity after content changes

## When Not to Use
- For API endpoint changes — use `pr-workflow` instead
- For CI pipeline debugging — use `ci-verify` instead
- For frontend content changes (fap-web) — use fap-web skills

## Hard Invariants
- **Do not** skip manifest validation before committing.
- **Do not** modify `content_packages/default/` directly without validation.
- **Do not** change the manifest schema without updating validation scripts.
- **Do not** commit content changes that fail selfcheck.

## Standard Workflow

### Step 1 — Manifest Selfcheck
```bash
cd /Users/rainie/Desktop/GitHub/fap-api
make selfcheck MANIFEST=content_packages/default/CN_MAINLAND/zh-CN/MBTI-CN-v0.2.2/manifest.json
```

Expected: exit code 0, no errors in manifest structure.

### Step 2 — Content Store Verification
```bash
cd backend
bash scripts/verify_store_hot_reload_reads.sh
bash scripts/verify_store_hot_reload_highlights.sh
bash scripts/verify_store_hot_reload_overrides.sh
```

### Step 3 — Pack Fallback Validation
```bash
cd backend
bash scripts/verify_pack_fallback.sh
```

### Step 4 — Content Package Checks
```bash
cd backend
bash scripts/check_content_packages.sh
```

### Step 5 — Full Content Test
```bash
cd backend
composer test:content
```

## Acceptance Commands
```bash
make selfcheck MANIFEST=content_packages/default/CN_MAINLAND/zh-CN/MBTI-CN-v0.2.2/manifest.json
cd backend
bash scripts/check_content_packages.sh
composer test:content
```

## Output Contract
- `make selfcheck`: exit code 0 with validated manifest summary
- `check_content_packages.sh`: exit code 0 with pack counts
- `composer test:content`: all tests pass

## Stop Conditions
- Manifest selfcheck returns non-zero exit code
- Content store scripts report missing assets
- Pack fallback validation fails
- Content package check reports invalid packs
- Any content test failure
