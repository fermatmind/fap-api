# UTF-8 Normalization Fix Design

## Scope

Command: `articles:import-seo-content-package-draft`

Fixes the draft-only SEO content package writer path that failed during real import when JSON-cast audit fields, especially `ArticleEditorialPackageImport.heading_sequence_json`, contained malformed UTF-8.

## Root Cause

Two heading-processing paths used PCRE newline or heading capture patterns that could corrupt valid multibyte heading text before JSON persistence:

- `ArticleBodyHeadingGuard::downgradeMarkdownH1ToH2()`
- `SeoContentPackageDraftImporter::headingSequence()`

The importer then handed JSON-cast fields directly to Eloquent without a shared serialization preflight, so dry-run could pass while real database writes failed.

## Implementation

- Replaced multibyte-sensitive newline splitting with byte-safe string newline normalization in heading guard and heading sequence extraction.
- Added `SeoContentPackageJsonNormalizer` for recursive JSON-field serialization checks.
- Added dry-run and non-dry-run JSON serialization preflight for:
  - `article.cover_image_variants`
  - `article_seo_meta.schema_json`
  - `article_editorial_package_import.*_json`
- Added write-time normalization for the same JSON payloads before Eloquent JSON casts.
- Added `JSON_INVALID_UTF8_SUBSTITUTE` to command JSON output so diagnostics remain printable.

## User-Visible Content Policy

Article body Markdown, SEO title, meta description, and visible article content are not normalized or rewritten by this patch. Normalization is scoped to JSON audit, metadata, and heading sequence fields used by the import writer.
