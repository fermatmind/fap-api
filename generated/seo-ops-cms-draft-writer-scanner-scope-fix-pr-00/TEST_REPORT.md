# Test Report

Task: SEO-OPS-CMS-DRAFT-WRITER-SCANNER-SCOPE-FIX-PR-00

## Commands Run

```bash
cd /Users/rainie/Desktop/GitHub/fap-api/backend
php artisan test tests/Feature/Console/ArticleImportSeoContentPackageDraftCommandTest.php
```

Result: PASS

- 16 tests passed
- 130 assertions

```bash
cd /Users/rainie/Desktop/GitHub/fap-api/backend
php artisan test tests/Feature/Console/ArticleImportEditorialPackageCommandTest.php tests/Feature/Cms/ArticleMultiTestGraphEdgesTest.php
```

Result: PASS

- 14 tests passed
- 275 assertions

```bash
cd /Users/rainie/Desktop/GitHub/fap-api/backend
./vendor/bin/pint app/Services/Cms/SeoContentPackage/SeoContentPackageDraftImporter.php tests/Feature/Console/ArticleImportSeoContentPackageDraftCommandTest.php
```

Result: PASS

- 2 files formatted/checked

```bash
cd /Users/rainie/Desktop/GitHub/fap-api/backend
php artisan route:list
```

Result: PASS

- 218 routes listed

```bash
cd /Users/rainie/Desktop/GitHub/fap-api
git diff --check
```

Result: PASS

## Real Package Local Dry-Run Probe

Command attempted locally with `--dry-run --json` against:

`/Users/rainie/Desktop/GitHub/fap-web/generated/seo-ops-new-bilingual-article-pair-package-fix-and-rerun-00/source-package`

Result: BLOCKED_BY_LOCAL_DB_AUTH

The command reached local existing-article lookup and failed because this local environment cannot authenticate to MySQL `fap_api` as `root` with no password. No CMS write was attempted. This is the known local DB authority limitation and is not evidence of a scanner-scope failure.

## Regression Coverage Added

- Valid package with `ROUTE_ALIAS_CONTRACT.json` containing old Big Five alias key passes dry-run.
- Old Big Five path in page body fails.
- Old Big Five path in page frontmatter active link fails.
- Old Big Five path in CMS import CTA target fails.
- Old Big Five alias key with canonical value passes.
- Old Big Five alias value fails.
- Private routes in `PRIVATE_URL_GUARD.json` forbidden fields pass.
- Private routes in page body fail.
- Sensitive keys in `DYNAMIC_CTA_CONTRACT.json` `forbidden_tracking_params` pass.
- Sensitive keys in `allowed_tracking_params` fail.
- Review text in `review/claim_gate.md` is not treated as article body.
- `__CMS_MEDIA_LIBRARY_PLACEHOLDER__` remains blocked.
