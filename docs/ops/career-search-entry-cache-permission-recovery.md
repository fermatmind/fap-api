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

## Future production permission repair

No production write workflow is introduced by this repository change. A write
control is designed only after the diagnostic identifies one exact repair
class, and it requires a separate explicit production write authorization.

The future repair control must:

1. bind the exact successful permission diagnostic run/attempt, receipt digest,
   active release, manifest, and both repair-candidate set hashes/counts;
2. re-run the same read-only probe immediately before mutation and require an
   exact repair-set match;
3. mutate only the attested directories/files:
   owner = deploy user, group = `www-data`, directory mode = `2775`, file mode =
   `0664`;
4. use explicit individual targets, never recursive `chown`, recursive `chmod`,
   wildcard expansion, broad `find -exec`, deploy, symlink activation, cache
   publication, rollback, CMS/database write, queue, sitemap, llms, Search
   Channel, or URL submission;
5. re-run both identity probes after mutation and require zero repair
   candidates before emitting a success receipt.

If the fixed chain alone drifted, the write scope is exactly the four cache-chain
directories. If the bounded tree drifted, the write scope is exactly the
pre-attested set reconstructed by the same probe; the receipt never exposes its
raw paths.

## Authorization checkpoints

There are three operator checkpoints from this point:

1. one read-only production permission diagnostic authorization after this
   control is merged;
2. only if the diagnostic proves a repair is required, one exact production
   permission-write authorization;
3. only after permission verification passes, one exact cache-only refresh
   authorization.

Repository design, tests, PR fixes, and repair-control design within these
boundaries do not require additional operator authorization.
