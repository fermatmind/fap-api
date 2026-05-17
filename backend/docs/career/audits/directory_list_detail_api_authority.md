# Career Directory List Detail API Authority

## Purpose

The Career jobs list must not keep a `directory_draft` occupation as a public directory stub when the backend runtime publish projection already proves that the canonical detail route is published, route-enabled, robots-indexable, and release-gate passing.

This authority closes the gap where detail API responses were viewable while `/api/v0.5/career/jobs` still counted the same slugs as `detail_page_unavailable`.

## Authority Order

For `directory_draft` occupations, list authority is evaluated in this order:

1. Display-asset-backed detail authority.
2. Runtime-published detail shell authority.
3. Public directory stub fallback.

Runtime-published detail shell authority requires an explicit runtime projection item for the slug with:

- `runtime_publish_state`, `runtime_state`, `projection_state`, or `state` equal to `published`.
- `detail_route_enabled=true`.
- `robots_indexable=true`.
- `release_gate_pass=true`.

Default visibility booleans are not enough. The projection item must exist so synthetic/default fixture behavior cannot accidentally promote draft rows.

## Dataset Summary

The full dataset authority uses the same runtime-published detail shell rule for `directory_draft` members.

Rows that pass are counted as:

- `release_cohort=public_detail_indexable`
- `public_index_state=indexable`
- `strong_index_decision=strong_index_ready`
- `publish_track=runtime_publish_projection`

Rows that do not pass remain:

- `release_cohort=directory_draft_pending_detail`
- `public_index_state=noindex`
- `strong_index_decision=directory_draft_detail_pending`
- `exclusion_reasons=["detail_page_unavailable"]`

## Non-Goals

- No DB mutation.
- No rollout or apply.
- No deploy.
- No content generation.
- No CN proxy policy change.
- No manual-hold publication.
- No fap-web change.
