# Exact-package content promotion automation V2

`content:promote-exact-package` is the only trusted entry point for automatic CMS draft import and publication. A concrete end-to-end content `/goal` supplies standing authorization for the exact lane, subscope, backend package path and package SHA. The SHA is an integrity, idempotency, audit and rollback identity; it is not a human approval token.

The protected workflow executes `preflight → draft-import → publish → live-qa` against one deployed `fap-api` main commit. Every phase emits one immutable redacted JSON receipt. Publication consumes the exact successful import receipt and live QA consumes the exact successful publication receipt. A failed phase stops the workflow. Failed live QA restores the rollback snapshot recorded before publication.

The executor never changes indexability, sitemap, llms, Search Channel or deployment state. Draft rows remain non-public and noindex. A package must be committed under an allowlisted backend authority root; a `fap-web` evidence snapshot is never executable content authority.

## Adapter capability contract

An adapter is executable only after it proves all of the following against its real authority store: atomic draft import, deterministic replay, exact readback, exact publication, no discoverability mutation, an existing audit metadata location and bounded rollback. Missing compatibility fails closed during preflight.

Current capability truth:

- `W1 / mbti-comparisons`: audit-compatible and executable through V2.
- `W1 / mbti-results`, `W2 / big-five`, `W3 / articles`, `W3 / career-guides`, `W4 / riasec`, `W5 / enneagram`, `W6 / iq`, `W7 / eq`, `W8 / career-jobs`: registered but fail closed until their existing authority-specific importer/publisher exposes compatible revision/audit metadata and rollback.

This distinction prevents the unified command from becoming an arbitrary Artisan or SQL runner. Adding an adapter requires focused importer, publication, readback, idempotency and rollback tests for that authority.

## Invocation

```bash
php artisan content:promote-exact-package \
  --package=content_assets/en-content-parity/W1-mbti/comparisons/w9-correction-deecc817 \
  --expected-package-sha256=<sha256> \
  --lane=W1 \
  --subscope=mbti-comparisons \
  --phase=preflight \
  --receipt=/secure/new/receipt.json \
  --json --no-ansi
```

Workflow identity, expected row count, executor release SHA, release-policy SHA and previous receipt path are supplied as protected workflow environment values. Direct production execution outside the protected workflow fails closed because those exact bindings are absent.

## Separately controlled operations

Production application deployment, database migrations, secrets or permission changes, destructive deletion, indexability, sitemap, llms and Search Channel changes remain separate exact-scope gates. Deploying this executor is infrastructure deployment and is not performed by either automation infrastructure PR.
