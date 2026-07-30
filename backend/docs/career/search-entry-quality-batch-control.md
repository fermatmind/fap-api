# Career search-entry quality batch control

This control plane closes the runtime authority gap between the exact
`CAREER-SEARCH-ENTRY-QUALITY-BATCH-01` review package and the existing public
`search_entry_tier` projection. It does not publish Career content or alter
robots, canonical, sitemap, llms, cache, queue, or Search Channel state.

## Authority and persistence

Two transitions remain deliberately separate:

1. `Career Search Entry Batch Review Production Ops` binds one immutable
   `approved_all` attestation over exactly 50 slugs and 300 current bilingual
   content, SEO, and visible-claim targets.
2. `Career Search Entry Batch Apply Production Ops` consumes that exact
   attestation and appends one operation receipt. Runtime eligibility exists
   only while the receipt is authentic, matches the current review package,
   and has no append-only rollback receipt.

Review evidence alone remains runtime-ineligible. An apply receipt cannot
substitute for missing, stale, partial, or non-approved review evidence.

`career_search_entry_quality_batch_operations` is an append-only authority
ledger. Application updates and deletes are prohibited. A rollback appends a
second receipt referencing the exact apply receipt; it does not erase history.

## Deployment prerequisite

Merging this baseline repair does not authorize production deployment or Task
12. Deploy the exact merge SHA through `Deploy API Production` and explicitly
approve:

```text
backend/database/migrations/2026_07_28_000100_create_career_search_entry_quality_batch_operations_table.php
```

Fresh release discovery and Backend Production Verify Only must prove that the
exact release is active before either Career workflow is dispatched.

## Controlled sequence

1. Dispatch the review workflow in `preflight` mode from exact latest `main`.
   It is zero-write and emits an exact phrase bound to the active release,
   run/attempt, state SHA, both package SHAs, target-set SHA, actor, and 300
   targets.
2. After the operator repeats that phrase verbatim, dispatch `bind`. It may
   write only one review attestation plus 300 target-evidence rows. Existing
   exact evidence is a zero-write idempotent success.
3. Dispatch the apply workflow in `preflight` mode, binding the successful
   review-bind run and exact evidence SHA. It is zero-write and emits the Task
   12 apply phrase.
4. After verbatim operator confirmation, dispatch `apply` once. It may append
   only one apply receipt. Failure is not automatically retried.
5. The same run performs cache-backed authority readback, all 100 public Career
   detail API readbacks, and all 100 Career sitemap-membership checks. The
   sanitized receipt records only counts and hashes.

## Stale detail-cache recovery

If the review preflight fails at `exact_package_build` after the authority fix
is active, first complete a read-only 50-slug / 100-URL public readback. When
that evidence proves the active/LKG detail payloads still contain the
deployment-before-fix locale links or thin runtime shells, use:

`Career Search Entry Batch Cache Refresh Production Ops`

The workflow has two separately controlled modes:

1. `preflight` binds exact latest control-plane SHA, active release SHA/name,
   the checked-in manifest SHA, all 100 payload hashes, and exact stale URL
   counts. It is zero-write and emits the exact cache-only approval phrase.
2. `execute` requires that phrase and immutable preflight run/attempt,
   revalidates the complete pre-refresh state, refreshes only the exact 50
   manifest slugs in `en` and `zh-CN`, and completes exact quality-package plus
   100-URL public readback in the same run.

Each public detail request retries exactly once only after a transport-level
`curl` failure, using a one-second delay, a five-second connect timeout, and a
30-second total request timeout. HTTP or semantic mismatches are not retried,
and only the final successful response is included in the immutable payload-set
and readback hashes. Failed receipts expose only safe aggregate readback counts;
they do not include target identity, URL, response content, or topology.

The refresh uses the existing atomic active/LKG detail publication path and
does not forget prior pointers before replacement. It never changes database
or CMS authority, publication, indexability, queues, sitemap, llms, Search
Channel, URL submission, deployment, or non-target state. A failed or
indeterminate execute is not automatically retried or rolled back.

An indeterminate execute is inspected only through
`Career Search Entry Batch Cache Refresh Recovery Preflight`. This separate
read-only workflow binds the exact failed run/attempt and failed receipt SHA,
rejects any intervening cache-refresh production-ops run, revalidates latest
`main` plus the unchanged active release, and records a fresh complete 100-URL
payload-set/readback hash with aggregate HTTP, canonical, robots, locale,
unsafe-href, and thin-module counts. It never invokes the warmer and has no
retry, resume, rollback, deploy, queue, CMS/database, publication,
indexability, sitemap, llms, URL submission, or Search Channel write path.

If residual stale/thin targets remain, the receipt may emit only a phrase
authorizing design of a separate cache-only resume control. That phrase does
not authorize a production retry or write. If the current state is already
clean, the workflow additionally verifies the exact read-only quality package
and may emit only a recovery-closeout/fresh-Task-12-preflight phrase. In either
case the operator must stop at the emitted gate. After a successful refresh or
clean recovery receipt, Task 12 still requires a brand-new review preflight;
the cache-repair receipt cannot substitute for Task 12 review or apply
authorization.

The apply phrase keeps CMS content, publication, indexability, cache warm,
queue dispatch, sitemap/llms mutation or submission, Search Channel, URL
submission, and deploy held. The operation does not release held slugs or
expand beyond the first exact batch.

## Rollback

Successful apply output includes an exact apply receipt SHA and rollback
authorization SHA. Rollback requires a new, verbatim operator phrase binding
those values, the active release, operation ID, rollback identifier, and
actor. It appends exactly one rollback receipt and immediately makes the batch
runtime-ineligible.

Rollback is never automatic. If apply post-write readback fails, first inspect
the sanitized receipt and confirm actual active authority with read-only
commands. Do not edit production over SSH or retry without a fresh exact
authorization.

## Local command contracts

The workflows use these deployed commands:

```bash
php artisan career:review-search-entry-quality-batch \
  --expected-package=/private/path/package.json \
  --actor-admin-user-id=1 \
  --json

php artisan career:control-search-entry-quality-batch \
  --mode=preflight \
  --expected-package=/private/path/package.json \
  --active-release-sha=<exact-40-char-sha> \
  --active-release-name=<exact-release-name> \
  --operation-id=<exact-operation-id> \
  --rollback-identifier=<exact-rollback-id> \
  --actor-admin-user-id=1 \
  --expected-review-evidence-sha256=<exact-sha256> \
  --json
```

Direct manual production invocation is prohibited. The protected workflows
provide active-release checks, receipt binding, production environment
protection, exact phrases, concurrency, and sanitized artifacts.

Repository rule impact: Career content, publication, and discoverability
remain backend/CMS-authoritative. This repair adds only an explicit
search-entry eligibility operation ledger and controlled production gates. It
adds no frontend fallback, CMS content source, public route, Search Channel
executor, automatic publication, or automatic rollback.
