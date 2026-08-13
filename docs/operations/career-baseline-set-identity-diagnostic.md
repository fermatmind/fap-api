# Career baseline set identity diagnostic

This production control is a manual, protected, zero-write diagnostic for the
Career 1046 rollout partition. It binds the Task 2 generation pointer and the
failed baseline-repair preflight, then compares four independent authorities:

- pointer-bound published projection (raw bytes and strict loader);
- the frozen v1 manifest baseline partition;
- authentic signed rollout receipts;
- current latest index-state classifications.

The immutable receipt contains only bounded counts, SHA-256 set identities,
booleans, fixed classifications, and explicit zero-write guarantees. It never
contains slugs, production paths, artifact bytes, response bodies, topology, or
raw command output. A control calculation error or invalid projection fails the
workflow closed. A valid stale manifest or receipt partition is diagnostic
evidence only and does not authorize a database, pointer, content, publication,
discoverability, deployment, migration, cache, restart, or Search action.

Solo-owner efficiency impact: one diagnostic PR and no routine operator
interaction. This is the shortest safe path because a production write cannot
be scoped until the pointer, manifest, receipt, and DB set identities are
separated and proven by a zero-write receipt.
