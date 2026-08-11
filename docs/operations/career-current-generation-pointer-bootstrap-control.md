# Career current-generation pointer bootstrap control

This control bootstraps the already-materialized Career 342/30 authority into the generation-pointer contract. It is a manual production control, not a deployment or publication path. Merging the workflow does not run either phase.

## Fixed authority

The control accepts only the frozen current state:

- projection SHA-256: `397f2a4ec284e9c0a6cd610447541ad4773fa7a7f3045008fab5efb334ec85c6`
- ledger SHA-256: `975b311bb346a090f1add678d5a6d9f1be230f87b223e2c3c829f4c7fd7aac6e`
- slug-set SHA-256: `8b328b2e002875a9f92d4c406981f3c3724f066ee817d2d5bd1a61915e1eddf5`
- locale-row-set SHA-256: `607926991fa51c74d6d6c9606ab3b7f8f35918996006a39c68963c16765d5697`
- 342 slugs and 684 locale rows
- 30 published slugs and 60 published locale rows
- freeze contract payload SHA-256: `23c86542bbe9301f60b101d067ad6c2382982041042eaf4a9faa61886c164f88`

The bootstrap generation identity is `career-current-342-30-bootstrap-v1`. Its `legacy_exact_bytes_v1` format is permitted only with null prior lineage, no LKG and no revocation receipt. The source projection and ledger remain byte-identical.

## Phase 1: SELECT-only preflight

Dispatch `.github/workflows/career-current-generation-pointer-bootstrap-production-ops.yml` from exact latest `main` with `mode=preflight`, the exact active production SHA and release, and all apply-only inputs empty.

The phase:

- proves latest-main control-plane identity and active-release ancestry;
- revalidates the immutable freeze contract;
- proves the current symlink, REVISION, absent deploy lock and absent deployment/migration process;
- selects exactly one projection and one ledger by fixed byte hash under bounded storage families, never by mtime;
- validates kind/version/source, full two-locale structure, exact sets and 342/684/30/60 counts;
- proves there is no active or inactive generation pointer conflict;
- emits only hashes, counts, booleans and fixed identities in an always-uploaded receipt;
- performs zero remote writes.

Record the successful run id/attempt, downloaded receipt SHA-256, projection path SHA-256 and ledger path SHA-256. Raw source paths and production topology are not present in the receipt.

## Phase 2: receipt-bound apply

Apply requires a still-latest control-plane SHA and the exact successful preflight from the same SHA, active revision and release. Supply `mode=apply`, the preflight run id/attempt, receipt SHA-256, both path hashes and the exact approval phrase reconstructed by the workflow.

Immediately before each committed rename, the streamed runner revalidates the active release, REVISION, deploy lock/process state, both source byte hashes and absence of the root active pointer. It then:

1. creates an exclusive no-clobber candidate for the immutable generation pointer;
2. performs full candidate hash readback and atomically renames it to `generation-pointer.json`;
3. repeats the exclusive candidate, full hash readback and atomic rename for `active-generation.json`;
4. proves both committed pointer documents have the exact same SHA-256.

The pointer binds the preflight receipt as its activation receipt, sets previous generation to null, keeps rollback ineligible, and records all discoverability mutations as false.

The apply does not modify projection or ledger bytes and cannot deploy, migrate, restart, write DB/CMS/cache, publish content, warm caches, change sitemap/llms or submit Search Channel actions. Automatic retry, candidate cleanup and rollback are disabled. A failed or transport-ambiguous apply uploads a sanitized receipt with `write_state=indeterminate` or the last known committed phase; it must not be reported as zero-write success.

## Failure handling

Do not rerun automatically. Preserve every committed rename. Inspect the sanitized receipt and obtain a new exact preflight before any separately authorized recovery. Unknown status, source duplication, symlink, hash/count/set drift, existing pointer, release drift, deploy activity or malformed receipt all fail closed.
