# Career search-entry cache permission recovery

## Current state

The cache-only resume execute stopped before its first successful target write:

- failed execute: run `30557073777`, attempt `1`
- failure stage/category: `publish_cache_payload` / `cache_publish_failed`
- write state: `none`
- completed targets/batches: `0` / `0`
- source permission diagnostic: run `30564265848`, attempt `1`
- source status: `HOLD_DIAGNOSTIC_RUNTIME_CACHE_UNAVAILABLE`

The source diagnostic proved that the active release, 50-slug manifest, 100-URL
public snapshot, residual target set, and failed-execute baseline were unchanged.
It did not prove a repairable permission set: its deploy-runner `find` completed,
but the corresponding `www-data` tree scan did not complete within 15 seconds.

The existing shared-permissions manifest verifies
`backend/storage/framework/cache`, while Laravel's file store writes below
`backend/storage/framework/cache/data/<first-hash>/<second-hash>/<key-hash>`.
Checking only the parent directory cannot detect mixed owners or modes already
present in the two hash-directory levels or exact stable pointer files.

## One bounded read-only diagnostic

`Career Search Entry Cache Permission Diagnostic` is the only next production
read. It requires one exact operator approval and binds:

- exact latest `main` control-plane SHA;
- unchanged active release SHA and release name;
- the immutable successful source diagnostic run, receipt digest, artifact
  digest, and diagnostic state;
- the exact manifest, preflight state, residual target set, and observed
  payload-set hashes.

The workflow streams the checked-in probe to PHP over SSH. It creates no remote
file. The same probe runs once as the deploy runner and once as `www-data`.

The probe performs no Laravel bootstrap and no cache read API call, because both
could mutate expired cache state. It reads only filesystem metadata for:

- the fixed `storage -> framework -> cache -> data` chain;
- at most 256 first-level and 65,536 second-level Laravel hash directories;
- the existing files, if any, for 400 deterministic stable Career keys across
  the 50 slugs and two locales (active, LKG, negative, and legacy).

Artifacts contain only aggregate counts, booleans, code/artifact hashes, and a
deterministic opaque repair-candidate set hash. They do not contain a slug,
locale-target mapping, cache key, raw path, host, user, response body, or
exception text.

## Decision matrix

| Diagnostic status | Meaning | Next action |
| --- | --- | --- |
| `HOLD_PERMISSION_DIAGNOSTIC_INCOMPLETE` | Identity, topology, manifest, scan bound, or unexpected-entry condition prevented a complete decision. | Diagnose repository/control failure only; do not write production or retry cache refresh. |
| `PASS_PERMISSION_REPAIR_REQUIRED_FIXED_CACHE_CHAIN` | One or both identities cannot safely use the fixed cache chain, or its owner/group/setgid policy drifted. | Request one exact production permission-write authorization for the four fixed cache-chain directories only. |
| `PASS_PERMISSION_REPAIR_REQUIRED_BOUNDED_CACHE_TREE` | The fixed chain is usable, but an attested hash directory or exact stable-key file has capability or policy drift. | Request one exact production permission-write authorization bound to both identity-specific repair-set hashes and counts. |
| `PASS_PERMISSION_STATE_COMPLETE_NO_REPAIR` | Both identities can use the bounded cache tree and stable files with the expected shared policy. | Do not change permissions; investigate the configured cache store/runtime failure as a new read-only scope. |

## Observed production permission state

The one authorized read-only diagnostic completed as run `30576928176`, attempt
`1`:

- receipt SHA256:
  `278757da6d99a43fe6d1e80ea4e29b3c12ea67273f7f0540a11592212a4d4218`;
- GitHub artifact digest:
  `1624052319798ad7b938ac50c000d546db6c1ac1fd13169beb8b6e640e1abd2b`;
- status: `PASS_PERMISSION_REPAIR_REQUIRED_BOUNDED_CACHE_TREE`;
- repair scope: `attested_hash_directories_and_exact_stable_key_files`;
- fixed cache chain: four directories, zero capability/policy failures for both
  identities;
