# Big Five EN52 release and production readback

The English Big Five authority release is the locked 52-page `en` cohort identified by release id
`big-five-en52-52-page-release-20260719` and package SHA-256
`91f3c1e94894cfe59ce17ee00e5046d26a9cafc9113fe1eeb4488e4951e4940a`.

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
