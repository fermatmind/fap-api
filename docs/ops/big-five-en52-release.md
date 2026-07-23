# Big Five EN52 release and production readback

The English Big Five authority release is the locked 52-page `en` cohort identified by release id
`big-five-en52-52-page-release-20260719` and package SHA-256
`91f3c1e94894cfe59ce17ee00e5046d26a9cafc9113fe1eeb4488e4951e4940a`.

## Four-PR delivery status

The four-PR implementation train is complete at the source-control and CI layer. All four PRs are merged,
their final required checks passed, their review threads are resolved, their merge commits are contained by
the corresponding repository `origin/main`, and their task branches have been removed. This does **not**
mean that the controlled English publication or production runtime verification has run.

The following block is the stable scanner summary for this train. PR13 is cross-repository evidence from
`fap-web`; this backend documentation update does not mutate the frontend train ledger.

```yaml
big_five_en52_four_pr_train:
  snapshot_date: 2026-07-23
  delivery_status: merged
  production_execution: not_run
  items:
    - id: BIG5-EN52-RELEASE-PACKAGE-11
      repository: fap-api
      pr: 3201
      final_head: 6a23d62fb0c0ff0dc8f65b31080195189d0573a7
      merge_commit: feb6c48925b045c7931bf9a36e2c6badb53a36aa
      merged_at: 2026-07-19T04:44:41Z
      required_checks: 9/9
      unresolved_review_threads: 0
    - id: BIG5-EN52-CONTROLLED-PUBLISHER-12
      repository: fap-api
      pr: 3204
      final_head: 9a0f0d528f5e195d642f455d6a7f546d3e67df10
      merge_commit: 6ed2ca4591c764099e58cd2acc7ba2971263170e
      merged_at: 2026-07-19T06:42:53Z
      required_checks: 9/9
      unresolved_review_threads: 0
    - id: BIG5-EN52-104-DISCOVERABILITY-CONVERGENCE-13
      repository: fap-web
      pr: 1792
      final_head: ab1dec4b5c5b30e8b476051040b21d0210596fc8
      merge_commit: 5becd8b7374240fabd58803276e73d26f361b0af
      merged_at: 2026-07-19T07:18:22Z
      required_checks: 7/7
      unresolved_review_threads: 0
    - id: BIG5-EN52-RUNTIME-CLOSEOUT-14
      repository: fap-api
      pr: 3208
      final_head: d48816e982a9cd9a48f403a20050b25abcd8c597
      merge_commit: 2c197196ab041f5e5102f9d828e7e978bc96c8ba
      merged_at: 2026-07-19T09:39:14Z
      required_checks: 9/9
      unresolved_review_threads: 0
```

For follow-up scans, treat `delivery_status: merged` as code delivery only. The next controlled milestone
remains a separately authorized production publication followed by separately authorized read-only runtime
verification against an exact deployed `main` SHA and release identity.

## Separation of authority

`personality:big-five-en52-content-publish` is the separately controlled publisher. This document and
`personality:big-five-en52-runtime-verify` do not authorize or perform publication, deployment, migration,
alias purge, cache mutation, process restart, SSH repair, or search submission. Production publication and
production verification require separate, exact operator authorization.

The runtime verifier is fail closed and read only. It reads only the fixed deployed release identity,
schema-backed authority rows and revisions, configured search-channel tables, public APIs, sitemap source,
public sitemap/llms surfaces, and the 104 canonical plus 20 redirect-only public paths. It writes no remote
artifact. The generic production verify-only runner must capture sanitized JSON stdout into its runner-side
artifact after separately validating that the approved SHA is contained by `main`.

Every verifier request to the backend public API carries a short-lived HMAC signature bound to the exact
HTTPS API origin and GET request URI. The public personality read-model cache recognizes only a valid signature produced with
the deployed application key and bypasses all cache reads, locks, pointer refreshes, and cache writes for
that request. The same signature is carried under the dedicated `Fermat-Verify-Only` authorization scheme,
so the existing non-anonymous request boundary also suppresses deferred runtime-metrics cache writes. The
signature expires after 60 seconds and cannot be replayed for a different URI. Frontend surface probes do
not carry these headers.

