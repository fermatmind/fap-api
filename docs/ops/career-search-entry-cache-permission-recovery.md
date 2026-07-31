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

The permission-repair checkpoint is complete:

- successful repair: run `30586605927`, attempt `1`;
- receipt SHA:
  `346adeed33a71db9b82d2513a9728f45980c8a45724adf1df3f788a1e23dbc12`;
- artifact digest:
  `c2256ef80b6b69958747137b9ef2e46a476b792d81192772dd62577468c343c4`;
- status: `PASS_PERMISSION_REPAIR_VERIFIED`;
- 9,554 permission metadata targets changed, including 9,454 directories and
  100 exact stable-key files;
- pre/post stable-file payload aggregate:
  `a243cb7990bf0441942125cd5937aa12e205a51cc10027530462d1c0bb707b56`;
- deploy-runner and `www-data` post-probes both reported zero candidates;
- cache payload, database, CMS, publication, indexability, queue, sitemap,
  llms, Search Channel, URL submission, deploy, rollback, and retry counts
  remained zero.

The first permission-repaired execute authorization reached eligibility run
`30587810948`, attempt `1`, but failed before `operate` because the control
incorrectly compared historical preflight run `30556658048` with the current
control-plane SHA. That run produced zero artifacts and performed zero
production writes. There is one exact cache-only refresh operator checkpoint
remaining: the lineage-repaired resume authorization.

## Permission-repaired cache-only resume

The permission-repaired resume control binds:

- failed zero-write execute run `30557073777`, attempt `1`, receipt
  `42bf4d8ed96513b83603a8f8aa7d2e7619a811abb9fa7691c3f5390cf7a2b203`,
  and artifact digest
  `bc1ac3e250a1a248de072102089d2b00aacc348e43be7741f47ebb88f456f677`;
- successful permission-repair run and immutable evidence above;
- successful residual-target preflight run `30556658048`, attempt `1`, state
  `c03ba2dfba1817a76b31653824333b792db8f3802403f1c582da2ced466da82c`,
  and target set
  `da453a9b4ca2ed2c60a0b17ef383eb2879ff7f67fa5c94458dbfa2fedc1ac381`;
- failed eligibility-only run `30587810948`, attempt `1`, with zero artifacts,
  and the historical preflight control-plane SHA
  `2284f5d9f0528722c7a58486f86c0716e526ed5c`;
- exactly 75 residual cache targets in ten batches, with at most five slugs and
  ten URLs per batch, a 5,000ms offline-build budget, and zero per-target
  retry.

The execute must reproduce the preflight state before writes, stop on the first
failure, and finish with the exact quality package plus complete 100-URL public
readback. It does not authorize database, CMS, publication, indexability,
queue, sitemap, llms, Search Channel, URL submission, deploy, rollback, or
non-target cache writes.

Repository design, tests, PR fixes, and repair-control design within these
boundaries do not require additional operator authorization.

## Post-readback partial execution

Lineage-repaired resume run `30588614573`, attempt `1`, completed all 75
bounded cache targets in all ten batches. Its maximum per-target build time was
4,060.14ms, below the 5,000ms budget. The run then failed at
`post_refresh_public_readback` with status `FAIL_PARTIAL` and write state
`committed_unverified`: all 100 public URLs returned HTTP 200 with valid
canonical, robots, and locale contracts, but the public snapshot still
reported 65 locale-unsafe-href URLs and ten thin-module URLs. No exact quality
package was issued. All database, CMS, publication, indexability, queue,
sitemap, llms, Search Channel, URL submission, deploy, rollback, and
non-target write counts remained zero.

The next control is read-only and receipt-bound. It compares only aggregate
quality and payload-set hashes for the exact 100 active cache targets as the
deploy runner and `www-data`, then performs one fresh public snapshot using
`Cache-Control: no-cache`, `Pragma: no-cache`, and a receipt-bound readback
query key. It emits no slugs or payloads and performs no cache, server, CMS,
database, publication, discoverability, deploy, or rollback write.

The first diagnostic dispatch, run `30590027833`, attempt `1`, passed
eligibility but failed before server connection because the workflow referenced
an unused known-hosts secret name. The aggregate inspection step was skipped,
zero artifacts were produced, and no production read or write occurred. The
recovery control binds that exact failed run and uses the repository-standard
`SSH_KNOWN_HOSTS` secret plus pinned host-key lookup before allowing inspection.

