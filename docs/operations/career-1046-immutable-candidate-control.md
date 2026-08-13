# Career 1046 immutable candidate contract

`CAREER-1046-IMMUTABLE-CANDIDATE-07` adds an offline, manifest-filtered product candidate generator. It does not add a workflow, command, route, scheduler entry, database mutation, active-pointer write, publication action, cache warm, or discoverability action.

## Exact inputs

The generator accepts only:

- the repository frozen manifest whose raw SHA-256 is `ef4d43eeaa0300534b36fd77d7806bcbe065de1fb13f158ceda1517f259207c5`;
- an exact baseline-authority slug set of 30;
- an exact database-matching receipt slug set of 1016;
- one full-release ledger containing the exact target set;
- one runtime projection containing exactly two published locale rows per target slug;
- one non-empty EN and ZH detail payload per target slug.

The target slug set is the exact manifest union, not a database scan, latest directory, working revision, draft, regenerated package, or HTTP-success result.

## Fail-closed output contract

Successful generation proves all of the following in the generation manifest and candidate receipt:

- unique slugs: 1046;
- locale rows: 2092;
- published slugs: 1046;
- published locale rows: 2092;
- missing, duplicate, and outside-target counts: zero;
- target set SHA-256: `3b101fb76b5666200c73519c650beb1a5b0b35f47f7592453bf5671920571a18`;
- target locale-row set SHA-256: `c9878e76c817cc09448c32b1dcba3152b22821af34a31204840eb77a2d65857e`.

The generator rejects 1048/2096 output, `database-administrators-and-architects`, `software-developers`, any non-manifest slug, duplicate rows, an incomplete locale pair, or any receipt-covered slug absent from the exact DB-authority input.

It emits generation-native projection and ledger documents plus EN/ZH detail and directory documents. Every document is bound to one deterministic generation ID. Sitemap and llms flags remain closed, and Search submission remains disabled.

## Immutable materialization boundary

`materializeImmutable()` may create only a new local `candidates/<generation-id>` directory. It uses exclusive file creation, byte-hash readback, and a no-clobber final rename. It never creates or changes `active-generation.json`, publishes the candidate, warms a cache, or triggers a production or staging workflow. A repeated materialization of the same generation fails rather than overwriting bytes.

Task 5 owns any protected remote product-data staging control. Task 6 owns root pointer activation. Task 7B owns discoverability release. None of those actions are implemented or executed here.