## Required approval inputs

- Exact deployed fap-api `main` SHA and exact release directory name.
- Exact HTTPS backend and frontend public origins.
- The checked-in PR11 release package; its hash is hard locked by code.
- Exact pre-publish zh-CN, non-target, and search-channel fingerprints from the approved release evidence.
- A verified `big-five-en52-production-backup.v1` manifest and its exact SHA-256.

## Evidence and backup gate

Run the evidence command before any backup or publish action. It is read only and reports the exact English
and Chinese canonical counts, current legacy alias count, 52-page source-hash match count, table-level backup
checksums, and the three runtime baseline fingerprints:

```bash
php artisan personality:big-five-en52-production-evidence \
  --package=../generated/big-five-en52-release/release-package.json \
  --json
```

The backup action is separately controlled. The output directory must already exist and be protected. The
command creates one private row-level artifact and one manifest with mode `0600`, refuses to overwrite either
file, re-reads both files, and verifies their checksums against the live 52-row cohort. It requires the twenty
legacy alias database rows to have already been purged.

```bash
php artisan personality:big-five-en52-production-backup \
  --package=../generated/big-five-en52-release/release-package.json \
  --execute \
  --confirm=CREATE_BIG_FIVE_EN52_PRODUCTION_BACKUP \
  --output-dir="<existing-protected-backup-directory>" \
  --approved-sha="<40-hex-approved-main-sha>" \
  --release-name="<exact-release-name>" \
  --operator-admin-user-id=1 \
  --json
```

Publisher execute must bind the same deployed identity and exact manifest. It revalidates the manifest,
artifact hash, target table row counts/checksums, source hashes, and approved baseline fingerprints after
locking the transaction boundary. A stale, missing, moved, modified, or mismatched backup fails before any
content write.

```bash
php artisan personality:big-five-en52-content-publish \
  --package=../generated/big-five-en52-release/release-package.json \
  --execute \
  --approved-sha="<40-hex-approved-main-sha>" \
  --release-name="<exact-release-name>" \
  --backup-manifest="<exact-backup-manifest-path>" \
  --backup-sha256="<64-hex-backup-manifest-sha256>" \
  --confirm-content-sha256=056b10d3f640d0cf7da35ec7bc99b009408049e75c1e25aa8e760eb8641ea8d5 \
  --confirm-cohort-sha256=94449467281cffaccc295bab3bbbb574cf817e461ee2fbae8288eedd9a988b3a \
  --confirm-package-sha256=91f3c1e94894cfe59ce17ee00e5046d26a9cafc9113fe1eeb4488e4951e4940a \
  --operator-admin-user-id=1 \
  --json
```

Example shape (placeholders are intentionally non-runnable):

```bash
php artisan personality:big-five-en52-runtime-verify \
  --approved-sha="<40-hex-approved-main-sha>" \
  --release-name="<exact-release-name>" \
  --api-origin="https://api.fermatmind.com" \
  --frontend-origin="https://fermatmind.com" \
  --expected-zh-fingerprint="<64-hex-approved-baseline>" \
  --expected-non-target-fingerprint="<64-hex-approved-baseline>" \
  --expected-search-fingerprint="<64-hex-approved-baseline>" \
  --json
```

Success proves the exact 52 English assets and revisions, 1/5/15/1/30 family inventory, exact pointers and
package lineage, reciprocal English/Chinese hreflang, public/index/sitemap/llms eligibility, 104 bilingual
canonicals, absence of all legacy alias assets and discoverability entries, exact 20 permanent single-hop
redirects, zero canonical redirects, zero media projection, unchanged approved boundary fingerprints, and
zero writes. Any mismatch or bounded HTTP failure returns sanitized JSON with a stable error code and does
not attempt repair. Do not include raw response bodies, environment values, database credentials, tokens,
private topology, logs, or private notes in the runner artifact.