Successful post-readback diagnostic run `30594408300`, attempt `1`, proved the
deploy runner and `www-data` resolve the same runtime identity and active
payload set. All 100 cache targets were `ready_active`; locale-unsafe hrefs
were zero in both runtime identities and the fresh public snapshot. Ten
thin-module URLs remained in both active cache and fresh public delivery, so
the receipt classified the state as
`PASS_DIAGNOSTIC_REFRESH_PAYLOAD_INEFFECTIVE`. This means another blind refresh
of the same generated payload cannot close the quality gate.

`Career Search Entry Cache Thin-Source Diagnostic` is the only next diagnostic
control. It binds the successful post-readback run, immutable receipt and
artifact digests, diagnostic state, dual-identity runtime payload set, exact
manifest, unchanged active release, and exact latest control plane. After a
separate exact read-only authorization it may:

- reproduce the same ten thin targets as both the deploy runner and
  `www-data`;
- emit only one-based manifest positions, locale, module counts, enumerated
  surface-policy classes, and deterministic hashes;
- perform guarded database reads only for the thin manifest positions and
  report enumerated occupation, exact display-asset, 24-component, bilingual
  page, crosswalk, and locale-surface gate results.

The receipt never contains raw payloads, SQL, cache keys, server paths,
topology, or exception text. It uploads only the final sanitized receipt, not
the intermediate runtime or authority observations. The database guard rejects
every non-read query. Cache/server/CMS/database writes, publication,
indexability, queue, sitemap, llms, Search Channel, URL submission, deploy,
rollback, retry, and non-target mutation remain prohibited. Merging this
control does not authorize its production dispatch or any content/cache repair.
## Thin-source authority repair

The successful read-only thin-source diagnostic proved that the residual ten
thin URLs are five bilingual manifest members at manifest positions `30`, `33`,
`34`, `40`, and `42`. Each has one occupation row but no exact v4.2 display
asset, so the runtime correctly falls back to the restricted navigation shell.
The diagnostic classification is `exact_display_asset_missing`; permission
repair and blind cache refresh cannot supply the missing authority.

`Career Search Entry Thin Authority Repair` is a separate two-gate control. Its
`preflight` mode is read-only. It binds the immutable thin-source diagnostic,
the unchanged active release, and the exact 50-slug manifest, then validates
only the five reviewed workbook rows against the occupation crosswalks and the
existing display publish gate. The only accepted workbook is the historical
reviewed workbook with SHA-256
`c30f8743cfd0d8baa14ac931cc7270807425164952f6a44953b5b4ab448778ef`.
The receipt exposes manifest positions and hashes, not slugs or editorial
payloads.

The first production preflight, run `30597196453` attempt `1`, safely stopped
with `HOLD_APPROVED_WORKBOOK_UNAVAILABLE`: the exact reviewed workbook was not
present in any allowlisted production path. Its receipt SHA-256 is
`f896d7c7eb1af5a602cfa1c02b2a71bfee7c1afae3379d12e4f96bfa43c33c80`,
and its artifact digest is
`80da38b7dc56662b08ac632e3fd16d71fc5a9aad53e3b25dfcd1b35c04f8b1b2`.
All write counts were zero.

Workbook-unavailable recovery uses one exact private draft release as a
transport, never a repository content asset. Eligibility binds that failed
preflight, the exact release tag, the single expected asset, its SHA-256, and
its exact size of 29,974,136 bytes. The protected job downloads and verifies
the asset, writes it once to an ephemeral `/tmp` path with mode `0600`, runs
the bounded probe, deletes the temporary file, and verifies absence before
accepting the receipt. The receipt records one staging write, one deletion,
and zero persistent server writes. It still records zero cache, CMS, database,
publication, indexability, queue, sitemap, llms, Search Channel, URL
submission, deploy, and rollback operations in preflight mode.

The transport-backed preflight run `30599594878` attempt `1` proved the
workbook staging/removal path and then failed closed with
`CROSSWALK_AUTHORITY_DRIFT`. Its receipt SHA-256 is
`adaea57e089ee506fd545af32fe33f3de7265adf0df20c24051fc7e3a054479d`,
and its artifact digest is
`a55ff51d1fc467175bff3ea304243597a55677640792115bd05777882446972c`.
It recorded one ephemeral staging write, one deletion, zero persistent server
writes, and zero database/cache/CMS writes.

The recovery preflight binds that exact failed run and emits only a safe
five-position crosswalk diagnostic: per-position row counts, expected-code
match counts, an enumerated `exact`/`missing`/`duplicate`/`conflict`
classification, and a deterministic diagnostic-state hash. It never emits
slugs, occupation IDs, crosswalk codes, workbook payloads, SQL, or server
paths. Crosswalk drift returns
`HOLD_CROSSWALK_AUTHORITY_REPAIR_REQUIRED` with a successful read-only
receipt, so any authority repair can be designed from one bounded production
read and must remain a separately authorized write.