- hash tree: 256 first-level plus 9,176 second-level directories; all 9,432
  have shared-policy drift and are not writable by the deploy runner;
- stable Career files: 100 existing files; all 100 have owner/group drift and
  are not writable by the deploy runner;
- exact repair set: 9,532 nodes, with identical deploy-runner and `www-data`
  set SHA
  `d7d4911bdfaccdb26117e48c3608f87a2a96e66977fc8f1800c92ee7ff4edd54`;
- every server/cache/database/CMS/publication/indexability/queue/sitemap/llms/
  Search Channel/URL submission/deploy/rollback write counter remained zero.

## Exact production permission repair

`Career Search Entry Cache Permission Repair` is a separate, one-time,
exact-authorized write control. Merging it does not authorize production
execution.

The control:

1. bind the exact successful permission diagnostic run/attempt, receipt digest,
   active release, manifest, and both repair-candidate set hashes/counts;
2. re-run the same read-only probe immediately before mutation and require an
   exact repair-set match;
3. mutate only the attested directories/files:
   owner = deploy user, group = `www-data`, directory mode = `2775`, file mode =
   `0664`;
4. use individual PHP `chown`, `chgrp`, and `chmod` calls, never recursive
   `chown`, recursive `chmod`,
   wildcard expansion, broad `find -exec`, deploy, symlink activation, cache
   publication, rollback, CMS/database write, queue, sitemap, llms, Search
   Channel, or URL submission;
5. bind each target's device, inode, owner, group, and mode immediately before
   its metadata write and fail closed on target drift;
6. hash the 100 existing stable cache file payloads before and after metadata
   mutation and require the aggregate to remain identical;
7. re-run both identity probes after mutation and require zero repair
   candidates before emitting `PASS_PERMISSION_REPAIR_VERIFIED`.

If the fixed chain alone drifted, the write scope is exactly the four cache-chain
directories. If the bounded tree drifted, the write scope is exactly the
pre-attested set reconstructed by the same probe; the receipt never exposes its
raw paths.

### Pre-write drift recovery

Run `30585526098`, attempt `1`, passed eligibility but failed during the
immediate dual-identity re-attestation. Its immutable receipt SHA is
`c8eda9c16ac765642c2ca88961bdde5a121ccdb0dbd03cbc6be8a181e268d7c2` and
artifact digest is
`71b238b5db9e1e5293233196e72be4103909f6b5ddfb0606fd971d093512a006`.
The apply step was skipped and every write counter, including
`permission_metadata_write_count`, remained zero.

The failure was a bounded runtime-growth event: the first-level count remained
256, the second-level count increased from 9,176 to 9,198, the stable-key file
count remained 100, and both identities reported the same 9,554-candidate set
SHA `0ad0272441088dbf22516e5727fbb632c9d656c074e7ff58763196276a223e17`.

The drift-recovery revision must bind that exact failed run, receipt, artifact,
baseline count, and baseline set. Immediately before apply it may accept only:

- the same healthy four-directory fixed chain;
- exactly 256 first-level hash directories;
- 9,198 through 9,454 well-formed second-level hash directories;
- exactly the same 100 existing stable-key files;
- one identical deploy-runner and `www-data` live repair set;
- at most 256 additional hash-directory candidates and no additional
  stable-key files.

The live count and opaque set hash are passed directly from that dual-identity
attestation into the root repair process. All per-target state checks, payload
hash invariance, zero-candidate post-probes, zero retry, and prohibited-operation
boundaries remain unchanged. The failed authorization cannot be retried or
reused; production execution requires a new phrase bound to the drift-recovery
control-plane SHA and failed-run evidence.

## Authorization checkpoints

The read-only checkpoint is complete. Two operator checkpoints remain:

1. one exact drift-recovery production permission-write authorization;
2. only after permission verification passes, one exact cache-only refresh
   authorization.

Repository design, tests, PR fixes, and repair-control design within these
boundaries do not require additional operator authorization.
