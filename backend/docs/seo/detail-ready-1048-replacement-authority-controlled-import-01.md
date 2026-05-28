# DETAIL_READY_1048_REPLACEMENT_AUTHORITY_CONTROLLED_IMPORT-01

## Summary

This PR adds a controlled backend import path for the reviewed
`DETAIL_READY_1048_REPLACEMENT_AUTHORITY_IMPORT-01` package.

It does not run the import against production, promote the 1048 runtime cohort,
publish career pages, expose sitemap/llms/footer URLs, deploy, or enqueue Search
Channel.

## Controlled Import Path

New command:

```bash
php artisan career:import-detail-ready-replacement-authority --json
```

Default mode is dry-run and writes no rows.

The write path requires:

```bash
php artisan career:import-detail-ready-replacement-authority \
  --apply \
  --confirm=DETAIL_READY_1048_REPLACEMENT_AUTHORITY_CONTROLLED_IMPORT_APPROVED \
  --json
```

When explicitly confirmed, the command writes only:

- one `career_import_runs` ledger row
- one `occupation_crosswalks` `us_soc` row for `computer-occupations-all-other`
- one `career_job_display_assets` `display.surface.v1` / `v4.2` row

It intentionally writes zero `index_states` rows and performs zero runtime
promotion actions.

## Guardrails

- target slug is fixed to `computer-occupations-all-other`
- `software-developers` remains manual hold
- target occupation must already exist
- target occupation must already have observed O*NET-SOC 2019 authority
  `15-1299.00`
- replacement `us_soc` source code must be `15-1299`
- display asset must have `display.surface.v1`, `v4.2`, 24 components, and both
  EN/ZH page payloads
- sitemap, llms, footer, Search Channel, and runtime public exposure are not
  changed

## Next Task

After an explicitly approved controlled import is actually executed in the
target authority environment, run `DETAIL_READY_1048_DELTA_AUTHORITY_REPAIR-01`
to regenerate the clean 1018 manifest while keeping `software-developers`
excluded.
