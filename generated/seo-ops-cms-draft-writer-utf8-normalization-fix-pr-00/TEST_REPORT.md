# Test Report

## Commands Run

```bash
php artisan test tests/Feature/Console/ArticleImportSeoContentPackageDraftCommandTest.php
php artisan test tests/Unit/Services/Cms/SeoContentPackageJsonNormalizerTest.php
php artisan test tests/Feature/Console/ArticleImportEditorialPackageCommandTest.php
./vendor/bin/pint app/Services/Cms/ArticleBodyHeadingGuard.php app/Services/Cms/SeoContentPackage/SeoContentPackageDraftImporter.php app/Services/Cms/SeoContentPackage/SeoContentPackageJsonNormalizer.php app/Console/Commands/ArticleImportSeoContentPackageDraft.php tests/Feature/Console/ArticleImportSeoContentPackageDraftCommandTest.php tests/Unit/Services/Cms/SeoContentPackageJsonNormalizerTest.php
php artisan list articles | rg "import-seo-content-package-draft"
git diff --check
```

## Results

- `ArticleImportSeoContentPackageDraftCommandTest`: 20 passed, 149 assertions.
- `SeoContentPackageJsonNormalizerTest`: 4 passed, 12 assertions.
- `ArticleImportEditorialPackageCommandTest`: 11 passed, 259 assertions.
- Pint: passed for changed PHP files.
- Command registration: `articles:import-seo-content-package-draft` is registered.
- `git diff --check`: passed.

## Not Run

- No production import.
- No production dry-run.
- No production DB connection.
- No CMS draft creation.
