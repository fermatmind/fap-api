# DETAIL_READY_1047_DELTA_AUTHORITY_REPAIR-01

## Executive Summary

The clean 1047 rollout manifest could not be safely generated from the current production authority scan.

The read-only production scan returned 1018 ready-not-public slugs. Both protected slugs are present in that 1018 set:

- `software-developers`: manual hold
- `digital-forensics-analysts`: index-state/runtime-projection conflict

After excluding both required slugs, the clean delta is 1016, not 1017. With the current public baseline of 30, the safe target would be 1046, not 1047.

No apply, production write, DB mutation, CMS mutation, runtime promotion, deploy, sitemap/llms/footer exposure, Search Channel action, URL submission, external search API call, or fap-web change was performed.

## Scan Source

- Source: production read-only `career:audit-detail-ready-1048-candidates --json`
- Writes database: false
- Current public detail count: 30
- Ready-not-public count: 1018
- Union detail-ready count: 1048
- Manual-hold ready count: 1

## Required Exclusions

| Slug | Reason | Action |
|---|---|---|
| `software-developers` | Manual hold | Excluded |
| `digital-forensics-analysts` | `index_state_runtime_projection_conflict` | Excluded |

## Manifest Result

| Field | Value |
|---|---:|
| current public detail | 30 |
| requested clean delta | 1017 |
| actual clean delta | 1016 |
| requested target public total | 1047 |
| actual safe target total | 1046 |
| manifest safe | false |

## Blocker

`clean_delta_count_mismatch_after_required_exclusions`

A 1017 delta cannot be produced while excluding both required slugs from the current scan. Any 1017 manifest generated from this authority set would have to include either a manual-hold slug, a conflict slug, or a slug outside the current scanned authority set.

## Recommendation

Do not apply.

Choose one of these paths before any apply preflight:

1. Approve a 1046 target from the current clean 1016 delta.
2. Select one additional clean replacement slug that is non-indexable, non-manual-hold, non-blocked, non-review-needed, and not a runtime projection conflict.
3. Make a separate explicit decision on `software-developers` manual hold.

Do not use `digital-forensics-analysts` as the replacement until a separate reconciliation resolves its index-state/runtime-projection conflict.

## Final Decision

`blocked_1047_delta_still_contains_conflict_slug`

