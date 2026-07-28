# Enneagram Authority V2 technical architecture and operations guide

## Purpose and status

This is the maintainer entry point for the backend-authoritative Enneagram
public-content system. It consolidates the stable architecture, release
invariants, operator boundaries, and the lessons retained from the rollout
task that ran from 2026-07-16 through 2026-07-28.

The conversation that coordinated the rollout is not a repository artifact.
Only facts supported by committed code, immutable receipts, Git history, and
public-readback evidence belong here. Credentials, private review identities,
request authentication material, rollback material, routing metadata, server
paths, and private URLs must remain outside Git.

The Authority V2 rollout is complete. Its final recorded state is:

```text
completed_with_recorded_authorization_drift_and_cache_recovery
```

This is deliberately not described as an unconditional clean success. The
original publication transaction committed before frontend revalidation
failed, and the original authorization fingerprint did not remain continuous
after a stale pre-readback was regenerated. A separately authorized,
cache-only recovery later converged the frontend and completed the full
readback.

## Public estate

The frozen estate contains 58 locale-neutral identities and 116 public assets:

- 1 hub;
- 3 centers;
- 9 core types;
- 18 wings;
- 27 instinctual subtypes;
- `en` and `zh-CN` for every identity.

`EnneagramPublicAuthorityV224RuntimeManifest` is the executable source for the
target count and batch plan:

- `TARGET_COUNT = 116`;
- `canary-00 = 8`;
- `readback-01..09 = 9 x 12`;
- one atomic 116-revision promotion, followed by readback batches.

The batches are verification units. They are never partial publication waves.

## Authority and ownership

The backend owns:

- public editorial content and revision state;
- title, summary, sections, FAQ, evidence, limitations, and sources;
- canonical, hreflang, robots, indexability, internal links, and public
  discoverability eligibility;
- exact asset/package hashes and release evidence;
- review status and reviewed timestamp;
- sitemap, `llms.txt`, and `llms-full.txt` enumeration;
- the empty-media contract.

The frontend consumes the public API and renders the visible contract. It must
not invent a CMS fallback, reviewer identity, media fallback, SEO authority, or
additional public Enneagram route.

The public projection is `personality_public_asset.v2`. Missing authority data
fails closed; the frontend must not reconstruct it from local content.

## Persistent model and privacy boundary

The principal records are:

- `PersonalityPublicContentAsset`: stable public identity and published
  pointer;
- `PersonalityPublicContentAssetRevision`: immutable candidate/published
  content revision;
- `PersonalityPublicContentAssetRevisionReview`: private review evidence;
- `ReviewAttestation` and target evidence: compact solo-owner review
  attestation and its exact target binding.

The supported solo-owner review path is an internal attestation for the exact
116-target set. It may expand internally into the row shape required by the
binder. The public API may expose only review state and review time; it must
not expose the owner/reviewer identity or private evidence.

Both the 116-row private register and the compact solo-owner attestation are
valid binder inputs when they pass their exact package, target-set, evidence,
and identity checks. Completed private inputs do not belong in Git.

## Content and evidence pipeline

The committed packages form a one-way authority pipeline:

1. `benchmark-01` freezes the estate and comparison baseline.
2. `integrity-gate-02` defines deterministic zero-write QA.
3. `public-contract-04` exposes the visible-evidence projection.
4. `revision-workspace-05` creates collision-safe working revisions.
5. `revision-promoter-06` provides atomic pointer promotion and signed
   rollback material.
6. `source-ledger-07` binds claims to declared sources.
7. `editorial-gate-08` enforces bilingual originality and evidence limits.
8. `hub-centers-09` and `type-1-family-10` through
   `type-9-family-18` contain the 116 candidate assets.
9. `link-graph-20` freezes public links, FAQ, and locale relationships.
10. `release-gate-22` aggregates the exact release report and package.
11. runtime import/review/readback controls bind exact deployed revisions and
    authorize the one-time rollout.
12. `runtime-closeout-23` preserves the redacted final receipts and
    retrospective.

The historical `media-og-19` directory is planning evidence only. It was
superseded by the empty-media boundary before production and is not an active
release input.

## Media boundary

Every one of the 116 Authority V2 assets is permanently text-only:

```json
{"hero":null,"inline":[],"og":null}
```

Consequences:

- media writes must remain zero;
- no Media Library upload belongs to the rollout;
- no backend or frontend fallback image is allowed;
- no hero or inline authority-media DOM is allowed;
- section Markdown/HTML images must not be rendered;
- a future image initiative requires a separate backend-authoritative and
  rights-reviewed change.

