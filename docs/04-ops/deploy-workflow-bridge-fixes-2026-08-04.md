# Deploy Workflow Bridge Fixes — 2026-08-04

## Merged Fixes (on main)

### 1. Runtime-46 Bridge (Commit: `353e02258`, `aaae18ed2`, `9ede2ac8c`, `3be54cbea`)
- Added ancestry-based bridge: when production has advanced past `bc0ed833b` (Runtime-46 audited SHA), skip exact diff check and enforce blob-level identity on audited paths
- Previously `CURRENT_PRODUCTION_SHA == bc0ed833b` was a hard equality check; now accepts descendants
- Skip bridge SHA fetch in shallow clone (bridge is always an ancestor via ancestry check)

### 2. INDEX-52 Bridge (Commit: `ff8e9b5d2`)
- Same ancestry-based bridge for INDEX-52 audited candidate SHA
- Production descendants accepted without exact equality check

### 3. Shallow Clone Fix (Commit: `353e02258`)
- `git fetch --no-tags origin $AUDITED_RUNTIME46_PRODUCTION_SHA $AUDITED_IDX52_CANDIDATE_SHA` before ancestry checks
- Fixes "cannot resolve the audited Runtime 46 bridge" in shallow clone (fetch-depth: 1)

### 4. Staging-Equivalence Content Assets (Commit: `PR #3525`)
- Added `backend/content_assets/*` to staging-equivalence audited path list for code_only deployments

### 5. Content Promotion Policy SHA (Commit: `PR #3528`)
- Fixed `jq -cS` trailing newline in policy SHA computation; now uses `tr -d '\n'` to match PHP `json_encode(json_decode(...))` output
- Input validation and PHP execution now produce the same `cdb605...` SHA

### 6. CareerCmsPromotionAuthority Fixes (Commits: `03d7e70f4`, `ca7f33c41`, `0a1ae8ec7`, `68f06d4b4`, `08180438c`, `54752b027`, `e6e0b31b7`, `7050c7c6d`, `d0ab9c787`)
- Accept `W3/W3-CAREER-GUIDES` in `kind()` match
- `findTarget()` zh-CN fallback for first-import preflight
- Per-occurrence context-aware claim boundary scan (negated/disclaimer context exempted)

### 7. Career Guides Package (Commit: `f9b835c03`, `c1f2d4448`)
- Payloads moved to package root for adapter access
- `manifest.json` built matching adapter schema
- `assets.json` built from `source_ledger.json` with 20 guide entries

## Known Remaining Issue

~~**Deploy workflow eligibility validation**: `deploy-production.yml` step `Validate manual exact-SHA approval and staging evidence` rejects all dispatch methods. `${{ inputs.expected_release_sha }}` appears empty in the `run:` block, causing all three format checks (SHA, release_id, deploy_mode) to fail immediately.~~

~~Root cause: likely GitHub Actions `workflow_dispatch` input resolution bug or `DEPLOY_SHA` / `RELEASE_ID` env var scoping issue in the composite `run:` block.~~

**Status (2026-08-05)**: Eligibility check now passes with correct inputs (verified in deploy run 30968920271). The issue was likely resolved by `fix(deploy): change deploy_mode from type:choice to type:string` (commit `27cbcf7cf`).

### New Issue: deploy:setup path detection

**Deployer deploy:setup task fails on GitHub Actions**: The `deploy:setup` task detects `current` as a directory (not symlink) at the deploy path, causing the Deployer to abort. Root cause likely `secrets.PRODUCTION_DEPLOY_PATH` pointing to a wrong path on the GitHub Actions runner.

**Fix applied (2026-08-05)**: Added a `Verify production deploy path` step before the Deployer runs that:
1. Prints the resolved deploy path for diagnostics
2. Verifies the path exists on production
3. Verifies `current` is a symlink (not a directory)
4. Fails early with a clear error message if the path is wrong

This catches the issue before the Deployer runs, providing actionable diagnostics instead of a cryptic `deploy:setup` error.

**Workaround**: Use local `dep deploy` with `DEPLOY_IDENTITY_FILE_PROD=~/.ssh/fap_api_gha` (the production SSH key). Content-only changes can be deployed via manual `rsync` + `REVISION` update.
