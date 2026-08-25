# Current Career compilation contract

Use source at the exact candidate SHA; these paths are the maintained contract:

- `backend/app/Console/Commands/CareerTenBlockCurrentPackageCompile.php`: dry-compile command and temp-output boundary.
- `backend/app/Domain/Career/Compilation/CareerTenBlockCompiler.php`: single-source normalization, lookup/evidence binding, omissions, and public-key guards.
- `backend/app/Domain/Career/Compilation/CareerTenBlockCurrentPackageCompiler.php`: full-cohort baseline retention, deterministic candidate assembly, receipts, and package diff.
- `backend/app/Domain/Career/Display/CareerDisplayAssetComponentContract.php`: exact current v4.3/28-component order, v4.2/26 read compatibility, and page-shape validation.
- `backend/app/Domain/Career/Display/CareerCurrentAuthorityPackage.php`: 1046-row/2092-locale package, canonical encoding, hashes, and public projection.
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

## Sharded transition contract

The future Current shape is frozen by:

- `backend/docs/career/contracts/career-sharded-current.v1.json`
- `backend/docs/career/contracts/career-sharded-current-manifest.v1.schema.json`
- `backend/docs/career/contracts/career-sharded-current-record.v1.schema.json`
- `backend/docs/career/contracts/career-sharded-current-field-ownership.v1.json`

The sharded package is installed as Current authority: `current/manifest.json` binds the fixed 640 shards, coverage, module completeness, registries, and aggregate hash. The committed `current/assets.jsonl` is temporarily retained only as a compiler-owned legacy LKG compatibility projection; it is not a content input, may not be edited by hand, and is deliberately excluded from the sharded manifest. `scripts/split_legacy_current.php` remains a transition/recovery generator whose normal output is confined to an external temporary directory; it does not grant runtime, CMS, database, cache, sitemap, discoverability, or search authority.

`scripts/assemble_sharded_current.php` consumes only a validated shard package to build its row projection. The committed legacy row is an equality oracle after assembly, never a source or fallback. Assembly fails on manifest/shard/line drift, missing or unknown module fields, incomplete locale/module/component coverage, duplicate bindings, cross-module claim conflicts, unresolved source ordering, FAQ derivation conflict, component-order drift, or any mismatch across the 2092 locale projections. Temporary assembly output grants no publication, runtime, deploy, or search authority.
