# Exact-package content promotion automation V2

`content:promote-exact-package` is the only trusted entry point for automatic CMS draft import and publication. A concrete end-to-end content `/goal` supplies standing authorization for the exact lane, subscope, backend package path and package SHA. The SHA is an integrity, idempotency, audit and rollback identity; it is not a human approval token.

The protected workflow executes `preflight → draft-import → publish → live-qa` against one deployed `fap-api` main commit. Every phase emits one immutable redacted JSON receipt. Publication consumes the exact successful import receipt and live QA consumes the exact successful publication receipt. A failed phase stops the workflow. Failed live QA restores the rollback snapshot recorded before publication.

The executor never changes indexability, sitemap, llms, Search Channel or deployment state. Draft rows remain non-public and noindex. A package must be committed under an allowlisted backend authority root; a `fap-web` evidence snapshot is never executable content authority.

## Adapter capability contract

## Priority matrix

| Situation | Required path |
| --- | --- |
| Registered, audit-compatible adapter | V2 automatic workflow: independent W9/QA, dry-run, import, readback, publish, and live QA. |
| Registered, incompatible adapter | Complete the adapter; do not fall back to manual publication. |
| Non-package legacy operation | The separate manual SOP may apply only when its direct legacy command is intentionally invoked. |
| Deploy, migration, secrets/permissions, destructive or SEO discoverability work | Separately controlled; V2 promotion does not authorize it. |

The V2 path must not request a confirmation phrase or approval artifact after its machine gates pass. A W9 `BLOCKED` verdict is repaired and rerun in the same Producer PR, not in a status-only or separate evidence/reset/refreeze PR.

An adapter is executable only after it proves all of the following against its real authority store: atomic draft import, deterministic replay, exact readback, exact publication, no discoverability mutation, an existing audit metadata location and bounded rollback. Missing compatibility fails closed during preflight.

Current capability truth:

- `W1 / mbti-comparisons`: audit-compatible and executable through V2.
- `W1 / mbti-results`: audit-compatible through the database-backed `content_pack_releases`, `content_release_manifests`, activation, and `ContentReleaseSnapshot` authority. It dynamically recomputes the package manifest chain, creates only the exact inactive authority record, activates only the matching English target set, and restores only the prior activation on rollback. It never writes an immutable deployed content-pack tree or reads private attempts, reports, orders, payments, or tokens.
- `W2 / big-five` and `W5 / enneagram`: audit-compatible through the shared `PersonalityPublicContentAsset`, immutable `PersonalityPublicContentAssetRevision`, independently bound private review attestation, published/working pointer, public read-model cache invalidation, and `ContentReleaseSnapshot` authority. Each package has its own framework, target set, SHA, receipt chain, and rollback lineage. The frozen package contains only the exact asset payload; it cannot self-declare review approval. After draft import, `content:bind-personality-promotion-review --json` derives the current draft revisions, verifies the package-external configured-owner attestation against their exact hashes, and binds immutable per-revision evidence. This command never imports, publishes, changes discoverability, submits search URLs, or deploys. Publication and live QA revalidate the same attestation and per-target evidence, including idempotent publication. The adapter accepts complete, type-safe English text-only snapshots only; it rejects Markdown and HTML media forms, private routes/sensitive query values, and over-budget public payloads; it never writes hero, inline, OG, or Twitter fields, and preserves all discoverability fields.
- `W3 / W3-ARTICLES`: audit-compatible through existing `Article`, `ArticleTranslationRevision`, `ArticleEditorialPackageImport`, working/published revision pointers, `ContentReleaseSnapshot`, and the Article public API readback. Use `W3-ARTICLES` as the protected workflow input and receipt identity. The immutable package-local manifests and W9 snapshot retain their historical `articles` label; that label is not a dispatchable control subscope. The adapter binds every package row to one existing English Article and an immutable package-SHA revision, requires an external independent W9 PASS report for that exact payload SHA, preserves existing indexability/sitemap/llms flags, and restores only the captured Article pointers and revision states on rollback. It never invokes the generic Article publish service or a legacy operator command.
- `W3 / career-guides` and `W8 / career-jobs`: audit-compatible through the existing `CareerGuide` / `CareerGuideRevision` and `CareerJob` / `CareerJobRevision` authority stores. Each concrete adapter creates an immutable, exact-SHA-bound draft revision without changing the public row; publication projects only that revision's exact content onto its already-public English target, retains every public/indexability field, and uses a target-bound `ContentReleaseSnapshot` for rollback. The two lanes have distinct package SHA, receipt, revision-store, target fingerprint, and rollback lineages; neither adapter can read or modify the other model family, Career search cache, sitemap, llms, Search Channel, or deployment state.
- `W4 / riasec`, `W6 / iq`, and `W7 / eq`: registered but fail closed until their existing authority-specific importer/publisher exposes compatible revision/audit metadata and rollback.

This distinction prevents the unified command from becoming an arbitrary Artisan or SQL runner. Adding an adapter requires focused importer, publication, readback, idempotency and rollback tests for that authority.

## Reusable conformance primitives

Every V2 adapter uses one canonical target set. Its sorted identity fingerprint is bound to the lane, subscope, package SHA, source commit and lifecycle phase when a snapshot is taken. A rollback reference is accepted only when all of those bindings, including the exact target set, match; an adapter may restore only its own captured rows.

The shared result factory requires exact readback before a phase can emit a success receipt. Every receipt separately records `created_count`, `updated_count`, and `unchanged_count`; those values must sum to the exact readback count and created plus updated must equal written. The test harness checks the same result and boundary invariants for every adapter: draft phases stay non-public, publication and live QA reach the exact expected count, and indexability, sitemap, llms, Search Channel and deploy mutation counts remain zero. `content_promotion.adapter_capabilities` is a contract mirror of concrete registry adapters; a mismatch fails the registry contract rather than changing capability truth by configuration alone.

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
