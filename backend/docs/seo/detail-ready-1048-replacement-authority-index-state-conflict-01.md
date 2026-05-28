# DETAIL_READY_1048_REPLACEMENT_AUTHORITY_INDEX_STATE_CONFLICT-01

## Executive Summary

`digital-forensics-analysts` is not a safe replacement slug for the DETAIL_READY_1048 rollout.

Read-only production inspection showed that the slug already has backend authority rows, crosswalks, a display asset, and index-state rows marked `index_eligible=true`, but it is absent from the runtime publish projection and still returns 404/noindex at the public detail surface. This is an index-state/runtime-projection conflict, not a missing source-authority gap.

No production write, CMS mutation, runtime promotion, deploy, sitemap exposure, llms exposure, footer exposure, Search Channel action, URL submission, or fap-web change was performed.

## Target Slug

- `digital-forensics-analysts`
- English title: Digital Forensics Analysts
- Chinese title: 数字取证分析师
- O*NET-SOC 2019: `15-1299.06`
- US SOC: `15-1299`

## Observed Production State

| Check | Result |
|---|---|
| Occupation row | Exists |
| Crosswalk | Exists: `onet_soc_2019=15-1299.06`, `us_soc=15-1299` |
| Display asset | Exists: `career_job_public_display`, `v4.2`, `ready_for_pilot` |
| Recommendation snapshots | 0 |
| Published CareerJob row | 0 |
| Index states | 2 rows, both `index_eligible=true` |
| Runtime publish projection item | Absent |
| Public dataset cache | 30 public items; target slug absent |
| Public detail API | 404 |
| Public frontend detail page | 404/noindex |

## Conflict Class

`index_state_runtime_projection_conflict`

The controlled import path correctly blocked the target because the import safety gate requires the replacement to be non-indexable before import. The target already has index-state rows that mark it index-eligible, while the runtime projection does not publish or expose it.

## Replacement Decision

`replacement_safe=false`

This slug must not be used as the 1048 replacement without separate reconciliation. Reusing it as a clean replacement would mix three different states:

- source authority exists;
- index-state history says promotion/indexed;
- runtime projection and public API say non-public.

## Recommended Path

Use a conservative path:

1. Do not apply the controlled import for `digital-forensics-analysts`.
2. Keep `software-developers` on manual hold.
3. Treat the current rollout target as 1047 unless a separate human-approved decision releases `software-developers` or a clean replacement is created.
4. If 1048 remains mandatory, run a separate reconciliation task for the conflicted slug or create a new replacement authority source that is confirmed non-indexable in the target authority environment before import.

## What Was Not Done

- No production write.
- No CMS mutation.
- No runtime promotion.
- No deploy.
- No sitemap, llms, or footer exposure.
- No Search Channel action.
- No URL submission.
- No external search API call.
- No fap-web change.
- No change to `software-developers` manual hold.
- No production state modification for `digital-forensics-analysts`.

## Final Decision

`replacement_slug_not_safe_recommend_1047_target`

