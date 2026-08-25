# Current Career compilation contract

Use source at the exact candidate SHA; these paths are the maintained contract:

- `backend/app/Console/Commands/CareerTenBlockCurrentPackageCompile.php`: dry-compile command and temp-output boundary.
- `backend/app/Domain/Career/Compilation/CareerTenBlockCompiler.php`: single-source normalization, lookup/evidence binding, omissions, and public-key guards.
- `backend/app/Domain/Career/Compilation/CareerTenBlockCurrentPackageCompiler.php`: full-cohort baseline retention, deterministic candidate assembly, receipts, and package diff.
- `backend/app/Domain/Career/Display/CareerDisplayAssetComponentContract.php`: exact current v4.3/28-component order, v4.2/26 read compatibility, and page-shape validation.
- `backend/app/Domain/Career/Display/CareerCurrentAuthorityPackage.php`: 1046-row/2092-locale package, canonical encoding, hashes, and public projection.
- `backend/tests/Unit/Domain/Career/Compilation/CareerTenBlockCurrentPackageCompilerTest.php` and `backend/tests/Unit/Domain/Career/Display/CareerDisplayAssetComponentContractTest.php`: focused executable coverage.

## Input contract

The compiler accepts a read-only source root, lookup JSON, evidence root, repository Current baseline, and an existing task temp output directory. It must reject missing files, symlinks where forbidden, slug/lookup mismatch, absent evidence, ambiguous schema profiles, unbound claims, and output outside the system temp boundary.

The compiler may normalize known source profiles into an internal representation. It must not edit the source files or invent facts, translations, links, claims, or evidence.

## Output contract

A dry compile produces candidate `assets.jsonl`, `manifest.json`, a full compile receipt, field-coverage report, and package-diff report. These artifacts are not publication receipts.

The candidate must preserve:

- the exact Current canonical slug set and en/zh-CN locale pairing;
- the current v4.3/28-component order and complete page fields, while retaining v4.2/26 read compatibility;
- allowed structured data only: `Occupation`, `BreadcrumbList`, and FAQ derived from visible content;
- canonical link identities and zero unresolved/variant output links;
- Current baseline copy for claims without exact active evidence binding;
- zero CMS, database, cache, discoverability, and search writes.

Do not duplicate the component list or package schema in this Skill. Read the classes above so future contract changes have one code authority.

## Sharded transition contract

The future Current shape is frozen by:

- `backend/docs/career/contracts/career-sharded-current.v1.json`
- `backend/docs/career/contracts/career-sharded-current-manifest.v1.schema.json`
- `backend/docs/career/contracts/career-sharded-current-record.v1.schema.json`
- `backend/docs/career/contracts/career-sharded-current-field-ownership.v1.json`

Until an explicitly scoped release installs that package, legacy `current/assets.jsonl` remains the only readable Current authority. `scripts/split_legacy_current.php` may create only an external temporary candidate and zero-write reports; it must not write `current/`, runtime, CMS, database, cache, sitemap, discoverability, or search state.
