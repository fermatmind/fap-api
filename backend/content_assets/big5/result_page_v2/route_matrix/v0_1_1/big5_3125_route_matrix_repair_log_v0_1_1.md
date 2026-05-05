# B5-CONTENT-6 v0.1.1 Repair Log

## Repair scope

Semantic route consistency only.

## Fixed P0 issue

Low-confidence nearest canonical routes previously allowed canonical axes such as “高敏感 × 中高开放 × 克制进入” to appear in user-visible route fields even when actual domain bands were low. v0.1.1 adds usage gating and regenerates visible route fields from actual `domain_bands`.

## Repair actions

| issue_id | severity | action | status |
|---|---|---|---|
| B5C6-SEMANTIC-001 | P0 | Added `canonical_profile_usage` with direct / nearest / generic routing modes. | closed |
| B5C6-SEMANTIC-002 | P0 | Added `profile_label_public_allowed` true / conditional / false. | closed |
| B5C6-SEMANTIC-003 | P0 | Regenerated user-visible route fields from actual domain bands for low and medium confidence rows. | closed |
| B5C6-SEMANTIC-004 | P0 | Prevented low confidence rows from leaking canonical axis or public profile label. | closed |
| B5C6-SEMANTIC-005 | P0 | Added semantic consistency QA metrics and CSV. | closed |

## Final status

- semantic_axis_band_mismatch_count: 0
- low_confidence_canonical_axis_leak_count: 0
- low_confidence_public_profile_label_count: 0
- ready_for_asset_review: true
- ready_for_pilot: false
- ready_for_runtime: false
- ready_for_production: false
