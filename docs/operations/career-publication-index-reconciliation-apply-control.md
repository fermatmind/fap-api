# Career publication/index exact reconciliation apply control

## Status and boundary

This control is implemented but has not been dispatched. It is the separately controlled Task 3B write lane that follows the SELECT-only Task 3A preflight. Merging this control does not authorize production execution.

The workflow may restore publication/index authority only for the frozen 1016-slug signed-receipt delta. It does not publish content, build a projection, activate a generation, deploy code, migrate schema, warm cache, change CMS data, or release sitemap/llms/Search surfaces.

## Frozen authority

- manifest SHA-256: `b570ec0cdda65278aa543431886b3529d072de8d67a8e79f1cafbb1c4c8dfc0e`
- baseline 30 set SHA-256: `39cc766fb18c85d385b83f0ac1f56a8b97d46481d3e9a12de0588abbaf640060`
- receipt/delta 1016 set SHA-256: `09ec67befe967e1619a40578c47b862743883717b048da802ee7ef3551a0747f`
- target 1046 set SHA-256: `3b101fb76b5666200c73519c650beb1a5b0b35f47f7592453bf5671920571a18`
- empty set SHA-256: `01ba4719c80b6fe911b091a7c05124b64eeece964e09c058ef8f9805daca546b`

The workflow requires a successful Task 3A preflight on the exact current control-plane SHA. The preflight must be no more than six hours old. Its run id, attempt, receipt SHA-256, GitHub artifact digest, active release identity, and current DB latest-state hash are independent required inputs and must all agree with the downloaded immutable receipt.

## Exact write allowlist

The streamed runner opens one database transaction with one attempt. It locks and rereads the exact 1046 occupations and their `index_states`, then requires the Task 3A current-state hash to match before any insert.

The only permitted write is an append-only `index_states` insert for a receipt-covered delta slug whose latest state is not already the exact public state. No update or delete path exists.

- table: `index_states`
- state: `indexed`
- eligibility: `true`
- canonical path: `/career/jobs/{exact_receipt_slug}`
- canonical target: `null`
- required reasons: `canonical_rollout_batch_promotion`, `career_1046_exact_publication_index_reconciliation`, and the frozen receipt-set hash
- import run: `null`
- timestamps and row fingerprint: generated inside the one transaction

The code refuses missing or duplicate occupation identities, rows outside the target, receipt drift, latest-state timestamp ties, baseline absence or restriction, preflight DB-state drift, and any planned non-delta write.

## Atomic readback

Before commit, the same transaction locks and rereads the authority tables and requires:

- receipt-covered delta: 1016 with the frozen receipt set hash
- matching latest state: 1016 with the frozen receipt set hash
- missing or mismatching latest state: 0 with the empty set hash
- missing latest state: 0 with the empty set hash
- outside target: 0 with the empty set hash
- baseline preserved and matching: 30 with the frozen baseline set hash
- exact baseline latest-state row hash unchanged from the prewrite snapshot
- latest-state ties: 0

Any failed assertion rolls back the transaction. The workflow has no automatic retry. A transport failure or invalid remote receipt is recorded as an ambiguous outcome and must not be rerun automatically.

## Sanitized receipt

The immutable runner-side artifact contains only bounded identities, counts, canonical hashes, the exact allowlist, transaction/write booleans, and zero-action guarantees. It contains no slugs, row ids, release names, filesystem paths, topology, SQL, response bodies, or raw exceptions.

The GitHub artifact is evidence storage on the runner; `artifact_write_count: 0` refers to production application artifact/projection writes.

## Explicit prohibitions

This lane cannot:

- write CMS, cache, projection, ledger, sitemap, llms, or Search state;
- update or delete database rows;
- insert any baseline or outside-target row;
- accept free-form SQL or a user-provided slug list;
- deploy, restart, migrate, warm, repair, clean up, roll back, or retry automatically;
- dispatch itself from push, pull request, schedule, workflow chaining, or repository dispatch.

This PR does not run the workflow, use SSH, or access production/staging state.