## Release and runtime state machine

The intended state transition is:

```text
frozen release report
  -> read-only integrity/release gate
  -> private approved review input
  -> read-only pre-readback
  -> exact-SHA runtime preflight
  -> separately authorized execute
  -> 116 isolated working revisions
  -> 116 bound review records
  -> one atomic 116-pointer promotion
  -> signed rollback material retained outside Git
  -> 116-path frontend revalidation
  -> canary-00
  -> readback-01..09
  -> redacted closeout
```

Only `--artifacts-only`, gate, preflight, and readback modes are intrinsically
read-only. Write modes require the command's exact dynamic authorization and
the separately controlled production context. A documentation change,
successful staging run, or merged PR never authorizes a production mutation.

### Operator commands

Run commands from `backend/`. Use `--help` and the implementation signatures as
the source of truth; do not copy a historical authorization phrase.

Read-only release gate:

```bash
php artisan personality:enneagram-authority-v2-integrity-gate \
  --release-gate \
  --json
```

Generate uncompleted review bootstrap artifacts without runtime probes:

```bash
php artisan personality:enneagram-authority-v2-runtime-closeout \
  --artifacts-only \
  --output-dir=<git-external-output-directory> \
  --json
```

Read-only runtime readback:

```bash
php artisan personality:enneagram-authority-v2-runtime-readback \
  --phase=<pre-or-post> \
  --batch=<canary-00-or-readback-01-through-09-or-all> \
  --api-base-url=<public-api-origin> \
  --frontend-base-url=<public-frontend-origin> \
  --backend-deployed-sha=<exact-sha> \
  --frontend-deployed-sha=<exact-sha> \
  --json
```

Production preflight and execute options intentionally are not reproduced as a
ready-to-run recipe. The canonical signatures are:

- `PersonalityEnneagramAuthorityV2RuntimeCloseout`;
- `PersonalityEnneagramAuthorityV2RevisionWorkspace`;
- `PersonalityEnneagramAuthorityV2ReviewEvidenceBinder`;
- `PersonalityEnneagramAuthorityV2RevisionPromoter`;
- `PersonalityEnneagramAuthorityV2RuntimeReadback`.

Use the protected workflow/control plane and its freshly generated immutable
preflight artifact. Never reuse a phrase, packet, pre-readback, or runtime
fingerprint after any bound input changes.

## Readback contract

Every API and HTML target must prove:

- HTTP 200 and no soft 404;
- expected title, description, H1, canonical, hreflang, and robots;
- visible FAQ/evidence agrees with generated schema;
- exact source/package identity;
- empty hero, inline, and OG media;
- no authority hero/inline DOM;
- no private reviewer identity, assessment result, order/payment data,
  authentication material, or private link.

The complete run must also prove:

- 116 API reads and 116 HTML reads;
- 8-page canary plus nine 12-page batches;
- zero private-data exposure;
- zero non-empty media;
- stable public projection and discoverability fingerprints;
- unchanged sitemap, `llms.txt`, and `llms-full.txt` URL sets.

The public projection fingerprint may change when publication intentionally
changes visible content. The stable identity/discoverability fingerprint and
URL-set fingerprints must not change unless a separately reviewed scope
explicitly authorizes an estate or discoverability change.

## Frontend revalidation and recovery

Frontend revalidation signs the exact request body, timestamp, and nonce with
the shared content-release contract and submits exactly 116 allowlisted paths.
Secrets and replay-protection storage are runtime configuration, never release
artifacts.

Acceptance requires:

- 116 accepted paths;
- zero rejected paths;
- no arbitrary path or private route accepted;
- no secret, nonce, signature, or routing metadata in logs/artifacts.

If promotion committed but frontend revalidation fails:

1. stop the full closeout executor;
2. confirm 116 working imports, review binds, and published pointers;
3. retain rollback material outside Git and record only its SHA;
4. diagnose configuration and endpoint health through separate controlled
   scopes;
5. do not repeat import, bind, or promotion;
6. use the protected `Enneagram PR23 Cache-Only Resume` workflow only after a
   fresh immutable preflight and separate exact authorization;
7. revalidate exactly 116 paths, then run the complete post-readback;
8. do not roll back automatically.

The cache-only lane may perform frontend HMAC revalidation and readback only.
It must not deploy, invalidate backend caches, migrate, restart services,
mutate CMS/database authority, import, bind, promote, or roll back.