The successful recovery preflight, run `30600386710` attempt `1`, classified
all five positions as `missing`: each has zero `us_soc` and zero
`onet_soc_2019` rows. Its diagnostic-state SHA-256 is
`69d1b64c7d61467ab7a7470f69597d988dff3c70745f41ec30d0b1a43c731e08`,
receipt SHA-256 is
`ef72a72228682ef8c119025036a127fb76e778f94c96b29d3b1ebd81ffa4c67d`,
and artifact digest is
`d0ab5f043b33bf37764fd6bf5030579d307e6b06ce18271d18b91b80eb0b565a`.
The run recorded one ephemeral staging write, one deletion, and zero
persistent, database, CMS, or cache writes.

`repair` is separately authorized by that exact diagnostic receipt, the
combined repair-set SHA, and payload-set SHA. It requires the five positions to
remain in the exact all-missing diagnostic state, then atomically creates ten
`occupation_crosswalks` rows and five `career_job_display_assets` rows. The
crosswalk values and display payloads come only from the exact reviewed
workbook. Three deterministic contract normalizations are limited to those
five rows: `start_click` becomes `start_riasec_test`, prohibited Chinese
job-posting-sample wording becomes `公开市场信号`, and an explicit FermatMind
interpretation-boundary source label is added. These transformations do not
change occupational facts.

The offline exact-workbook plan produced combined repair-set SHA-256
`314f00896acd2a2b0fb1b6e495ddf888ff069effec005fbefef5efacf00ef7f8`
and display payload-set SHA-256
`fbdc6fe42e2d9bc6b893ddf938d54e5a7d231b29cef0857950e57989ee61ead1`.
Its component hashes are display repair set
`35280ad42fc6f95ec1833f86eb0e74ebb46a587fb0be0330120ff6db2512fdc3`,
crosswalk repair set
`453e09187ea1281d591f962830d2c12f3fefa24e4dcdd4e2872f09f768a63117`,
and normalization set
`14b3f90c527c5776b85621f8f8fab0e844d4897ce238a6bf78e23af9b5294dc0`.
All five normalized rows pass the mapper with 24 components and both locales.

The database guard permits inserts only into those two tables, and every
target must be absent before the transaction starts. Before commit, both
crosswalk systems must be exact and both locales must resolve to the
24-component display contract with locale-safe hrefs. A hash over all
non-target crosswalk and display-asset identities must remain unchanged.

The repair does not warm or forget cache entries. It does not change CMS
resources, publication, indexability, queues, sitemap, llms, Search Channel,
URL submission, deployment, rollback, or any non-target row. After a verified
repair receipt has been uploaded, the workflow deletes the exact private draft
release transport. Then use a separately authorized cache-only refresh and
require the exact quality package plus complete 100-URL public readback before
resuming Task 12.

The combined repair succeeded in run `30601380901` attempt `1`. Its immutable
receipt SHA-256 is
`afa5cac18c50722c8072c2ef184617e649621919570b34429d5e02207eb74ec5`
and artifact digest is
`40a38665002e7012bddfc7555f18cbe3a07789af37774e2041439be71cd41cf1`.
The transaction created exactly ten crosswalk rows and five display-authority
rows, verified ten bilingual 26-module surfaces, preserved the non-target state,
and deleted the private draft-release transport. It performed no cache write.

## Post-authority cache-only refresh

`Career Search Entry Post-Authority Cache Refresh` is the single write gate
after the verified authority repair. It binds the successful authority-repair
run, receipt and artifact digests, combined repair and payload sets, unchanged
active release, exact manifest, and latest control-plane SHA.

The cache write scope is derived only from manifest positions `30`, `33`, `34`,
`40`, and `42`: exactly five slugs and ten bilingual detail-cache targets. Its
stable target-set SHA-256 is
`33c4418a4491c901dc35fd7ee3ddf739b7e6d2b62e32d97fe5e924d80dea1a6a`.
Before any cache write, the runner verifies the active release identity,
manifest contract, all 100 public URL transport/canonical/robots/locale gates,
the fixed target set, the exact quality package, and the 5,000ms offline-build
interface. Drift fails closed with zero cache writes. The post-write quality
package must match the pre-write package exactly.

The ten targets run in one batch, with at most five slugs and ten URLs, and
zero per-target retry. Success requires all ten cache publications, the exact
50-candidate/100-URL/300-review-target quality package, zero locale-unsafe
hrefs, zero thin-module URLs, and one complete fresh 100-URL public readback.
Database, CMS, publication, indexability, queue, sitemap, llms, Search Channel,
URL submission, deploy, rollback, and non-target writes remain prohibited.
