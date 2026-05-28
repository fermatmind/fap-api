# DETAIL_READY_1046_DELTA_AUTHORITY_REPAIR-01

## Executive Summary

This dry-run/report-only PR generates a safe `1046` public career detail rollout manifest from the existing authorized production read-only authority scan captured by `DETAIL_READY_1047_DELTA_AUTHORITY_REPAIR-01`.

The clean rollout target is:

- current public detail count: `30`
- clean delta count: `1016`
- target public total: `1046`

The manifest is marked safe for explicit apply preflight because the delta excludes all known unsafe replacement/hold slugs and does not perform any runtime promotion or discoverability exposure.

## Exclusions

- `software-developers`: excluded because it remains on manual hold.
- `digital-forensics-analysts`: excluded because it has an `index_state_runtime_projection_conflict`.
- `computer-occupations-all-other`: excluded as a replacement candidate because it was already indexable and is not a clean replacement.

## Authority Source

This task reuses the existing authorized production read-only scan artifact from `DETAIL_READY_1047_DELTA_AUTHORITY_REPAIR-01` instead of searching for another replacement. That prior scan established that excluding `software-developers` and `digital-forensics-analysts` leaves exactly `1016` clean delta slugs.

## Manifest State

- `backend/docs/seo/generated/detail-ready-1046-rollout-manifest.v1.json`
- status: `ready_for_explicit_apply_preflight`
- manifest_safe: `true`
- apply_allowed: `false`
- rollout_apply_allowed: `false`

## Guardrails

No production write, DB mutation, CMS mutation, runtime promotion, deploy, sitemap/llms/footer exposure, Search Channel action, URL submission, external search API call, fap-web change, replacement search, software-developers hold release, or digital-forensics repair was performed.

## Next Step

A future apply/preflight task must be explicitly approved before any runtime promotion or database write. The apply preflight must re-check authority/runtime state before promoting the `1016` delta.