## Fail-closed conditions

Stop before the next mutation or batch when any of the following occurs:

- deployed backend/frontend SHA drift;
- package, report, review, receipt, or preflight fingerprint drift;
- missing, rejected, duplicate, or non-matching review evidence;
- a non-empty media record or media write;
- a working import changes the pre-promotion public fingerprint;
- pointer state differs from the exact promotion plan;
- any revalidation rejection;
- canary or later readback mismatch;
- sitemap/LLM URL-set drift;
- private data appears publicly;
- a required check or exact protected-workflow gate is not green.

After a committed promotion, a downstream failure is a partial-write incident,
not a zero-write failure. Preserve the committed-state truth and require a new
bounded recovery or rollback authorization.

## Rollout timeline

This task thread ran from 2026-07-16 through 2026-07-28. The foundational
benchmark, integrity, public-contract, revision-workspace, promoter, and source
ledger commits landed on 2026-07-15 and are prerequisites rather than part of
the thread's stated start date.

### 2026-07-16: content estate and release gate

- Hub/centers, nine type families, link graph, and the 116-asset release gate
  were completed.
- The media plan was replaced by the exact empty-media release boundary.
- Two English evidence-boundary duplicates were repaired.

### 2026-07-16 to 2026-07-19: runtime and review controls

- Exact candidate import, private review binding, atomic promotion,
  rollback preflight, and 116-page readback controls were added.
- A compact solo-owner attestation became an accepted private review input.
- Public review state was normalized while private reviewer identity remained
  hidden.

### 2026-07-20 to 2026-07-27: deployment convergence

- Backend/frontend revision evidence, health gates, queue/runtime alignment,
  public API origin handling, visible HTML normalization, and LLM URL-set
  comparison were converged through separate controlled scopes.
- Production deployment and runtime configuration remained distinct from the
  content promotion authorization.

### 2026-07-28: closeout, incident, and recovery

- The original closeout committed 116 imports, 116 review binds, and one
  atomic 116-pointer promotion.
- Frontend HMAC revalidation failed after promotion, so post-readback stopped
  and automatic rollback remained disabled.
- The event also retained an `AUTHORIZATION_DRIFT` finding because a refreshed
  stale pre-readback changed the runtime fingerprint before execution.
- Separate runtime-configuration and cache-only controls restored frontend
  convergence without repeating import, bind, or promotion.
- The final canary and all nine follow-up batches passed.
- PRs #3355 through #3363 form the bounded cache-recovery control chain; PR
  #3364 is the redacted final closeout.

## Evidence and navigation

Use these directories according to purpose:

- `enneagram-public-authority-v2-benchmark-01/`: frozen estate and baseline;
- `enneagram-public-authority-v2-integrity-gate-02/`: zero-write integrity
  rules;
- `enneagram-public-authority-v2-public-contract-04/`: public API contract;
- `enneagram-public-authority-v2-source-ledger-07/`: source and claim ledger;
- `enneagram-public-authority-v2-editorial-gate-08/`: editorial QA;
- `enneagram-public-authority-v2-release-gate-22/`: exact release
  report/evidence;
- `enneagram-public-authority-v2-runtime-closeout-23/`: redacted final
  receipts and retrospective;
- `docs/ops/release-train.md`: protected cache-only recovery runbook.

Historical package evidence is immutable. Correct a misleading summary through
a new scoped documentation PR; do not rewrite machine receipts or private
inputs.

## Maintenance checklist

For any future Enneagram public-authority change:

1. Declare whether the change affects content, contract, estate,
   discoverability, review governance, cache, or operations.
2. Keep one PR to one scope and preserve backend authority.
3. Regenerate exact package/report hashes when content changes.
4. Run the complete integrity, editorial duplicate/evidence, public-contract,
   and media-boundary gates.
5. Keep review input private and bind it to the exact target set.
6. Rebuild pre-readback and authorization after any bound input drift.
7. Separate application deployment, runtime configuration, production
   promotion, cache recovery, and rollback authorizations.
8. Archive only redacted receipts: SHAs, run IDs, counts, states, and safe
   error classifications.
9. Never claim exact authorization continuity when the evidence shows drift.
10. Update this guide when the architecture or operator boundary changes.

Repository rule impact: none. This guide documents the existing
backend-authoritative, text-only Enneagram Authority V2 system and does not
change content ownership, public contracts, runtime behavior, review policy,
publication state, discoverability, or deployment permissions.
