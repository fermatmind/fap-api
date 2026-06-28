# Big Five Share Safety Candidate Assets v0.2 Review

## Scope

This package contains 36 candidate `content_asset` drafts for the Share Safety section only. It does not generate final result payload, frontend copy, CMS, SEO, runtime wiring, or production content.

## Editorial changes from v0.1

- Rewrote away from generic template language and made each category serve a different user job.
- Separated public summary, collaboration excerpt, personal adjustment phrase, collaboration manual, safe quote, and surface boundary notice.
- Removed profile-label driven wording and avoided language that turns the report into a fixed identity.
- Kept share text useful when detached from the private report.
- Strengthened privacy boundary for PDF/share/history/compare without exposing internal implementation details.
- Enforced title, summary, quote, and boundary length ranges in the candidate QA scan.

## Category coverage

```json
{
  "public_summary_card": 6,
  "collaboration_friendly_excerpt": 6,
  "what_i_am_working_on": 6,
  "how_to_work_with_me": 6,
  "safe_quote_pool": 8,
  "surface_boundary_notice": 4
}
```

## Safety boundary

All public text avoids private identifiers, untreated score details, population ranking, clinical/high-stakes claims, relationship or outcome guarantees, and internal implementation terms. The forbidden-term scan is scoped to `public_payload` because structural fields necessarily include keys such as `target_registry_key`.

## QA summary

```json
{
  "asset_batch_version": "big5_share_safety_candidates_v0_2",
  "content_asset_count": 36,
  "category_counts": {
    "public_summary_card": 6,
    "collaboration_friendly_excerpt": 6,
    "what_i_am_working_on": 6,
    "how_to_work_with_me": 6,
    "safe_quote_pool": 8,
    "surface_boundary_notice": 4
  },
  "forbidden_hit_count": 0,
  "forbidden_hits": [],
  "forbidden_scan_scope": "public_payload_only",
  "runtime_use_all_staging_only": true,
  "production_use_allowed_true_count": 0,
  "ready_for_pilot_true_count": 0,
  "ready_for_runtime_true_count": 0,
  "ready_for_production_true_count": 0,
  "shareable_all_true": true,
  "reading_modes_all_share_safe": true,
  "duplicate_title_count": 0,
  "duplicate_body_count": 0,
  "public_field_length_issues": [],
  "length_stats": {
    "title_zh": {
      "min": 12,
      "max": 16,
      "expected_range": [
        12,
        28
      ]
    },
    "share_summary_zh": {
      "min": 24,
      "max": 33,
      "expected_range": [
        24,
        60
      ]
    },
    "safe_quote_zh": {
      "min": 24,
      "max": 33,
      "expected_range": [
        24,
        60
      ]
    },
    "boundary_zh": {
      "min": 35,
      "max": 57,
      "expected_range": [
        35,
        90
      ]
    }
  }
}
```

## Codex follow-up required

- schema validation
- selector contract validation
- slot/category mapping check
- body_quality metadata calculation
- forbidden-token scan over rendered public text
- result page / PDF / share / history / compare rendered hygiene scan
- human review manifest
- staging import only

## Import boundary

These assets remain candidate artifacts. Do not attach to runtime. Do not import to production. Do not write frontend fallback copy.
