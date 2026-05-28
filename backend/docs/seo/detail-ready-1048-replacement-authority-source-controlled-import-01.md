# DETAIL_READY_1048_REPLACEMENT_AUTHORITY_SOURCE_CONTROLLED_IMPORT-01

## Executive Summary

This task adds a controlled import path for the source-repair package created by `DETAIL_READY_1048_REPLACEMENT_AUTHORITY_SOURCE_REPAIR-01`.

Final decision: `source_controlled_import_path_completed_ready_for_deploy_and_explicit_apply`.

The command validates and can import `digital-forensics-analysts` into the career authority source layer after explicit approval. It does not publish the occupation, does not promote the 1048 cohort, and does not expose sitemap, llms, footer, or Search Channel surfaces.

## Command

```bash
php artisan career:import-detail-ready-replacement-authority-source --json
```

The dry-run command validates the package without writes.

Future apply requires the exact confirmation phrase:

```bash
php artisan career:import-detail-ready-replacement-authority-source --apply --confirm=DETAIL_READY_1048_REPLACEMENT_AUTHORITY_SOURCE_CONTROLLED_IMPORT_APPROVED --json
```

## Controlled Writes

When explicitly confirmed, the command may write only:

- one `career_import_runs` ledger row
- one `occupation_families` row, if missing
- one `occupations` row, if missing
- two `occupation_crosswalks` rows
- one `career_job_display_assets` row

It must write zero:

- `index_states`
- runtime projection rows
- sitemap rows
- llms rows
- footer/nav entries
- Search Channel rows

## Safety Gates

The command fails closed if:

- confirmation phrase is missing during apply
- package task/type does not match the source-repair package
- target slug is not `digital-forensics-analysts`
- candidate is manual hold, blocked, or CN proxy
- target authority already has indexable `index_states` for the candidate
- display asset is not v4.2 / `display.surface.v1` / `ready_for_pilot`
- public payload contains forbidden runtime or release-gate keys

`software-developers` remains manual hold.

## What Was Not Done

- No controlled import was executed in production.
- No production deploy was performed.
- No runtime promotion was performed.
- No publish was performed.
- No Search Channel action or URL submission was performed.
- No fap-web change was made.

## Next Task

Deploy readiness is required before the command can exist in the target authority environment. After deployment, a separate exact apply confirmation is required before running the controlled import.

Recommended next task:

`DEPLOY-READINESS | Deploy replacement authority source controlled import path`
