# Current Career compilation contract

Use source at the exact candidate SHA; these paths are the maintained contract:

- `backend/app/Console/Commands/CareerTenBlockCurrentPackageCompile.php`: dry-compile command and temp-output boundary.
- `backend/app/Domain/Career/Compilation/CareerTenBlockCompiler.php`: single-source normalization, lookup/evidence binding, omissions, and public-key guards.
- `backend/app/Domain/Career/Compilation/CareerTenBlockCurrentPackageCompiler.php`: full-cohort baseline retention, deterministic candidate assembly, receipts, and package diff.
- `backend/app/Domain/Career/Display/CareerDisplayAssetComponentContract.php`: exact current v4.3/28-component order, v4.2/26 read compatibility, and page-shape validation.
- `backend/app/Domain/Career/Display/CareerContentV3AuthorityPackage.php`: 1046-directory/2092-file package, canonical encoding, hashes, and fail-closed inventory validation.
- `backend/app/Domain/Career/Display/CareerContentV3CanonicalReader.php`: shared API, publisher, cache, and snapshot hydration boundary.
- `backend/tests/Unit/Domain/Career/Compilation/CareerTenBlockCurrentPackageCompilerTest.php` and `backend/tests/Unit/Domain/Career/Display/CareerDisplayAssetComponentContractTest.php`: focused executable coverage.
- `.agents/skills/fap-api-career-canonical-builder/scripts/split_legacy_current.php`: deterministic read-only legacy-to-module sharder.
- `.agents/skills/fap-api-career-canonical-builder/scripts/assemble_sharded_current.php`: full module-only assembler and legacy equivalence oracle boundary.
- `backend/tests/Unit/Domain/Career/Compilation/CareerShardedCurrentAssemblerTest.php`: full-cohort equivalence and fail-closed assembly coverage.

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

## Per-page Current contract

The installed Current shape is frozen by:

- `backend/docs/career/contracts/career-content-v3-current.v1.json`
- `backend/docs/career/contracts/career-content-v3-current-manifest.v1.schema.json`
- `backend/docs/career/contracts/career-content-v3-current-field-ownership.v1.json`

The per-page package is the only installed Current authority: `current/manifest.json` binds exactly 1046 canonical slug directories and 2092 locale files, their bytes and hashes, coverage, source registry, semantic set, compatibility projections, and aggregate hash. There is no committed flat or sharded projection. Historical split/assembly scripts are transition evidence and are not active Current inputs.

Active reading consumes only a fully validated per-page package. It fails closed on root manifest drift, missing or undeclared files, incomplete locale pairing, duplicate identities, traversal, symlinks, JSON/locale/hash drift, duplicate block/item IDs, unknown primitives, or invalid sources and links. A database presentation_v1/v2 materialization is usable only when the same canonical reader verifies its manifest-bound compatibility hash.
