# DETAIL_READY_1048_ROLLOUT_DRY_RUN-01

## Summary

This read-only dry-run PR prepares the `detail_ready_1048` rollout gate without
promoting any Career runtime rows.

The intended rollout shape is:

- current public detail cohort: 30 slugs
- ready-not-public delta: 1018 slugs
- target public detail total: 1048 slugs
- expected locale rows for `en,zh`: 2036

## Dry-Run Result

The repo already contains the read-only scanner, candidate-prep planner, rollout
manifest planner, and rollout gate planner for this path. The local authority
scan command was attempted, but the developer database was unavailable, so a
slug-level 1018 manifest was not generated in this PR.

The dry-run therefore stops at a blocker state:

- no explicit 1018 slug list was written
- no runtime candidate rows were prepared
- no rollout manifest was applied
- no database/runtime promotion occurred

## Required Blockers Before Apply

Before `DETAIL_READY_1048_ROLLOUT_APPLY-01`, a later approved task must provide
or regenerate the authority artifact proving:

- exactly 30 current public baseline slugs
- exactly 1018 ready-not-public delta slugs
- no overlap between baseline and delta
- no manual-hold slugs such as `software-developers`
- no CN proxy rows
- no review-needed, family-handoff, or blocked slugs
- rollback group equals the 1018 delta slug list
- `apply_allowed=false` remains true in the dry-run manifest

## Boundaries

This PR does not deploy, mutate CMS, run production migrations, enqueue Search
Channel, submit URLs, call external search APIs, or publish Career pages.

## Next Task

`DETAIL_READY_1048_ROLLOUT_APPLY-01` must not start until the user explicitly
approves DB/runtime promotion after reviewing a clean dry-run manifest.
