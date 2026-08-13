# Career publication/index reconciliation preflight control

## Scope

`Career Publication Index Reconciliation Preflight Production Ops` is a protected, manual-dispatch-only, SELECT-only production evidence lane. It does not contain an apply mode.

The control exists to bind the exact difference between the authentic 1016-slug rollout receipt authority and the current latest `occupations` / `index_states` publication-index state before a separately reviewed reconciliation apply control can be designed or authorized.

This repository goal implements and merges the workflow only. It does not dispatch it, connect to production, or create production evidence.

## Frozen identities

- manifest SHA-256: `ef4d43eeaa0300534b36fd77d7806bcbe065de1fb13f158ceda1517f259207c5`
- baseline count / set SHA-256: `30` / `39cc766fb18c85d385b83f0ac1f56a8b97d46481d3e9a12de0588abbaf640060`
- delta and authentic receipt count / set SHA-256: `1016` / `09ec67befe967e1619a40578c47b862743883717b048da802ee7ef3551a0747f`
- target count / set SHA-256: `1046` / `3b101fb76b5666200c73519c650beb1a5b0b35f47f7592453bf5671920571a18`
- canonical empty-set SHA-256: `01ba4719c80b6fe911b091a7c05124b64eeece964e09c058ef8f9805daca546b`

The frozen production evidence observed zero matching and 1016 missing latest index states. That observation is a design baseline, not a future execution authorization. Any future run must bind the exact then-active application SHA/release and produce a new immutable receipt.

## Preflight contract

Eligibility requires:

- exact latest `main` control-plane SHA;
- an active release SHA reachable from that `main`;
- an exact active release name and exact operator phrase;
- the unchanged frozen manifest and current-state freeze contract;
- protected production environment and secrets-only routing;
- absent deploy lock and bounded deploy/migration/queue-reload/composer processes.

The streamed runner reads only:

- the verified rollout execution receipts through `CareerVerifiedRolloutBatchSlugAuthority`;
- `occupations.id` and `occupations.canonical_slug`;
- the allowlisted latest-state identity and semantic columns from `index_states`.

It emits no slug list, database identifier list, SQL, topology, raw exception, response body, or production path. It emits only fixed status values, counts, booleans, SHA identities, run identity, and the hashed release-name identity.

Every set has an explicit canonical hash, including receipt, baseline, target, covered delta, missing receipt, outside target, baseline overlap, matching DB state, missing/mismatching DB state, missing occupation, missing latest state, and latest-state tie sets. The complete current DB state is hashed from one deterministic semantic-and-row-identity record per delta slug.

Identical latest timestamps are treated as ambiguous and fail closed; the runner does not infer authority from row existence, mtime, a working revision, draft content, or generated packages.

## Zero-write boundary

The lane has no apply mode and performs no:

- insert, update, delete, transaction write, migration, deploy, restart, or queue action;
- CMS, cache, projection, ledger, artifact, pointer, or publication mutation;
- sitemap, llms, IndexNow, GSC, URL inspection, Search Channel, or URL-submission action;
- automatic retry, repair, cleanup, rollback, warm, or workflow dispatch.

The only write is the runner-side immutable sanitized GitHub Actions artifact. It is initialized before checkout and uploaded with `if: always()` on success or failure. A transport or receipt ambiguity cannot be reported as a successful or guaranteed zero-write preflight.

## Deferred

The exact transaction-bound reconciliation apply control is Task 3B and remains a separate PR. Candidate generation, product-data staging, root activation, public verification, discoverability release, monitoring, and data-debt audit are also deferred to their own train items.
