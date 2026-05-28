# DETAIL_READY_1048_REPLACEMENT_AUTHORITY_IMPORT-01

## Summary

This PR prepares one repo-backed replacement authority import package for the
`detail_ready_1048` career rollout path.

The previous dry-run proved that the 1018 ready-not-public delta contains the
manual-hold slug `software-developers`. Excluding that slug leaves only 1017
delta members, so the rollout needs one additional qualified replacement before
the clean 1018 manifest can be regenerated.

## Replacement Candidate

- slug: `computer-occupations-all-other`
- observed occupation id: `019da904-3dbb-723b-ace7-532fb069b486`
- observed authority: O*NET-SOC 2019 `15-1299.00`
- current blocker: missing `us_soc` crosswalk and `career_job_public_display`
  display asset

The package proposes:

- one `occupation_crosswalks` `us_soc` row derived from the observed O*NET-SOC
  code
- one `career_job_display_assets` `display.surface.v1` / `v4.2` import payload
  with the required 24 component order and both `en` and `zh` page payloads

## Boundaries

This PR does not write production data, mutate CMS, publish career pages, deploy,
enqueue Search Channel, submit URLs, or expose the replacement in sitemap/llms.

The replacement remains import-package-only until a future explicitly approved
controlled import task writes it into the backend authority tables. After that
import, the 1048 authority scan must be rerun and the clean manifest must still
exclude `software-developers`.

## Expected Post-Import Shape

- current public detail cohort: 30
- existing detail-ready union: 1048
- replacement added after import: 1
- manual-hold slugs excluded: 1
- clean target union excluding manual hold: 1048
- clean ready-not-public delta: 1018
- expected locale rows for the clean delta: 2036

## Next Task

`DETAIL_READY_1048_DELTA_AUTHORITY_REPAIR-01` should rerun the slug-level
authority scan only after the replacement import is explicitly reviewed and
applied in a separate approved task.
