# Career baseline 30 index-state authority repair

This protected production control repairs only the frozen 30-slug Career baseline required by the Career 1046 publication/index reconciliation gate.

The zero-write preflight binds the exact latest control plane, active release, Task 2 pointer authority, Task 3A delta snapshot, and the confirmed prewrite-only Task 3B failure. It reports only counts, SHA-256 identities, and fixed status codes.

Apply accepts one fresh successful preflight receipt and performs one append-only transaction. It may insert one exact `indexed` and eligible `index_states` row per receipt-bound baseline repair target. It cannot update or delete rows, write delta or outside-target states, or mutate CMS, cache, pointers, artifacts, publication, discoverability, migrations, deployments, services, sitemap, llms, or Search surfaces.

Readback is SELECT-only and binds the successful apply receipt. Transport ambiguity is terminal, and no phase permits automatic retry, rollback, cleanup, or fallback.

Solo-owner efficiency impact: one PR and zero operator interactions; the three receipt-bound phases are combined in one protected control because they share one immutable baseline repair boundary, while the workflow-only merge is ignored by automatic staging to avoid a production mutation race.
