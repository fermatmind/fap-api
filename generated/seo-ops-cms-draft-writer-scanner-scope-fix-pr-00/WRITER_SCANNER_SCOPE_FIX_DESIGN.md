# Writer Scanner Scope Fix Design

Task: SEO-OPS-CMS-DRAFT-WRITER-SCANNER-SCOPE-FIX-PR-00

Decision: GO_FOR_PR_REVIEW

## Problem

The draft-only SEO content package writer treated the entire Mode C package as one scan surface. That incorrectly blocked valid policy files, especially `contracts/ROUTE_ALIAS_CONTRACT.json`, where the legacy Big Five route is expected to appear as an alias key:

`/tests/big-five-personality-test` -> `/tests/big-five-personality-test-ocean-model`

## Fix

The importer now separates scan scope into two explicit preflight outputs:

- `active_surface_guard_scan`
- `contract_integrity_scan`

`active_surface_guard_scan` scans only data that can be written to article content, CMS fields, CTA/link targets, metadata, manifest page entries, and public canonical route surfaces.

`contract_integrity_scan` validates policy files with context-aware rules:

- `ROUTE_ALIAS_CONTRACT.json` may contain the old Big Five route only as an alias key.
- The old Big Five alias value must be `/tests/big-five-personality-test-ocean-model`.
- `PRIVATE_URL_GUARD.json` may contain private routes and sensitive query keys only inside forbidden guard fields.
- `DYNAMIC_CTA_CONTRACT.json` may contain sensitive tracking params only inside `forbidden_tracking_params`.
- Review files such as `review/claim_gate.md` are not treated as article body.

## Files Changed

Modified:

- `backend/app/Services/Cms/SeoContentPackage/SeoContentPackageDraftImporter.php`
- `backend/tests/Feature/Console/ArticleImportSeoContentPackageDraftCommandTest.php`

Added:

- `generated/seo-ops-cms-draft-writer-scanner-scope-fix-pr-00/WRITER_SCANNER_SCOPE_FIX_DESIGN.md`
- `generated/seo-ops-cms-draft-writer-scanner-scope-fix-pr-00/ACTIVE_SURFACE_VS_CONTRACT_SURFACE_MATRIX.md`
- `generated/seo-ops-cms-draft-writer-scanner-scope-fix-pr-00/TEST_REPORT.md`
- `generated/seo-ops-cms-draft-writer-scanner-scope-fix-pr-00/NEXT_DEPLOY_AND_DRY_RUN_INSTRUCTIONS.md`

## Out Of Scope

- No CMS writes.
- No draft creation.
- No publish/index/sitemap/llms/search-channel mutation.
- No production environment changes.
- No `fap-web` changes.
