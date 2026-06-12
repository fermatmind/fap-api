# Transaction Rollback Safety Review

## Transaction Boundary

`SeoContentPackageDraftImporter::importFromDirectory()` still wraps all article, revision, SEO meta, and import-audit writes inside one `DB::transaction`.

## Failure Behavior

If write-time JSON normalization or import log persistence fails, the transaction throws and rolls back. Draft-related rows are not partially retained.

## Regression Coverage

`ArticleImportSeoContentPackageDraftCommandTest::test_import_failure_rolls_back_all_draft_related_writes` forces `ArticleEditorialPackageImport` creation to fail after article and SEO rows would have been written. The test confirms:

- `articles` count remains 0
- `article_seo_metas` count remains 0
- `article_editorial_package_imports` count remains 0

## Safety Gates Preserved

The patch does not publish, index, add sitemap eligibility, add llms eligibility, dispatch content release follow-up, enqueue search channel work, or trigger production revalidation.
